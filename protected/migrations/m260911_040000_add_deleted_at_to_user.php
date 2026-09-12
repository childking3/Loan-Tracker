<?php

use yii\db\Migration;

/**
 * Adds deleted_at to the user table, at the user's explicit request for a
 * safety mechanism on staff removal: deactivation (status -> INACTIVE)
 * already existed as a reversible, temporary state, but there was no way
 * to actually remove an account beyond that - and a real SQL DELETE isn't
 * an option regardless, since user rows are referenced by
 * loan.assigned_staff_id, repayment.recorded_by_staff_id, and
 * customer.created_by, all with ON DELETE RESTRICT (see their respective
 * migrations) - any staff account with real history would make the
 * database itself refuse the delete.
 *
 * Same column, same semantics, same nullable-unix-timestamp shape as
 * customer.deleted_at (m260910_190200_create_customer_table) - deliberately
 * reusing that exact established pattern rather than inventing a new one.
 *
 * Deliberately one-directional once set, matching Customer's own
 * soft-delete (which has no restore action anywhere in the app either):
 * the two-step design is deactivate (temporary, reversible via
 * UserController::actionActivate) then, separately, delete (permanent,
 * hides the account everywhere via User::find()'s override) - not a
 * three-state undo chain. Restoring a deleted user, if ever needed, is
 * always still possible directly against the database (this column is
 * nullable specifically so that stays true), just not exposed as an app
 * feature.
 */
class m260911_040000_add_deleted_at_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'deleted_at', $this->integer()->unsigned()->null()->after('status'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'deleted_at');
    }
}
