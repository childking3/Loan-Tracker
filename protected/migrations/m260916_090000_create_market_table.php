<?php

use yii\db\Migration;

/**
 * Creates the market table: one row per trading market/territory, with at
 * most one staff member assigned to it at a time (mirrors loan's single
 * assigned_staff_id, not a join table - a market isn't a team).
 *
 * assigned_staff_id is nullable with ON DELETE SET NULL, unlike every other
 * FK-to-user in this schema (which use RESTRICT): "unassigned" is a normal,
 * expected state for a market (a new market before staff is allocated, or a
 * staff member who leaves), not a data-integrity violation to guard
 * against. Deactivating (is_active) rather than deleting a market follows
 * the same pattern as loan_package/customer - a market referenced by
 * customer.market_id must not vanish out from under them.
 */
class m260916_090000_create_market_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%market}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(100)->notNull(),
            'area' => $this->string(255)->null(),
            'assigned_staff_id' => $this->integer()->unsigned()->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-market-assigned_staff_id', '{{%market}}', 'assigned_staff_id');

        $this->addForeignKey(
            'fk-market-assigned_staff_id',
            '{{%market}}',
            'assigned_staff_id',
            '{{%user}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-market-assigned_staff_id', '{{%market}}');
        $this->dropTable('{{%market}}');
    }
}
