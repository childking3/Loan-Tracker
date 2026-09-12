<?php

use yii\db\Migration;

/**
 * Creates the guarantor table.
 *
 * A loan can have zero or more guarantors (hasMany, not a single fixed
 * slot) - some small lending shops require two, some require none for a
 * small enough loan, and this app has no stated policy either way. Making
 * whether a guarantor is required at all a hard validation rule would be
 * a business-policy decision this migration has no basis to make; left
 * fully optional at the schema/model level, same as this app already
 * leaves repayment amount ceilings and guarantor requirements
 * undecided elsewhere (see CODEBASE.md's open items).
 *
 * Deliberately one row per loan, not a customer-like standalone entity
 * reused across loans via a join table: this mirrors how a paper loan
 * application collects guarantor details fresh each time, even if the
 * same real person guarantees more than one loan for the same
 * lender - two separate rows, not one shared record. Simpler than a
 * many-to-many design, and matches how this app already treats
 * per-loan data it doesn't need to deduplicate.
 *
 * phone is indexed but not unique, matching customer.phone's own
 * reasoning (m260910_190200_create_customer_table) - two guarantors, or
 * the same guarantor across two loans, sharing a phone number is
 * expected, not a data-integrity violation.
 *
 * ON DELETE RESTRICT on both foreign keys, matching every other
 * financial-record table in this app (loan, repayment) - a guarantor
 * row must never silently disappear because its loan or the staff who
 * recorded it was removed; nothing in this app hard-deletes loans or
 * users today, but the constraint exists so that stays true even if a
 * future change tries to.
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
