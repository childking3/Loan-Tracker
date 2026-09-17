<?php

use yii\db\Migration;

/**
 * Adds market.deleted_at, giving Market the same soft-delete + trash/
 * restore treatment as Customer instead of staying on LoanPackage's
 * is_active-only pattern (see MARKETS_REDESIGN_REVIEW.md's now-resolved
 * item on this - client chose the richer, undoable option).
 *
 * Same nullable-unix-timestamp shape as customer.deleted_at/user.deleted_at,
 * reusing that established pattern. is_active is untouched and keeps its
 * existing meaning (a market temporarily out of rotation but still very
 * much present); deleted_at is the separate, further step of removing a
 * market from every normal listing entirely, mirroring Customer's own
 * deactivate-vs-delete distinction rather than overloading is_active with
 * a second meaning.
 */
class m260917_090000_add_deleted_at_to_market extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%market}}', 'deleted_at', $this->integer()->unsigned()->null()->after('is_active'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%market}}', 'deleted_at');
    }
}
