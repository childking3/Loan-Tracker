<?php

use yii\db\Migration;

/**
 * Creates the loan table.
 *
 * principal_amount, total_repayment and daily_payment are copied from the
 * selected loan_package at creation time rather than joined on read, so a
 * later admin edit to a package's figures does not retroactively change the
 * terms of loans already issued under the old figures.
 *
 * status is a native MySQL ENUM rather than a lookup table: the value set is
 * small, fixed, and not user-editable. overdue is set by a scheduled console
 * command (a later phase), not computed on every page load, so dashboard
 * queries can filter on this column directly and cheaply.
 */
class m260910_190300_create_loan_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%loan}}', [
            'id' => $this->primaryKey()->unsigned(),
            'loan_number' => $this->string(50)->notNull(),
            'customer_id' => $this->integer()->unsigned()->notNull(),
            'package_id' => $this->integer()->unsigned()->notNull(),
            'principal_amount' => $this->decimal(12, 2)->notNull(),
            'total_repayment' => $this->decimal(12, 2)->notNull(),
            'daily_payment' => $this->decimal(12, 2)->notNull(),
            'start_date' => $this->date()->notNull(),
            'expected_completion_date' => $this->date()->notNull(),
            'status' => "ENUM('active','completed','overdue','cancelled') NOT NULL DEFAULT 'active'",
            'assigned_staff_id' => $this->integer()->unsigned()->notNull(),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-loan-loan_number', '{{%loan}}', 'loan_number', true);
        $this->createIndex('idx-loan-customer_id', '{{%loan}}', 'customer_id');
        $this->createIndex('idx-loan-package_id', '{{%loan}}', 'package_id');
        $this->createIndex('idx-loan-assigned_staff_id', '{{%loan}}', 'assigned_staff_id');
        $this->createIndex('idx-loan-status', '{{%loan}}', 'status');

        $this->addForeignKey(
            'fk-loan-customer_id',
            '{{%loan}}',
            'customer_id',
            '{{%customer}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-loan-package_id',
            '{{%loan}}',
            'package_id',
            '{{%loan_package}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-loan-assigned_staff_id',
            '{{%loan}}',
            'assigned_staff_id',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-loan-created_by',
            '{{%loan}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-loan-created_by', '{{%loan}}');
        $this->dropForeignKey('fk-loan-assigned_staff_id', '{{%loan}}');
        $this->dropForeignKey('fk-loan-package_id', '{{%loan}}');
        $this->dropForeignKey('fk-loan-customer_id', '{{%loan}}');
        $this->dropTable('{{%loan}}');
    }
}
