<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

/**
 * Admin-only staff account management: list, create, edit,
 * deactivate/reactivate, delete. Gated on manageUsers (admin role only,
 * per m260910_200000_init_rbac).
 *
 * Removal is a deliberate two-step: actionDeactivate() sets status to
 * STATUS_INACTIVE, a temporary, fully reversible state actionActivate()
 * undoes. actionDelete() is the permanent second step - a soft delete
 * (User::softDelete()), never a real SQL DELETE, since a user row is
 * referenced by loan.assigned_staff_id, repayment.recorded_by_staff_id,
 * customer.created_by, and activity_log.user_id (all RESTRICT except
 * activity_log's own SET NULL - see m260910_190500_create_activity_log_table).
 * actionDelete() only accepts an already-STATUS_INACTIVE account - no
 * one-click path from active straight to gone.
 */
class UserController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageUsers'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'deactivate' => ['post'],
                    'activate' => ['post'],
                    'delete' => ['post'],
                    'create' => ['get', 'post'],
                    'update' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $users = User::find()->orderBy(['full_name' => SORT_ASC])->all();
        $roles = [];
        foreach ($users as $user) {
            $roles[$user->id] = $this->currentRole((int) $user->id);
        }

        return $this->render('index', ['users' => $users, 'roles' => $roles]);
    }

    public function actionCreate()
    {
        $model = new User();
        $model->scenario = User::SCENARIO_CREATE;
        $model->status = User::STATUS_ACTIVE;

        $model->avatarFile = UploadedFile::getInstanceByName('User[avatarFile]');

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->setPassword((string) $model->password);
            $model->generateAuthKey();
            $model->save(false);
            $this->assignRole((int) $model->id, $model->role);

            if ($model->avatarFile !== null) {
                $model->saveAvatar($model->avatarFile);
            }

            AuditLogger::audit('user_create', 'user', $model->id, null, [
                'username' => $model->username,
                'email' => $model->email,
                'full_name' => $model->full_name,
                'role' => $model->role,
                'status' => $model->status,
            ]);

            Yii::$app->session->setFlash('success', 'Staff account created.');
            return $this->redirect(['index']);
        }

        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $model->password = null;
        $model->role = $this->currentRole((int) $model->id);
        $oldAttributes = $model->getAttributes();
        $oldRole = $model->role;

        if (Yii::$app->request->isPost) {
            $model->avatarFile = UploadedFile::getInstanceByName('User[avatarFile]');
        }

        if ($model->load(Yii::$app->request->post())) {
            // An admin can't demote themselves out of admin or deactivate
            // their own account - either could leave no admin able to
            // reach this controller again (manageUsers is admin-only),
            // recoverable only via direct database access.
            //
            // validate() first, custom checks after: Model::validate()
            // clears existing errors by default, so adding these errors
            // before calling it would get them wiped, leaving $valid true
            // and the guard defeated.
            $isSelf = (int) $id === (int) Yii::$app->user->id;
            $valid = $model->validate();

            if ($isSelf && $model->role !== User::ROLE_ADMIN) {
                $model->addError('role', 'You cannot remove your own admin role.');
                $valid = false;
            }
            // (int) cast, not bare !== : $model->status here is a string
            // from $_POST, so a strict compare against STATUS_ACTIVE (int)
            // is always true, blocking every self-edit regardless of what
            // was submitted.
            if ($isSelf && (int) $model->status !== User::STATUS_ACTIVE) {
                $model->addError('status', 'You cannot deactivate your own account.');
                $valid = false;
            }

            if ($valid) {
                if ($model->password !== null && $model->password !== '') {
                    $model->setPassword($model->password);
                }
                $model->save(false);

                if ($model->role !== $oldRole) {
                    $this->assignRole((int) $model->id, $model->role);
                }

                if ($model->avatarFile !== null) {
                    $model->saveAvatar($model->avatarFile);
                }

                AuditLogger::audit(
                    'user_update',
                    'user',
                    $model->id,
                    $oldAttributes + ['role' => $oldRole],
                    $model->getAttributes() + ['role' => $model->role]
                );

                Yii::$app->session->setFlash('success', 'Staff account updated.');
                return $this->redirect(['index']);
            }
        }

        return $this->render('update', ['model' => $model]);
    }

    public function actionDeactivate($id)
    {
        if ((int) $id === (int) Yii::$app->user->id) {
            throw new ForbiddenHttpException('You cannot deactivate your own account.');
        }

        $model = $this->findModel($id);
        $oldStatus = $model->status;
        $model->status = User::STATUS_INACTIVE;
        $model->save(false);

        AuditLogger::audit('user_deactivate', 'user', $model->id, ['status' => $oldStatus], ['status' => $model->status]);
        Yii::$app->session->setFlash('success', 'Staff account deactivated.');
        return $this->redirect(['index']);
    }

    public function actionActivate($id)
    {
        $model = $this->findModel($id);
        $oldStatus = $model->status;
        $model->status = User::STATUS_ACTIVE;
        $model->save(false);

        AuditLogger::audit('user_activate', 'user', $model->id, ['status' => $oldStatus], ['status' => $model->status]);
        Yii::$app->session->setFlash('success', 'Staff account reactivated.');
        return $this->redirect(['index']);
    }

    /**
     * Permanent half of the two-step removal (see class docblock). Only
     * reachable from STATUS_INACTIVE, enforced server-side so a direct
     * POST can't skip deactivation. Self-delete is blocked explicitly
     * too, even though self-deactivation being blocked already prevents
     * it indirectly.
     */
    public function actionDelete($id)
    {
        if ((int) $id === (int) Yii::$app->user->id) {
            throw new ForbiddenHttpException('You cannot delete your own account.');
        }

        $model = $this->findModel($id);
        if ((int) $model->status !== User::STATUS_INACTIVE) {
            throw new ForbiddenHttpException('Deactivate this staff account before deleting it.');
        }

        $oldAttributes = $model->getAttributes();
        $model->softDelete();

        AuditLogger::audit('user_delete', 'user', $model->id, $oldAttributes, null);
        Yii::$app->session->setFlash('success', 'Staff account deleted.');
        return $this->redirect(['index']);
    }

    /**
     * Replaces whatever role the user holds with exactly one new one.
     * Safe unconditionally - revokeAll() on a user with no prior
     * assignment is a no-op. Same shape m260910_200000_init_rbac uses for
     * the seeded admin user.
     */
    private function assignRole(int $userId, string $role): void
    {
        $auth = Yii::$app->authManager;
        $auth->revokeAll($userId);
        $roleItem = $auth->getRole($role);
        if ($roleItem !== null) {
            $auth->assign($roleItem, $userId);
        }
    }

    /**
     * Each user has exactly one role assigned directly - the
     * staff/manager/admin hierarchy is expressed via addChild()
     * parent-child relationships (see m260910_200000_init_rbac), not
     * multiple role assignments - so getRolesByUser() returns at most one
     * entry.
     */
    private function currentRole(int $userId): string
    {
        $roles = Yii::$app->authManager->getRolesByUser($userId);
        return $roles !== [] ? array_key_first($roles) : User::ROLE_STAFF;
    }

    private function findModel($id): User
    {
        $model = User::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('User not found.');
        }

        return $model;
    }
}
