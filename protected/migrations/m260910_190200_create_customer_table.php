<?php

use yii\db\Migration;

/**
 * Creates the customer table.
 *
 * phone is indexed but not unique: duplicate detection is handled at the
 * application layer, not as a hard DB constraint, since two customers
 * sharing a phone is a warning case, not a data-integrity violation.
 *
 * deleted_at is a nullable soft-delete timestamp, so records can be hidden
 * without losing loan/repayment history, and to support deletion requests
 * without breaking referential integrity.
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
