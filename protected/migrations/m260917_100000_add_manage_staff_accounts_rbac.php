<?php

use yii\db\Migration;

/**
 * Creates the manageStaffAccounts permission and assigns it to manager
 * (and therefore admin, which inherits manager) - not to
 * m260910_200000_init_rbac, which is never edited after the fact.
 *
 * Decision (2026-09-17, client): managers get scoped access to the staff
 * list - UserController::behaviors() now allows either manageUsers
 * (admin, unrestricted) or this permission. Holding only
 * manageStaffAccounts is enforced, in the controller itself, to mean:
 * see every account in the list, but only create/edit/deactivate/
 * reactivate/delete staff-role accounts, and never set a role above
 * staff (manager/admin accounts are fully off limits, not just hidden in
 * the UI - see UserController::canManageAllRoles()/guardScopedTarget()).
 * Admin already has full access via manageUsers regardless of also
 * inheriting this permission through the role hierarchy - harmless,
 * since the controller checks manageUsers first for the unrestricted
 * path.
 */
class m260917_100000_add_manage_staff_accounts_rbac extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;

        $permission = $auth->createPermission('manageStaffAccounts');
        $auth->add($permission);
        $auth->addChild($auth->getRole('manager'), $permission);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        $permission = $auth->getPermission('manageStaffAccounts');
        if ($permission !== null) {
            $auth->remove($permission);
        }
    }
}
