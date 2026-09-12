<?php

use yii\db\Migration;

/**
 * Replaces four single-column indexes with composites matching how they're
 * actually queried, found during a DB-interaction audit (see CODEBASE.md).
 * A composite still serves a plain equality lookup on its leftmost column
 * (loan.assigned_staff_id alone, loan.status alone, etc.), so every
 * existing query keeps its current index coverage or gains a new one - the
 * old single-column index becomes pure duplicate write/storage cost, not a
 * safety net.
 *
 * New index each pair replaces + who reads it:
 * - loan(assigned_staff_id, status): DashboardCache::computeTotals() and
 *   ReportController::actionStaff()'s per-staff correlated subqueries
 *   (WHERE assigned_staff_id = ? AND status = ?), previously an index
 *   lookup on one column plus a filter over every matching row.
 * - loan(status, created_at): ReportController::actionLoans()'s
 *   filter-by-status-then-sort-by-created_at query.
 * - loan(customer_id, created_at): CustomerController::actionView()'s
 *   per-customer loan history, same filter+sort shape.
 * - activity_log(category, id): LogController's per-category, paginated,
 *   id-DESC log views - without this, category alone finds the rows but id
 *   ordering still needs a filesort once the table is large.
 *
 * New composites are created before the old single-column index is
 * dropped, in each pair, so the FK column (assigned_staff_id, customer_id)
 * is never briefly without any covering index mid-migration.
 */
class m260912_020000_add_composite_query_indexes extends Migration
{
    public function safeUp()
    {
        $this->createIndex('idx-loan-assigned_staff_id-status', '{{%loan}}', ['assigned_staff_id', 'status']);
        $this->dropIndex('idx-loan-assigned_staff_id', '{{%loan}}');

        $this->createIndex('idx-loan-status-created_at', '{{%loan}}', ['status', 'created_at']);
        $this->dropIndex('idx-loan-status', '{{%loan}}');

        $this->createIndex('idx-loan-customer_id-created_at', '{{%loan}}', ['customer_id', 'created_at']);
        $this->dropIndex('idx-loan-customer_id', '{{%loan}}');

        $this->createIndex('idx-activity_log-category-id', '{{%activity_log}}', ['category', 'id']);
        $this->dropIndex('idx-activity_log-category', '{{%activity_log}}');
    }

    public function safeDown()
    {
        $this->createIndex('idx-activity_log-category', '{{%activity_log}}', 'category');
        $this->dropIndex('idx-activity_log-category-id', '{{%activity_log}}');

        $this->createIndex('idx-loan-customer_id', '{{%loan}}', 'customer_id');
        $this->dropIndex('idx-loan-customer_id-created_at', '{{%loan}}');

        $this->createIndex('idx-loan-status', '{{%loan}}', 'status');
        $this->dropIndex('idx-loan-status-created_at', '{{%loan}}');

        $this->createIndex('idx-loan-assigned_staff_id', '{{%loan}}', 'assigned_staff_id');
        $this->dropIndex('idx-loan-assigned_staff_id-status', '{{%loan}}');
    }
}
