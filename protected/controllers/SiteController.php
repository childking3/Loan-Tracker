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

            // Logged even when the username doesn't exist or the account is
            // locked out - the point of an access log is to show every
            // attempt, not just ones against a real account (see the
            // AuditLogger docblock).
            //
            // The raw submitted value is only logged verbatim if it belongs
            // to a real, existing account. Originally (Phase 9) this
            // checked the value against USERNAME_PATTERN instead - closing
            // the case of someone fat-fingering their password into the
            // username field, since a password virtually never matches
            // that pattern (letters/digits/dots/underscores only). Found
            // during a later pentest pass, via a manual test submitting
            // "sk_live_51H8xYzABC123SECRET" (an API-key-shaped string) as
            // the username: it matched USERNAME_PATTERN - real secrets and
            // tokens are very often exactly this shape (alphanumeric plus
            // underscores) - and was stored in activity_log verbatim
            // regardless. A shape-based check can never fully close this:
            // any character-class a real username is allowed to use is
            // also a character-class a real secret could happen to use.
            // Checking against actual existing usernames instead closes it
            // completely - nothing that isn't one of this app's real
            // account names is ever logged verbatim, whatever it looks
            // like.
            //
            // Trade-off, deliberately accepted: an enumeration sweep
            // against usernames that don't exist here (root, admin, test,
            // ...) now shows up as a redacted placeholder too, not the
            // literal string guessed - only a hit against a real account
            // name is logged in full. Confirmed live: guessing the
            // nonexistent "root" is now redacted exactly like a secret
            // would be. The count/timing/length of failed attempts is
            // still fully visible either way (that's what the lockout
            // throttle and this log's other columns are for) - only the
            // literal guessed string, for guesses that don't land, is what
            // this trades away, in exchange for a guarantee that nothing
            // resembling a real secret can ever reach this log verbatim.
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
     * Yii::$app->user->logout() destroys the PHP session server-side by
     * default (not merely the client-side identity cookie), satisfying the
     * client brief's requirement that logout actually invalidate the
     * session rather than just forgetting the identity on the client.
     */
    public function actionLogout()
    {
        // Logged before logout(), not after: logout() destroys the session
        // and clears the identity, so Yii::$app->user->id (which
        // AuditLogger reads to attribute the row) would already be gone.
        AuditLogger::access('logout');
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Self-service "My account" page - view your own picture, upload a
     * new one. No self-service page of any kind existed before this
     * feature (UserController's create/update forms are admin-only, for
     * managing *other* staff), so this is a new, deliberately narrow
     * surface: just the avatar, not username/email/password self-editing,
     * since that's all this feature actually asked for.
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
            // No error handler ever forwarded here - this route is being
            // hit directly rather than reached via an actual exception,
            // confirmed live during a regression pass (it 500'd trying to
            // call getMessage() on null). Nothing to show; treat it the
            // same as any other route with no matching resource.
            throw new \yii\web\NotFoundHttpException('Page not found.');
        }

        // Found during the Phase 9 hardening pass, comparing this
        // hand-rolled action against yii\web\ErrorAction (the stock action
        // this project deliberately didn't use, to control the view
        // exactly): getMessage() was rendered unconditionally for every
        // exception. yii\web\HttpException/UserException messages
        // (Page not found., Customer not found., the CSRF failure message,
        // etc.) are written to be user-facing and are safe to show as-is.
        // Anything else is an unexpected bug - a DB error, a PHP TypeError,
        // an unguarded null - and its message can contain internal detail
        // (file paths, SQL fragments, class names) that has no business
        // reaching whoever triggered it, deliberately or not. YII_DEBUG is
        // false in this app (never defined, so Yii's own default), which
        // only controls the framework's own debug view - it does nothing
        // to gate this custom action, so the generic-message fallback
        // below has to do that job explicitly, exactly as
        // yii\web\ErrorAction::getExceptionMessage() does.
        $message = $exception instanceof \yii\base\UserException
            ? $exception->getMessage()
            : 'An internal server error occurred.';

        return $this->render('error', ['exception' => $exception, 'message' => $message]);
    }
}
