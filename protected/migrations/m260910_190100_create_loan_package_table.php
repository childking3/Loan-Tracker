<?php

use yii\db\Migration;

/**
 * Creates the loan_package table.
 *
 * Holds the client's fixed set of loan packages (loan amount, total
 * repayment, daily payment, repayment period). Values must match the
 * client's original Excel calculator exactly, since the client
 * acceptance-tests calculations against that spreadsheet. Rows are seeded in
 * a later data migration once the exact figures are confirmed, and remain
 * admin-editable afterward through a settings screen (a later phase).
 */
class m260910_190100_create_loan_package_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%loan_package}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(100)->notNull(),
            'loan_amount' => $this->decimal(12, 2)->notNull(),
            'total_repayment' => $this->decimal(12, 2)->notNull(),
            'daily_payment' => $this->decimal(12, 2)->notNull(),
            'repayment_period_days' => $this->smallInteger()->unsigned()->notNull(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function safeDown()
    {
        $this->dropTable('{{%loan_package}}');
    }
}
