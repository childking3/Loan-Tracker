<?php

use yii\db\Migration;

/**
 * Adds customer.assigned_staff_id: the staff member a customer belongs to,
 * which every per-staff visibility rule is scoped by from here on.
 *
 * Why a new column rather than reusing created_by: created_by records who
 * typed the record in, not whose customer it is. Measured against the real
 * data before writing this - all six live customers carry created_by = 1
 * (admin), because the admin has registered every one of them, so scoping
 * by created_by would hand admin every customer and the staff none. The
 * market assignment, by contrast, already splits them correctly (Oshodi ->
 * staff 3, Ariaria -> staff 4, three customers each), which is why it is
 * the backfill source below. created_by keeps its existing audit meaning
 * and is deliberately left untouched.
 *
 * Same split HumHub uses for content ownership: Content.created_by stays
 * the author/audit field while visibility is scoped by the container
 * (contentcontainer_id) - see CODEBASE.md's "Borrowed from HumHub".
 *
 * Nullable, and SET NULL on delete, mirroring market.assigned_staff_id: an
 * unassigned customer is a real state (newly imported, or a staff account
 * removed) that the admin resolves by reassigning, not a condition that
 * should block the delete or leave a dangling id. Nothing reads this
 * column yet - enforcement lands in the RBAC phase that follows, so this
 * migration changes no behaviour on its own.
 */
class m261016_100000_add_assigned_staff_to_customer extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%customer}}', 'assigned_staff_id', $this->integer()->unsigned()->null()->after('market_id'));
        $this->createIndex('idx-customer-assigned_staff_id', '{{%customer}}', 'assigned_staff_id');
        $this->addForeignKey(
            'fk-customer-assigned_staff_id',
            '{{%customer}}',
            'assigned_staff_id',
            '{{%user}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        // Backfill from the customer's market, the only field that already
        // encodes whose customer this is. Soft-deleted customers are
        // backfilled too: they keep their owner so that a restore from the
        // trash doesn't come back unassigned.
        $backfilled = $this->db->createCommand(
            'UPDATE {{%customer}} c
             INNER JOIN {{%market}} m ON m.id = c.market_id
             SET c.assigned_staff_id = m.assigned_staff_id
             WHERE c.market_id IS NOT NULL AND m.assigned_staff_id IS NOT NULL'
        )->execute();

        $unassigned = (new \yii\db\Query())
            ->from('{{%customer}}')
            ->where(['assigned_staff_id' => null, 'deleted_at' => null])
            ->count('*', $this->db);

        echo "    > backfilled assigned_staff_id for {$backfilled} customer(s); {$unassigned} live customer(s) left unassigned for the admin to assign\n";
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-customer-assigned_staff_id', '{{%customer}}');
        $this->dropIndex('idx-customer-assigned_staff_id', '{{%customer}}');
        $this->dropColumn('{{%customer}}', 'assigned_staff_id');
    }
}
