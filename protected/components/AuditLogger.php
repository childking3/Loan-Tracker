<?php

namespace app\components;

use Yii;

/**
 * Writes rows to the activity_log table created in Phase 1
 * (m260910_190500_create_activity_log_table) but never actually written to
 * until this Phase 9 pass - the table and its two RBAC permissions
 * (viewAuditLog, viewAccessLogs) existed since Phase 2/1 respectively with
 * nothing behind them.
 *
 * Two entry points, matching the table's own category split:
 *
 * - audit(): data-changing actions (customer/loan/repayment create-update-
 *   delete, CSV import, the mark-overdue console command). Always carries
 *   the acting user id (or null for a console command with no user in
 *   session - see the migration's own docblock on why user_id is
 *   nullable).
 * - access(): authentication events only - login success, login failure,
 *   logout - matching the migration's docblock ("category = access, for
 *   login/logout and access events") rather than every page view. Logging
 *   every GET request a user makes would multiply the table's write volume
 *   by roughly the number of pages in the app with no proportionate
 *   security benefit for a small internal tool; the brief's pentest
 *   checklist item this satisfies is "who logged in/out and when, and who
 *   tried and failed", not a full clickstream. This scope decision should
 *   be revisited with the user if the brief is later found to require
 *   more.
 *
 * Both funnel through write() so the two categories can never end up with
 * different column handling by accident.
 */
final class AuditLogger
{
    public static function audit(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null
    ): void {
        self::write('audit', $action, $entityType, $entityId, $oldValue, $newValue);
    }

    public static function access(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $newValue = null
    ): void {
        self::write('access', $action, $entityType, $entityId, null, $newValue);
    }

    private static function write(
        string $category,
        string $action,
        ?string $entityType,
        ?int $entityId,
        ?array $oldValue,
        ?array $newValue
    ): void {
        // Console commands (e.g. loan/mark-overdue via cron) run under the
        // console app, which has no 'user' component at all - Yii::$app->has()
        // guards that rather than assuming a web request context.
        $userId = null;
        if (Yii::$app->has('user') && !Yii::$app->user->isGuest) {
            $userId = Yii::$app->user->id;
        }

        // yii\console\Request has no userIP property (that's web-specific),
        // so a console-run command logs a null IP rather than erroring.
        $ip = null;
        $request = Yii::$app->has('request') ? Yii::$app->request : null;
        if ($request instanceof \yii\web\Request) {
            $ip = $request->userIP;
        }

        // old_value/new_value are native MySQL JSON columns (see the
        // create-table migration's own docblock), and yii\db\mysql\
        // ColumnSchema::dbTypecast() already wraps any non-null value
        // bound against a JSON column in a JsonExpression that encodes
        // it exactly once when the query is built - passing an
        // already-Json::encode()'d string here (as this used to) gets
        // that string encoded a SECOND time, storing a JSON value whose
        // content is a string that itself contains JSON text, not the
        // object itself. Found this the hard way: every one of this
        // table's rows until now was double-encoded, silently
        // compensated for by a matching double-decode bug in
        // ActivityLog::getChanges() - masked completely until a
        // correctly single-encoded row (written directly via SQL, not
        // through this method) hit that same double-decode and crashed
        // outright, since json-decoding an already-array value throws
        // rather than silently doing nothing.
        Yii::$app->db->createCommand()->insert('{{%activity_log}}', [
            'user_id' => $userId,
            'category' => $category,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_address' => $ip,
            'created_at' => time(),
        ])->execute();
    }
}
