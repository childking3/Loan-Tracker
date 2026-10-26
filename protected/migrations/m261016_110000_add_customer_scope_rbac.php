<?php

use app\rbac\IsAssignedStaffCustomerRule;
use yii\db\Migration;

/**
 * Creates the customer-scoping permissions and their rule, mirroring the
 * loan-side pair created in m260910_200000_init_rbac (viewAllLoans /
 * viewAssignedLoans + IsAssignedStaffRule) - a separate migration, since
 * init_rbac is never edited after the fact.
 *
 *  - viewAssignedCustomers -> staff, carrying IsAssignedStaffCustomerRule,
 *    so a staff member resolves only their own customers (plus any customer
 *    behind a loan assigned to them - see the rule's docblock).
 *  - viewAllCustomers -> manager, inherited by admin, matching how
 *    viewAllLoans is assigned. Managers and admins keep the unrestricted
 *    view the client expects for oversight and for reassigning customers
 *    between staff.
 *
 * manageCustomers is deliberately left exactly as it is: it answers "may
 * this role work with customers at all", which every role still may. The
 * new pair answers the separate question "which customers", and the two
 * are kept apart so that neither check accidentally widens the other -
 * same separation the loan module already uses between manageLoans and
 * viewAllLoans/viewAssignedLoans.
 *
 * Changes no behaviour by itself: nothing calls these permissions until
 * the enforcement phase that follows.
 */
class m261016_110000_add_customer_scope_rbac extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;

        $rule = new IsAssignedStaffCustomerRule();
        $auth->add($rule);

        $viewAssigned = $auth->createPermission('viewAssignedCustomers');
        $viewAssigned->ruleName = $rule->name;
        $auth->add($viewAssigned);
        $auth->addChild($auth->getRole('staff'), $viewAssigned);

        $viewAll = $auth->createPermission('viewAllCustomers');
        $auth->add($viewAll);
        $auth->addChild($auth->getRole('manager'), $viewAll);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        foreach (['viewAssignedCustomers', 'viewAllCustomers'] as $name) {
            if ($permission = $auth->getPermission($name)) {
                $auth->remove($permission);
            }
        }

        if ($rule = $auth->getRule('isAssignedStaffCustomer')) {
            $auth->remove($rule);
        }
    }
}
