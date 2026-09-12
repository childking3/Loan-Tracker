<?php

namespace app\components;

use Yii;

/**
 * Writes rows to the activity_log table (schema:
 * m260910_190500_create_activity_log_table).
 *
 * Two entry points matching the table's category split:
 * - audit(): data-changing actions (customer/loan/repayment CRUD, CSV
 *   import, mark-overdue). Carries the acting user id, or null for a
 *   console command with no session user.
 * - access(): auth events only (login success/failure, logout), not every
 *   page view - satisfies "who logged in/out, who failed" without
 *   multiplying write volume for no proportionate benefit on a small
 *   internal tool.
 *
 * Both funnel through write() so the two categories can't diverge in
 * column handling by accident.
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

        // old_value/new_value are native MySQL JSON columns; yii\db\mysql\
        // ColumnSchema::dbTypecast() already wraps a bound value in a
        // JsonExpression that encodes it once. Passing an already-
        // json_encode()'d string here double-encodes it - previously
        // masked by a matching double-decode in ActivityLog::getChanges().
        // Always pass arrays/null here, never pre-encoded strings.
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
