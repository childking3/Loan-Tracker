<?php

namespace app\components;

use app\models\Loan;
use app\models\User;
use Yii;

/**
 * Caches dashboard totals in KeyDB behind a single incrementing "version"
 * integer, bumped by bumpVersion() from every write that affects the
 * totals (Loan::afterSave(), RepaymentController::actionCreate(), the
 * mark-overdue console command).
 *
 * Adapted from HumHub's polling approach (protected/humhub/modules/live) -
 * see CODEBASE.md's "Borrowed from HumHub" section. HumHub tracks "what
 * changed" via an append-only event-log table, suited to a busy
 * multi-tenant feed with many independent changes. This dashboard only
 * needs "has anything changed since my last totals", so a single bumped
 * KeyDB integer is the proportionate equivalent without an extra table.
 */
class DashboardCache
{
    private const VERSION_KEY = 'dashboard_version';
    private const TOTALS_KEY = 'dashboard_totals';

    public static function bumpVersion(): void
    {
        $cache = Yii::$app->cache;
        $current = (int) ($cache->get(self::VERSION_KEY) ?: 0);
        $cache->set(self::VERSION_KEY, $current + 1, 0);
        $cache->delete(self::TOTALS_KEY);
    }

    public static function getVersion(): int
    {
        return (int) (Yii::$app->cache->get(self::VERSION_KEY) ?: 0);
    }

    public static function getTotals(): array
    {
        $cached = Yii::$app->cache->get(self::TOTALS_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $totals = self::computeTotals();
        Yii::$app->cache->set(self::TOTALS_KEY, $totals, 86400);

        return $totals;
    }

    private static function computeTotals(): array
    {
        $db = Yii::$app->db;

        $activeLoanCount = (int) Loan::find()->andWhere(['status' => 'active'])->count();
        $overdueLoanCount = (int) Loan::find()->andWhere(['status' => 'overdue'])->count();

        // remainingBalancesFor() does one GROUP BY query for all active/
        // overdue loans instead of one per-loan cache/SQL round trip via
        // getRemainingBalance() - avoids the worst case where this cache
        // miss coincides with the per-loan cache also being cold.
        $activeOrOverdueLoans = Loan::find()->andWhere(['in', 'status', ['active', 'overdue']])->all();
        $outstandingBalance = array_sum(Loan::remainingBalancesFor($activeOrOverdueLoans));

        $todaysCollections = (float) $db->createCommand(
            'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE payment_date = :today'
        )->bindValue(':today', date('Y-m-d'))->queryScalar();

        // Admins excluded from staff performance by explicit request -
        // this table tracks loan officers doing collections, not accounts
        // managing them. Excluded by RBAC role (no column for it on
        // `user`; role lives in auth_assignment), ids passed as bound
        // placeholders.
        $adminIds = array_map('intval', Yii::$app->authManager->getUserIdsByRole(User::ROLE_ADMIN));
        $excludeAdmins = '';
        $params = [':active' => 'active', ':statusActive' => User::STATUS_ACTIVE];
        if ($adminIds !== []) {
            $placeholders = [];
            foreach ($adminIds as $index => $adminId) {
                $placeholder = ':admin' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $adminId;
            }
            $excludeAdmins = ' AND u.id NOT IN (' . implode(',', $placeholders) . ')';
        }

        // Correlated subqueries rather than a JOIN + GROUP BY: a staff
        // member with multiple loans and multiple repayments would inflate
        // both counts through the JOIN's cross-product before aggregation.
        $staffPerformance = $db->createCommand(
            'SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM {{%loan}} l WHERE l.assigned_staff_id = u.id AND l.status = :active) AS active_loans,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM {{%repayment}} r WHERE r.recorded_by_staff_id = u.id) AS total_collected
             FROM {{%user}} u
             WHERE u.status = :statusActive' . $excludeAdmins . '
             ORDER BY u.full_name'
        )->bindValues($params)->queryAll();

        return [
            'activeLoanCount' => $activeLoanCount,
            'overdueLoanCount' => $overdueLoanCount,
            'outstandingBalance' => round($outstandingBalance, 2),
            'todaysCollections' => round($todaysCollections, 2),
            'staffPerformance' => $staffPerformance,
        ];
    }
}
