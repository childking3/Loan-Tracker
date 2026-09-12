<?php

use yii\db\Migration;

/**
 * Creates the customer table.
 *
 * phone is indexed but not unique-constrained: duplicate-phone detection is
 * handled at the application layer (Phase 3 customer controller), not
 * enforced as a hard database constraint, since two customers sharing a
 * phone number is a warning case rather than a data-integrity violation.
 *
 * deleted_at implements soft delete (a stored unix timestamp, null when not
 * deleted) so customer records can be withheld from normal views without
 * losing loan/repayment history tied to them, and to support NDPR deletion
 * requests without breaking referential integrity.
 */
class m260910_190200_create_customer_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%customer}}', [
            'id' => $this->primaryKey()->unsigned(),
            'full_name' => $this->string(255)->notNull(),
            'phone' => $this->string(20)->notNull(),
            'address' => $this->text()->null(),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
            'deleted_at' => $this->integer()->unsigned()->null(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-customer-phone', '{{%customer}}', 'phone');
        $this->createIndex('idx-customer-created_by', '{{%customer}}', 'created_by');

        $this->addForeignKey(
            'fk-customer-created_by',
            '{{%customer}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-customer-created_by', '{{%customer}}');
        $this->dropTable('{{%customer}}');
    }
}
