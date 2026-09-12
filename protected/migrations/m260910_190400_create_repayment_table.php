<?php

use yii\db\Migration;

/**
 * Creates the repayment table.
 *
 * Append-only ledger: rows are only ever inserted, never updated or deleted,
 * so there is no updated_at, only created_at.
 *
 * A loan's remaining balance is computed on read as
 * total_repayment - SUM(repayment.amount) and cached per loan; every insert
 * here must invalidate that cache entry.
 */
class m260910_190400_create_repayment_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%repayment}}', [
            'id' => $this->primaryKey()->unsigned(),
            'loan_id' => $this->integer()->unsigned()->notNull(),
            'amount' => $this->decimal(12, 2)->notNull(),
            'payment_date' => $this->date()->notNull(),
            'recorded_by_staff_id' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-repayment-loan_id', '{{%repayment}}', 'loan_id');
        $this->createIndex('idx-repayment-payment_date', '{{%repayment}}', 'payment_date');
        $this->createIndex('idx-repayment-recorded_by_staff_id', '{{%repayment}}', 'recorded_by_staff_id');

        $this->addForeignKey(
            'fk-repayment-loan_id',
            '{{%repayment}}',
            'loan_id',
            '{{%loan}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-repayment-recorded_by_staff_id',
            '{{%repayment}}',
            'recorded_by_staff_id',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-repayment-recorded_by_staff_id', '{{%repayment}}');
        $this->dropForeignKey('fk-repayment-loan_id', '{{%repayment}}');
        $this->dropTable('{{%repayment}}');
    }
}
