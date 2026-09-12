<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\LoginForm;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\UploadedFile;

class SiteController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['login'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['logout', 'profile'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['index', 'error'],
                        'allow' => true,
                    ],
                ],
            ],
            // Logout must be POST-only: a GET-able logout link is a CSRF
            // vector (an attacker page could force it via an <img> tag).
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'profile' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['dashboard/index']);
        }

        return $this->render('index');
    }

    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post())) {
            if ($model->login()) {
                AuditLogger::access('login_success');
                return $this->goBack();
            }

            // Logged even for a nonexistent username or locked account - an
            // access log should show every attempt, not just real ones
            // (see AuditLogger).
            //
            // The raw value is only logged verbatim if it matches a real,
            // existing account. A shape-based check (e.g. a username
            // regex) can't reliably tell a fat-fingered password from a
            // real secret/API key, since both use the same character
            // classes - checking against actual usernames closes that gap
            // completely, at the cost of also redacting guesses against
            // nonexistent usernames (root, admin, ...). Attempt
            // count/timing/length stays fully visible either way.
            $submittedUsername = (string) $model->username;
            $loggedUsername = User::findByUsername($submittedUsername) !== null
                ? $submittedUsername
                : '[redacted: not a real username, length ' . strlen($submittedUsername) . ']';
            AuditLogger::access('login_failed', 'user', null, ['username' => $loggedUsername]);
        }

        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    /**
     * logout() destroys the session server-side, not just the client
     * identity cookie - satisfies the brief's requirement that logout
     * actually invalidates the session.
     */
    public function actionLogout()
    {
        // Logged before logout(): afterward the session/identity is gone,
        // so AuditLogger would have no Yii::$app->user->id to attribute
        // the row to.
        AuditLogger::access('logout');
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Self-service "My account" page - view/upload your own avatar only.
     * Deliberately narrow: no username/email/password self-editing (those
     * stay admin-only via UserController), since avatar is all this
     * feature asked for.
     */
    public function actionProfile()
    {
        $model = User::findOne(Yii::$app->user->id);

        if (Yii::$app->request->isPost) {
            $model->avatarFile = UploadedFile::getInstanceByName('User[avatarFile]');
            if ($model->avatarFile !== null && $model->validate(['avatarFile'])) {
                $model->saveAvatar($model->avatarFile);
                AuditLogger::audit('user_avatar_update', 'user', $model->id, null, ['avatar_filename' => $model->avatar_filename]);
                Yii::$app->session->setFlash('success', 'Profile picture updated.');
                return $this->redirect(['site/profile']);
            }

            Yii::$app->session->setFlash('error', implode(' ', $model->getFirstErrors()) ?: 'Choose a picture to upload.');
        }

        return $this->render('profile', ['model' => $model]);
    }

    public function actionError()
    {
        $exception = Yii::$app->errorHandler->exception;
        if ($exception === null) {
            // No exception was forwarded here - route hit directly rather
            // than via the error handler. Nothing to show; treat as any
            // unmatched route.
            throw new \yii\web\NotFoundHttpException('Page not found.');
        }

        // UserException messages (Page not found., CSRF failure, etc.) are
        // written to be user-facing and safe to show as-is. Anything else
        // is an unexpected bug whose message can leak internal detail
        // (file paths, SQL, class names). YII_DEBUG only gates Yii's own
        // debug view, not this custom action, so the generic fallback
        // below has to do that job explicitly - same as
        // yii\web\ErrorAction::getExceptionMessage().
        $message = $exception instanceof \yii\base\UserException
            ? $exception->getMessage()
            : 'An internal server error occurred.';

        return $this->render('error', ['exception' => $exception, 'message' => $message]);
    }
}
