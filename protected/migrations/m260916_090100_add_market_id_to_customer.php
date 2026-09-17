<?php

use yii\db\Migration;

/**
 * Adds customer.market_id: nullable since every existing customer predates
 * Markets and a hard backfill isn't part of this change. RESTRICT (not SET
 * NULL, unlike market.assigned_staff_id) - a market with customers attached
 * has no delete action in this app anyway (deactivate only), so RESTRICT
 * mainly documents intent rather than being exercised in practice.
 */
class m260916_090100_add_market_id_to_customer extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%customer}}', 'market_id', $this->integer()->unsigned()->null()->after('address'));
        $this->createIndex('idx-customer-market_id', '{{%customer}}', 'market_id');
        $this->addForeignKey(
            'fk-customer-market_id',
            '{{%customer}}',
            'market_id',
            '{{%market}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-customer-market_id', '{{%customer}}');
        $this->dropColumn('{{%customer}}', 'market_id');
    }
}
