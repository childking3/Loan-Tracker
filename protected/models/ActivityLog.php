<?php

namespace app\models;

use app\helpers\Currency;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the activity_log table.
 *
 * Read-only from the app's point of view: rows are written only through
 * AuditLogger, never directly, and there's no update/delete action anywhere
 * - an audit trail editable by the roles it holds accountable isn't an
 * audit trail.
 */
class ActivityLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%activity_log}}';
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Fields excluded from every diff view: password_hash/auth_key are
     * meaningless to a reader and shouldn't widen a hash's exposure
     * surface; updated_at/created_at duplicate the log row's own "When"
     * column; id is redundant with the entity name already shown.
     */
    private const EXCLUDED_KEYS = ['id', 'password_hash', 'auth_key', 'updated_at', 'created_at'];

    /**
     * Per-entity field already shown in the Entity column
     * (LogController::resolveEntityNames() reads the same column) -
     * excluded from the diff for the same reason as `id` above.
     */
    private const PRIMARY_FIELD_BY_TYPE = [
        'customer' => 'full_name',
        'loan' => 'loan_number',
        'loan_package' => 'name',
        'guarantor' => 'full_name',
        'user' => 'username',
        // repayment has no name column - its Entity-column display
        // ("Repayment on LN-000024") is built from this field via a join.
        'repayment' => 'loan_id',
    ];

    /**
     * Plain-language labels for raw column names, kept separate from
     * each model's attributeLabels(): several columns here (customer_id,
     * assigned_staff_id, created_by, recorded_by_staff_id...) never
     * appear in an editable form, so no existing label covers them.
     */
    private const FIELD_LABELS = [
        'loan_number' => 'Loan number',
        'customer_id' => 'Customer',
        'package_id' => 'Loan package',
        'principal_amount' => 'Principal amount',
        'total_repayment' => 'Total repayment',
        'daily_payment' => 'Daily payment',
        'start_date' => 'Start date',
        'expected_completion_date' => 'Expected completion',
        'status' => 'Status',
        'assigned_staff_id' => 'Assigned staff',
        'created_by' => 'Created by',
        'full_name' => 'Full name',
        'phone' => 'Phone',
        'address' => 'Address',
        'relationship' => 'Relationship to borrower',
        'occupation' => 'Occupation',
        'loan_id' => 'Loan',
        'amount' => 'Amount',
        'payment_date' => 'Payment date',
        'recorded_by_staff_id' => 'Recorded by',
        'username' => 'Username',
        'email' => 'Email',
        'deleted_at' => 'Deleted',
        'loan_amount' => 'Loan amount',
        'repayment_period_days' => 'Repayment period (days)',
        'is_active' => 'Active',
        'name' => 'Package name',
        'role' => 'Role',
    ];

    /**
     * Columns storing another table's id - resolved to that record's
     * display name (batched via LogController::resolveEntityNames())
     * instead of showing a bare id.
     */
    private const FOREIGN_KEYS = [
        'customer_id' => 'customer',
        'package_id' => 'loan_package',
        'assigned_staff_id' => 'user',
        'created_by' => 'user',
        'loan_id' => 'loan',
        'recorded_by_staff_id' => 'user',
    ];

    private const CURRENCY_FIELDS = ['principal_amount', 'total_repayment', 'daily_payment', 'amount', 'loan_amount'];

    /**
     * Reduces old_value/new_value JSON to just the fields that changed.
     * Create (old null) and delete (new null) fall out of the same
     * per-key comparison as update, with no special-casing needed.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChanges(): array
    {
        // old_value/new_value are native JSON columns - ColumnSchema::
        // phpTypecast() already decodes them into arrays on load, so
        // decoding again here would throw (Json::decode() rejects an
        // already-array argument). A matching double-encode bug in
        // AuditLogger::write() used to mask this; fixed there too.
        $old = $this->old_value ?? [];
        $new = $this->new_value ?? [];

        $excludePrimary = self::PRIMARY_FIELD_BY_TYPE[$this->entity_type] ?? null;

        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (in_array($key, self::EXCLUDED_KEYS, true) || $key === $excludePrimary) {
                continue;
            }

            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    /**
     * Foreign-key values in this row's changes, grouped by entity type -
     * feeds LogController::resolveEntityNames() so ids are batch-resolved
     * instead of one query per id at render time.
     *
     * @return array<string, int[]> entity_type => ids
     */
    public function getForeignKeyReferences(): array
    {
        $refs = [];
        foreach ($this->getChanges() as $field => $value) {
            $type = self::FOREIGN_KEYS[$field] ?? null;
            if ($type === null) {
                continue;
            }
            foreach ([$value['old'], $value['new']] as $candidate) {
                if ($candidate !== null && is_numeric($candidate)) {
                    $refs[$type][] = (int) $candidate;
                }
            }
        }

        return $refs;
    }

    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * $entityNames is the same map resolveEntityNames() builds for the
     * Entity column, passed in rather than queried per value so a page
     * of changes stays at one batched query per entity type.
     */
    public static function formatChangeValue(string $field, $value, array $entityNames = []): string
    {
        if ($value === null) {
            return '—';
        }

        $fkType = self::FOREIGN_KEYS[$field] ?? null;
        if ($fkType !== null && is_numeric($value)) {
            return $entityNames[$fkType][(int) $value] ?? ('#' . $value . ' (record no longer exists)');
        }

        if (in_array($field, self::CURRENCY_FIELDS, true) && is_numeric($value)) {
            return Currency::format((float) $value);
        }

        // Loan/LoanPackage status values are strings already; this only
        // fires for User's integer status (STATUS_ACTIVE=10/INACTIVE=0).
        if ($field === 'status' && is_numeric($value)) {
            return (int) $value === User::STATUS_ACTIVE ? 'Active' : 'Inactive';
        }

        if (str_ends_with($field, '_at') && is_numeric($value) && (int) $value > 1000000000) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        if (is_string($value) && preg_match('/^[a-z][a-z_]*$/', $value)) {
            return ucfirst(str_replace('_', ' ', $value));
        }

        return (string) $value;
    }
}
