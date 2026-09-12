<?php

use yii\db\Migration;

/**
 * Creates the guarantor table.
 *
 * A loan can have zero or more guarantors (hasMany): guarantor policy varies
 * by loan and this app has no stated requirement either way, so it's left
 * optional at the schema/model level rather than enforced (see CODEBASE.md's
 * open items).
 *
 * One row per loan, not a shared customer-like entity via a join table:
 * mirrors a paper application collecting guarantor details fresh each time,
 * even if the same person guarantees multiple loans.
 *
 * phone is indexed but not unique, matching customer.phone - a shared phone
 * across guarantors/loans is expected, not a data-integrity violation.
 *
 * ON DELETE RESTRICT on both FKs, matching loan/repayment: a guarantor row
 * must never disappear because its loan or the recording staff was removed.
 */
class m260911_154500_create_guarantor_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%guarantor}}', [
            'id' => $this->primaryKey()->unsigned(),
            'loan_id' => $this->integer()->unsigned()->notNull(),
            'full_name' => $this->string(255)->notNull(),
            'phone' => $this->string(20)->notNull(),
            'address' => $this->text()->null(),
            'relationship' => $this->string(100)->null(),
            'occupation' => $this->string(150)->null(),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-guarantor-loan_id', '{{%guarantor}}', 'loan_id');
        $this->createIndex('idx-guarantor-phone', '{{%guarantor}}', 'phone');
        $this->createIndex('idx-guarantor-created_by', '{{%guarantor}}', 'created_by');

        $this->addForeignKey(
            'fk-guarantor-loan_id',
            '{{%guarantor}}',
            'loan_id',
            '{{%loan}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-guarantor-created_by',
            '{{%guarantor}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-guarantor-created_by', '{{%guarantor}}');
        $this->dropForeignKey('fk-guarantor-loan_id', '{{%guarantor}}');
        $this->dropTable('{{%guarantor}}');
    }
}
