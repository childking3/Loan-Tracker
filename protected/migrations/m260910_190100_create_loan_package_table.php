<?php

use yii\db\Migration;

/**
 * loan_package: the client's fixed loan packages (amount, total repayment,
 * daily payment, period). Figures must match the client's Excel calculator
 * exactly, since acceptance testing checks calculations against it. Seeded
 * separately once real figures are confirmed; admin-editable afterward.
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
