<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\models\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\UploadedFile;

/**
 * Staff account management: list, create, edit, deactivate/reactivate,
 * delete. Gated on manageUsers (admin, unrestricted) or manageStaffAccounts
 * (manager, scoped - per m260917_100000_add_manage_staff_accounts_rbac and
 * the 2026-09-17 client decision) - see canManageAllRoles()/
 * guardScopedTarget() for exactly what "scoped" means and where it's
 * enforced.
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
                        'roles' => ['manageUsers', 'manageStaffAccounts'],
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
        $dataProvider = new ActiveDataProvider([
            'query' => User::find()->orderBy(['full_name' => SORT_ASC]),
            'pagination' => ['pageSize' => 20],
        ]);

        $users = $dataProvider->getModels();
        $userIds = array_map(static fn (User $user) => (int) $user->id, $users);
        $roles = $this->currentRoles($userIds);
        $marketNames = $this->assignedMarketNames($userIds);

        return $this->render('index', ['dataProvider' => $dataProvider, 'roles' => $roles, 'marketNames' => $marketNames]);
    }

    public function actionCreate()
    {
        $model = new User();
        $model->scenario = User::SCENARIO_CREATE;
        $model->status = User::STATUS_ACTIVE;

        $model->avatarFile = UploadedFile::getInstanceByName('User[avatarFile]');

        $loaded = $model->load(Yii::$app->request->post());
        if ($loaded && !$this->canManageAllRoles()) {
            // Enforced here, not just left to the create form hiding the
            // dropdown - a direct POST could otherwise submit role=admin.
            // See canManageAllRoles()'s own docblock.
            $model->role = User::ROLE_STAFF;
        }

        if ($loaded && $model->validate()) {
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

        return $this->render('create', ['model' => $model, 'canManageAllRoles' => $this->canManageAllRoles()]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $model->password = null;
        $model->role = $this->currentRole((int) $model->id);

        // A scoped manager (see canManageAllRoles()) never reaches a
        // manager/admin account's edit page at all, GET or POST - not
        // just hidden from the index list's action buttons.
        $this->guardScopedTarget($model->role);

        $oldAttributes = $model->getAttributes();
        $oldRole = $model->role;

        if (Yii::$app->request->isPost) {
            $model->avatarFile = UploadedFile::getInstanceByName('User[avatarFile]');
        }

        if ($model->load(Yii::$app->request->post())) {
            if (!$this->canManageAllRoles()) {
                // Locked to staff - same reasoning as actionCreate()'s
                // matching comment. The target was already confirmed
                // staff-role above, so this only blocks escalation.
                $model->role = User::ROLE_STAFF;
            }

            // An admin can't demote themselves out of admin or deactivate
            // their own account - either could leave no admin able to
            // reach every action here unrestricted (only manageUsers
            // clears guardScopedTarget()), recoverable only via direct
            // database access.
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

        return $this->render('update', ['model' => $model, 'canManageAllRoles' => $this->canManageAllRoles()]);
    }

    public function actionDeactivate($id)
    {
        if ((int) $id === (int) Yii::$app->user->id) {
            throw new ForbiddenHttpException('You cannot deactivate your own account.');
        }

        $model = $this->findModel($id);
        $this->guardScopedTarget($this->currentRole((int) $model->id));
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
        $this->guardScopedTarget($this->currentRole((int) $model->id));
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
        $this->guardScopedTarget($this->currentRole((int) $model->id));
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
     * True for admin (holds manageUsers): unrestricted access to every
     * account and role. False for a manager who only holds
     * manageStaffAccounts: scoped to staff-role accounts, per the
     * 2026-09-17 client decision (see m260917_100000_add_manage_staff_
     * accounts_rbac's docblock) - such a manager can see every account in
     * actionIndex()'s list, but guardScopedTarget() blocks them from
     * reaching create/update/deactivate/activate/delete for anyone above
     * staff, and actionCreate()/actionUpdate() force role back to staff
     * regardless of what's posted.
     */
    private function canManageAllRoles(): bool
    {
        return Yii::$app->user->can('manageUsers');
    }

    /**
     * Enforced server-side, not just hidden in the view - a direct POST
     * to deactivate/activate/delete, or a direct GET to update, could
     * otherwise reach a manager/admin account despite the UI never
     * offering a link to it.
     */
    private function guardScopedTarget(string $targetRole): void
    {
        if (!$this->canManageAllRoles() && $targetRole !== User::ROLE_STAFF) {
            throw new ForbiddenHttpException('You can only manage staff-role accounts.');
        }
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
     * entry. Used by actionUpdate() for a single user.
     */
    private function currentRole(int $userId): string
    {
        $roles = Yii::$app->authManager->getRolesByUser($userId);
        return $roles !== [] ? array_key_first($roles) : User::ROLE_STAFF;
    }

    /**
     * Batch equivalent of currentRole() for actionIndex()'s listing - one
     * query against auth_assignment for every user on the page instead of
     * one getRolesByUser() call per row, which scales linearly with
     * headcount.
     *
     * @param int[] $userIds
     * @return array<int, string> role keyed by user id
     */
    private function currentRoles(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $assignments = Yii::$app->db->createCommand(
            'SELECT user_id, item_name FROM ' . Yii::$app->authManager->assignmentTable . ' WHERE user_id IN (' . implode(',', $userIds) . ')'
        )->queryAll();

        $roles = array_fill_keys($userIds, User::ROLE_STAFF);
        foreach ($assignments as $assignment) {
            $roles[(int) $assignment['user_id']] = $assignment['item_name'];
        }

        return $roles;
    }

    /**
     * One market's name per user id that's assigned to a market - batched
     * for the listing the same way currentRoles() is, rather than one
     * query per row. A staff id could in theory be assigned to more than
     * one active market (nothing in the schema prevents it - see
     * MARKETS_REDESIGN_REVIEW.md's open item on this); this picks
     * whichever row the query returns first for that id, same defined-but-
     * arbitrary tie-break the customer-create market default uses.
     *
     * @param int[] $userIds
     * @return array<int, string> market name keyed by user id
     */
    private function assignedMarketNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = Yii::$app->db->createCommand(
            'SELECT assigned_staff_id, name FROM {{%market}} WHERE assigned_staff_id IN (' . implode(',', $userIds) . ')'
        )->queryAll();

        $names = [];
        foreach ($rows as $row) {
            $userId = (int) $row['assigned_staff_id'];
            if (!isset($names[$userId])) {
                $names[$userId] = $row['name'];
            }
        }

        return $names;
    }

    private function findModel($id): User
    {
        return ModelFinder::findOrFail(User::class, $id, 'User');
    }
}
