<?php

use yii\db\Migration;

/**
 * Resolves the manageLoans gap left open in m260910_200000_init_rbac: the
 * client brief's Roles section never specified who may create loans.
 *
 * Decision made now, as Phase 4 (loan creation) is actually built: loan
 * issuance requires manager or admin, not staff. This mirrors how
 * viewAllLoans, viewReports and viewAuditLog are already manager-tier
 * oversight permissions rather than staff-tier ones - initiating a new
 * loan is treated as a credit-control decision, distinct from the routine
 * day-to-day account handling (repayments, customer records) staff already
 * do. This is a judgment call, not a requirement stated in the brief; it is
 * a single addChild() away from being reversed if the client wants staff to
 * originate loans themselves.
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
