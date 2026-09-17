<?php

use yii\db\Migration;

/**
 * Extends guarantor to also hold a baseline guarantor captured at customer
 * registration (CustomerController::actionCreate()), alongside its existing
 * per-loan use (GuarantorController::actionCreate($loanId)) - one table,
 * not a near-duplicate second one, since every column (full_name/phone/
 * address, relationship/occupation already nullable) is shared, and it
 * keeps ActivityLog's existing 'guarantor' => 'full_name' mapping and
 * LogController's entity resolution working unchanged for both cases.
 *
 * loan_id becomes nullable (was notNull) alongside the new nullable
 * customer_id, with a CHECK enforcing exactly one is set - MariaDB 11.8
 * enforces CHECK constraints server-side, so this isn't just documentation.
 *
 * Both guarantor FKs are re-added as ON UPDATE RESTRICT here, deviating
 * from this schema's usual ON UPDATE CASCADE convention (every other FK in
 * the app uses it) - confirmed by hand against the live dev DB that MariaDB
 * 11.8 flat out refuses (error 1901) a CHECK constraint on any column that
 * is the child side of an ON UPDATE CASCADE FK, on any table, regardless of
 * the CHECK's own expression. Since every primary key in this schema is a
 * surrogate auto-increment id that's never actually UPDATEd, ON UPDATE
 * CASCADE was already a no-op safety net here, not exercised behavior - so
 * this loses nothing in practice while making the CHECK possible at all.
 *
 * safeDown()'s revert of loan_id to NOT NULL is only safe if no
 * customer-scoped guarantor rows exist yet (i.e. rolling back shortly after
 * rolling forward, before real data accumulates) - matches this project's
 * existing precedent of migrations that are cleanly reversible only in the
 * empty-data-so-far case.
 */
class m260916_100000_add_customer_id_to_guarantor extends Migration
{
    public function safeUp()
    {
        $this->alterColumn('{{%guarantor}}', 'loan_id', $this->integer()->unsigned()->null());
        $this->addColumn('{{%guarantor}}', 'customer_id', $this->integer()->unsigned()->null()->after('loan_id'));
        $this->createIndex('idx-guarantor-customer_id', '{{%guarantor}}', 'customer_id');

        // Re-add loan_id's FK as ON UPDATE RESTRICT (was CASCADE, set in
        // m260911_154500) - required for the CHECK below; see class docblock.
        $this->dropForeignKey('fk-guarantor-loan_id', '{{%guarantor}}');
        $this->addForeignKey(
            'fk-guarantor-loan_id',
            '{{%guarantor}}',
            'loan_id',
            '{{%loan}}',
            'id',
            'RESTRICT',
            'RESTRICT'
        );
        $this->addForeignKey(
            'fk-guarantor-customer_id',
            '{{%guarantor}}',
            'customer_id',
            '{{%customer}}',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->addCheck(
            'chk-guarantor-loan_xor_customer',
            '{{%guarantor}}',
            '(loan_id IS NOT NULL AND customer_id IS NULL) OR (loan_id IS NULL AND customer_id IS NOT NULL)'
        );
    }

    public function safeDown()
    {
        $this->dropCheck('chk-guarantor-loan_xor_customer', '{{%guarantor}}');
        $this->dropForeignKey('fk-guarantor-customer_id', '{{%guarantor}}');
        $this->dropColumn('{{%guarantor}}', 'customer_id');

        $this->dropForeignKey('fk-guarantor-loan_id', '{{%guarantor}}');
        $this->addForeignKey(
            'fk-guarantor-loan_id',
            '{{%guarantor}}',
            'loan_id',
            '{{%loan}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
        $this->alterColumn('{{%guarantor}}', 'loan_id', $this->integer()->unsigned()->notNull());
    }
}
