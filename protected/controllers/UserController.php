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
 * deactivate/reactivate, and delete. Gated on manageUsers, which - per
 * m260910_200000_init_rbac - only the admin role holds; this permission
 * existed since Phase 2 with nothing behind it until this Phase 9 pass, at
 * the user's explicit request to add it alongside the rest of Phase 9's
 * hardening work.
 *
 * Removing a staff member is a deliberate two-step, at the user's explicit
 * request for a safety mechanism: actionDeactivate() puts the account into
 * a temporary, fully reversible state (status -> STATUS_INACTIVE - the
 * account still exists, still shows in the staff list, and
 * actionActivate() undoes it completely). actionDelete() is the second,
 * permanent step out of that same temporary state - a soft delete
 * (User::softDelete(), setting deleted_at), never a real SQL DELETE:
 * a user row is referenced by loan.assigned_staff_id,
 * repayment.recorded_by_staff_id, customer.created_by, and
 * activity_log.user_id (all RESTRICT except activity_log's own SET NULL -
 * see m260910_190500_create_activity_log_table), so the database itself
 * would refuse a real delete on any account with real history regardless.
 * actionDelete() only accepts an already-STATUS_INACTIVE account - an
 * active staff member must be deactivated first, which is itself part of
 * the safety mechanism: there is no one-click path from "active" straight
 * to "gone".
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
            // An admin editing their own account can't demote themselves
            // out of the admin role or deactivate their own account - both
            // would either lock them out immediately or, if they were the
            // only admin, leave nobody able to reach this controller at
            // all again (manageUsers is admin-only, so revoking admin from
            // the last admin account would be irreversible without direct
            // database access). This is a plain guard against a
            // foreseeable operator mistake, not a response to anything
            // found during testing.
            // validate() first, custom checks after: Model::validate() clears
            // any existing errors before it runs (its $clearErrors
            // parameter defaults to true), so adding these errors before
            // calling validate() would have them silently wiped out by that
            // same call, leaving $valid true and the guard defeated. Caught
            // by re-reading this method after writing it, not by testing -
            // worth testing directly regardless before trusting it (see the
            // Phase 9 review doc).
            $isSelf = (int) $id === (int) Yii::$app->user->id;
            $valid = $model->validate();

            if ($isSelf && $model->role !== User::ROLE_ADMIN) {
                $model->addError('role', 'You cannot remove your own admin role.');
                $valid = false;
            }
            // (int) cast, not a bare !== : $model->status at this point is
            // whatever load() assigned from $_POST, i.e. the string "10",
            // not the int 10 - a strict comparison against
            // User::STATUS_ACTIVE (an int) is therefore always true
            // regardless of what was actually submitted. Caught live: this
            // blocked the admin from saving ANY self-edit at all, including
            // ones that never touched status, since the guard fired every
            // single time.
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
     * The permanent half of the two-step removal safety mechanism - see
     * this controller's own docblock and User::softDelete() for the full
     * reasoning. Only reachable from the temporary STATUS_INACTIVE state,
     * enforced server-side (not just hidden in the UI) so a direct POST
     * can't skip the deactivate step; self-delete is blocked the same way
     * self-deactivation already is, even though in practice an admin can
     * never reach this action against their own account anyway (they
     * cannot deactivate themselves in the first place) - kept explicit
     * rather than relying on that indirect guarantee.
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
     * Replaces whatever role the user currently holds with exactly one new
     * one. Safe to call unconditionally (revokeAll() on a user with no
     * prior assignment is a no-op) - matches the same
     * revoke-then-assign-a-single-role shape m260910_200000_init_rbac
     * itself uses for the seeded admin user, rather than assuming a user
     * can only ever gain roles, never change between them.
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
     * Each user has exactly one role assigned directly (the three-tier
     * staff/manager/admin hierarchy is expressed via addChild() parent-child
     * relationships in the RBAC schema, not by assigning multiple roles to
     * the same user - see m260910_200000_init_rbac), so
     * getRolesByUser() always returns at most one entry here.
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
