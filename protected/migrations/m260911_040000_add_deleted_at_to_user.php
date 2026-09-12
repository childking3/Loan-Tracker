<?php

use yii\db\Migration;

/**
 * Adds deleted_at to user: a permanent removal path beyond deactivation
 * (status -> INACTIVE, which is reversible). A real SQL DELETE isn't
 * possible regardless - user rows are referenced by loan.assigned_staff_id,
 * repayment.recorded_by_staff_id and customer.created_by, all ON DELETE
 * RESTRICT, so any account with real history would be refused by the DB.
 *
 * Same nullable-unix-timestamp shape as customer.deleted_at, reusing that
 * established pattern.
 *
 * One-directional once set, like Customer's soft delete: deactivate
 * (temporary, reversible via UserController::actionActivate) then delete
 * (permanent, hidden everywhere via User::find()'s override) - not a
 * three-state undo chain. Nullable so a restore is still possible directly
 * against the database, just not exposed as an app feature.
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
