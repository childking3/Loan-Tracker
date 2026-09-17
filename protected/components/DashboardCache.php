<?php

namespace app\components;

use app\models\Loan;
use app\models\Repayment;
use app\models\User;
use Yii;

/**
 * Caches dashboard totals - and, since Markets/Phase 6, the recent-
 * activity feed - in KeyDB behind a single incrementing "version" integer,
 * bumped by bumpVersion() from every write that affects either
 * (Loan::afterSave(), RepaymentController::actionCreate(), the
 * mark-overdue console command). Totals has exactly one cache entry, so
 * bumpVersion() deletes it directly; the activity feed has many (one per
 * staffId), so its cache key embeds the version number instead - a bump
 * makes every previously-cached entry simply unreachable rather than
 * needing enumeration.
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
    private const ACTIVITY_LIMIT = 10;

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

    /**
     * The dashboard/staff-portal "Recent Activity" feed: the most recent
     * repayments ("in") and loan issuances ("out"), merged and sorted by
     * when each actually happened. Deliberately not built from
     * activity_log's generic JSON diffs - that table is a full audit
     * trail (every entity type, every field change), and re-deriving a
     * money-focused feed from it would mean per-entity-type interpretation
     * logic that already lives in ActivityLog/LogController for a
     * different purpose (the audit-log page itself). Two small, already-
     * indexed queries plus an in-PHP sort is simpler and matches this
     * class's own stated preference for proportionate solutions.
     *
     * $staffId scopes both queries to one staff member's own records
     * (dashboard/staff.php, the staff-portal home) - null returns
     * everyone's, for the admin/manager dashboard.
     *
     * Cached behind the same version counter as getTotals(), but keyed
     * per staffId (many possible cache entries, not one) - rather than
     * teach bumpVersion() to enumerate and delete every staff-specific
     * key, the version number is embedded in the cache key itself, so a
     * bump makes every previously-cached entry simply unreachable instead
     * of needing to be individually deleted.
     *
     * @return array<int, array{type: string, customer_name: string, loan_number: string, amount: float, created_at: int}>
     */
    public static function getRecentActivity(?int $staffId = null): array
    {
        $cacheKey = 'dashboard_activity_' . self::getVersion() . '_' . ($staffId ?? 'all');
        $cached = Yii::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $activity = self::computeRecentActivity($staffId);
        Yii::$app->cache->set($cacheKey, $activity, 86400);

        return $activity;
    }

    private static function computeRecentActivity(?int $staffId): array
    {
        $repaymentQuery = Repayment::find()->with(['loan.customer'])->orderBy(['created_at' => SORT_DESC])->limit(self::ACTIVITY_LIMIT);
        if ($staffId !== null) {
            $repaymentQuery->andWhere(['recorded_by_staff_id' => $staffId]);
        }

        $loanQuery = Loan::find()->with('customer')->orderBy(['created_at' => SORT_DESC])->limit(self::ACTIVITY_LIMIT);
        if ($staffId !== null) {
            $loanQuery->andWhere(['assigned_staff_id' => $staffId]);
        }

        $events = [];
        foreach ($repaymentQuery->all() as $repayment) {
            // A repayment's own loan/customer can be null in theory (the
            // FK is RESTRICT so it shouldn't happen in practice, but a
            // feed item with no name to show is worse than skipping it).
            if ($repayment->loan === null || $repayment->loan->customer === null) {
                continue;
            }
            $events[] = [
                'type' => 'in',
                'customer_name' => $repayment->loan->customer->full_name,
                'loan_number' => $repayment->loan->loan_number,
                'amount' => (float) $repayment->amount,
                'created_at' => (int) $repayment->created_at,
            ];
        }
        foreach ($loanQuery->all() as $loan) {
            if ($loan->customer === null) {
                continue;
            }
            $events[] = [
                'type' => 'out',
                'customer_name' => $loan->customer->full_name,
                'loan_number' => $loan->loan_number,
                'amount' => (float) $loan->principal_amount,
                'created_at' => (int) $loan->created_at,
            ];
        }

        usort($events, static fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return array_slice($events, 0, self::ACTIVITY_LIMIT);
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
        $activeOrOverdueLoans = Loan::find()->andWhere(['in', 'status', Loan::OPEN_STATUSES])->all();
        $outstandingBalance = array_sum(Loan::remainingBalancesFor($activeOrOverdueLoans));

        $todaysCollections = (float) $db->createCommand(
            'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE payment_date = :today'
        )->bindValue(':today', date('Y-m-d'))->queryScalar();

        // Admins excluded from staff performance by explicit request -
        // this table tracks loan officers doing collections, not accounts
        // managing them.
        [$excludeAdmins, $adminParams] = User::excludeAdminsSql('u');
        $params = array_merge([':active' => 'active', ':statusActive' => User::STATUS_ACTIVE], $adminParams);

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
