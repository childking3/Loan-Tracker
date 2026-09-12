<?php

namespace app\models;

use app\helpers\Currency;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the activity_log table (created in
 * m260910_190500_create_activity_log_table, unused until Phase 9).
 *
 * Read-only from the application's point of view: rows are written
 * exclusively through app\components\AuditLogger, never through this class
 * directly, and there is deliberately no update/delete action anywhere -
 * an audit trail that could be edited or removed by the same roles it is
 * meant to hold accountable is not an audit trail.
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
     * Fields never worth showing in a log view even though they're part
     * of the raw attribute snapshot every AuditLogger::audit() call
     * stores - password_hash/auth_key are meaningless to a human reader
     * and there is no reason to widen a hash's exposure surface for
     * zero audit value; updated_at/created_at change on every save (or
     * duplicate the "When" column, which is this same log row's own
     * created_at) and carry no information about what actually
     * happened; id is present on one side only for a create/delete, so
     * it would otherwise show a redundant "id: — -> 3035" line right
     * next to the entity name that already identifies the record.
     */
    private const EXCLUDED_KEYS = ['id', 'password_hash', 'auth_key', 'updated_at', 'created_at'];

    /**
     * The one field per entity type that's already shown verbatim in
     * the Entity column (LogController::resolveEntityNames() reads the
     * exact same column) - excluded from the diff for the same reason
     * as `id` above, just per-entity-type instead of universal.
     */
    private const PRIMARY_FIELD_BY_TYPE = [
        'customer' => 'full_name',
        'loan' => 'loan_number',
        'loan_package' => 'name',
        'guarantor' => 'full_name',
        'user' => 'username',
        // repayment has no name column of its own - LogController's
        // resolveEntityNames() builds its Entity-column display value
        // ("Repayment on LN-000024") from this same field via a join,
        // so it's just as redundant to repeat here as the others above.
        'repayment' => 'loan_id',
    ];

    /**
     * Plain-language labels for every column that appears in any
     * entity's attribute snapshot - a raw column name
     * ("assigned_staff_id", "principal_amount") means nothing to the
     * non-technical staff this log is actually written for. Kept as
     * its own map here rather than reusing each model's own
     * attributeLabels(): several of these columns (customer_id,
     * assigned_staff_id, created_by, loan_id, recorded_by_staff_id) are
     * never present in any editable form in the first place, so no
     * existing label covers them, and a log entry's audience/context
     * ("who did this, to what") is different enough from a form's that
     * the two aren't necessarily the same wording anyway.
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
     * Columns that store another table's id - shown as that record's
     * own display name (resolved the same batched way as the Entity
     * column, see LogController::resolveEntityNames()) instead of a
     * bare number a reader would have to go look up themselves.
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
     * Reduces this row's old_value/new_value JSON into just the fields
     * that actually changed - both create (old_value === null, so
     * everything in new_value is "new") and delete (the reverse) are
     * handled the same way as update (both non-null, most keys usually
     * identical between the two full snapshots) rather than as special
     * cases, since a plain per-key comparison already produces the
     * right result for all three: only keys whose old and new value
     * differ end up in the returned array.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChanges(): array
    {
        // old_value/new_value are native MySQL JSON columns - yii\db\mysql\
        // ColumnSchema::phpTypecast() already json_decode()s them into a
        // plain PHP array the moment this row is loaded via ActiveRecord,
        // so $this->old_value/$this->new_value are arrays already by the
        // time this method runs. Decoding them again used to be exactly
        // matched by a symmetric double-encoding bug in AuditLogger::
        // write() (see its own comment), so it happened to produce the
        // right array anyway for every row written through that method -
        // until a correctly single-encoded row (written directly via SQL)
        // hit this same double-decode and threw, since Json::decode()
        // rejects an already-array argument outright.
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
     * Every foreign-key-shaped value referenced anywhere in this row's
     * changes, grouped by which entity type it points at - handed to
     * LogController::resolveEntityNames() so it can batch these into
     * the same lookup query it already runs for the Entity column,
     * rather than the view resolving one id at a time while rendering.
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
     * $entityNames is the same [entity_type => [id => name]] map
     * LogController::resolveEntityNames() builds for the Entity column -
     * passed in here rather than queried per value, so formatting a
     * whole page of changes stays at the one batched query per entity
     * type that resolveEntityNames() already runs, not a query per
     * foreign-key value rendered.
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

        // Loan/LoanPackage status columns are already human-readable
        // strings ('active', 'completed', ...) - is_numeric() is false
        // for those, so this branch only ever fires for User's integer
        // status column (User::STATUS_ACTIVE = 10, STATUS_INACTIVE = 0).
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
