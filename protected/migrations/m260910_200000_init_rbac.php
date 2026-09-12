<?php

use app\rbac\IsAssignedStaffRule;
use yii\db\Migration;

/**
 * Creates the application's RBAC permissions, roles, and hierarchy, and
 * assigns the admin role to the admin user seeded in
 * m260910_190700_seed_admin_user.
 *
 * Roles are hierarchical: staff < manager < admin, each inheriting every
 * permission of the role below it, matching the client brief's role
 * structure.
 *
 * Two deliberate gaps, left open rather than guessed at:
 *  - viewDashboard is granted to staff (and therefore inherited by manager
 *    and admin) as baseline functionality every authenticated user needs;
 *    the original brief did not explicitly assign it to any role.
 *  - manageLoans is created as a permission but not assigned to any role.
 *    Who is allowed to create/edit loans (staff, manager-only, or both) is
 *    not specified in the brief and is deferred to Phase 4, when the loan
 *    module is actually built and this can be decided with real controller
 *    actions in view rather than guessed at here.
 */
class m260910_200000_init_rbac extends Migration
{
    private const PERMISSIONS = [
        'manageCustomers',
        'manageLoans',
        'manageRepayments',
        'viewAllLoans',
        'viewAssignedLoans',
        'viewDashboard',
        'viewReports',
        'manageUsers',
        'manageSettings',
        'viewAuditLog',
        'viewAccessLogs',
    ];

    private const SEEDED_ADMIN_USER_ID = 1;

    public function safeUp()
    {
        $auth = Yii::$app->authManager;

        $rule = new IsAssignedStaffRule();
        $auth->add($rule);

        $permissions = [];
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->createPermission($name);
            if ($name === 'viewAssignedLoans') {
                $permission->ruleName = $rule->name;
            }
            $auth->add($permission);
            $permissions[$name] = $permission;
        }

        $staff = $auth->createRole('staff');
        $auth->add($staff);
        $auth->addChild($staff, $permissions['viewAssignedLoans']);
        $auth->addChild($staff, $permissions['manageRepayments']);
        $auth->addChild($staff, $permissions['manageCustomers']);
        $auth->addChild($staff, $permissions['viewDashboard']);

        $manager = $auth->createRole('manager');
        $auth->add($manager);
        $auth->addChild($manager, $staff);
        $auth->addChild($manager, $permissions['viewAllLoans']);
        $auth->addChild($manager, $permissions['viewReports']);
        $auth->addChild($manager, $permissions['viewAuditLog']);

        $admin = $auth->createRole('admin');
        $auth->add($admin);
        $auth->addChild($admin, $manager);
        $auth->addChild($admin, $permissions['manageUsers']);
        $auth->addChild($admin, $permissions['manageSettings']);
        $auth->addChild($admin, $permissions['viewAccessLogs']);

        $auth->assign($admin, self::SEEDED_ADMIN_USER_ID);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        $auth->revokeAll(self::SEEDED_ADMIN_USER_ID);

        foreach (['admin', 'manager', 'staff'] as $roleName) {
            if ($role = $auth->getRole($roleName)) {
                $auth->remove($role);
            }
        }

        foreach (self::PERMISSIONS as $name) {
            if ($permission = $auth->getPermission($name)) {
                $auth->remove($permission);
            }
        }

        if ($rule = $auth->getRule('isAssignedStaff')) {
            $auth->remove($rule);
        }
    }
}
