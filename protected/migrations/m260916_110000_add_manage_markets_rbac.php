<?php

use yii\db\Migration;

/**
 * Creates the manageMarkets permission and assigns it to manager (and
 * therefore admin, which inherits manager) - not to m260910_200000_init_rbac,
 * which is never edited after the fact.
 *
 * Decision: manager-tier, same as manageLoans/viewReports/viewAuditLog -
 * market administration (who's assigned where) is an operational decision
 * alongside loan issuance, not routine staff work and not admin-exclusive
 * like manageUsers/manageSettings. A judgment call, confirmed with the
 * client; reversible with a single addChild()/removeChild() if it should
 * move to admin-only instead.
 */
class m260916_110000_add_manage_markets_rbac extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;

        $permission = $auth->createPermission('manageMarkets');
        $auth->add($permission);
        $auth->addChild($auth->getRole('manager'), $permission);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;

        $permission = $auth->getPermission('manageMarkets');
        if ($permission !== null) {
            $auth->remove($permission);
        }
    }
}
