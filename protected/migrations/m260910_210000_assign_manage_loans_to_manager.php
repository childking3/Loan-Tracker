<?php

use yii\db\Migration;

/**
 * Resolves the manageLoans gap left open in m260910_200000_init_rbac.
 *
 * Decision: loan issuance requires manager or admin, not staff - treated as
 * a credit-control decision, distinct from staff's routine repayment/customer
 * work, consistent with viewAllLoans/viewReports/viewAuditLog already being
 * manager-tier. A judgment call, not a brief requirement; reversible with a
 * single addChild() if the client wants staff to originate loans.
 */
class m260910_210000_assign_manage_loans_to_manager extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;
        $auth->addChild($auth->getRole('manager'), $auth->getPermission('manageLoans'));
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        $auth->removeChild($auth->getRole('manager'), $auth->getPermission('manageLoans'));
    }
}
