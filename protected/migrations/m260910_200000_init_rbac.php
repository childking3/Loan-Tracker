<?php

use app\rbac\IsAssignedStaffRule;
use yii\db\Migration;

/**
 * Creates RBAC permissions, roles and hierarchy, and assigns admin to the
 * user seeded in m260910_190700_seed_admin_user.
 *
 * Roles are hierarchical: staff < manager < admin, each inheriting the role
 * below, per the client brief.
 *
 * Two deliberate gaps, left open rather than guessed at:
 *  - viewDashboard is granted to staff (and inherited upward) as baseline
 *    functionality; the brief never explicitly assigned it to a role.
 *  - manageLoans is created but not assigned to any role - who may create
 *    loans isn't specified in the brief, deferred until the loan module
 *    exists and it can be decided against real controller actions.
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

    /**
     * Looked up by username: auto_increment is not guaranteed to hand the
     * seeded admin id 1 (on a Galera cluster with auto_increment_increment=2
     * it can be 2), and a hard-coded id would leave the admin role-less.
     */
    private function seededAdminUserId(): int
    {
        return (int) (new \yii\db\Query())->select('id')->from('{{%user}}')->where(['username' => 'admin'])->scalar();
    }

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

        $auth->assign($admin, $this->seededAdminUserId());
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        $auth->revokeAll($this->seededAdminUserId());

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
