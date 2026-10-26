<?php

namespace app\components;

use app\models\Loan;
use app\models\Market;
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
        // 60s, not 24h: each app node has its own KeyDB, and bumpVersion()/writes only
        // clear the local node's copy. 60s bounds how stale the other node can be.
        Yii::$app->cache->set(self::TOTALS_KEY, $totals, 60);

        return $totals;
    }

    private static function computeTotals(): array
    {
        $db = Yii::$app->db;

        $activeLoanCount = (int) Loan::find()->andWhere(['status' => 'active'])->count();
        $overdueLoanCount = (int) Loan::find()->andWhere(['status' => 'overdue'])->count();

        // A single SQL aggregate rather than remainingBalancesFor() here:
        // that helper (correctly used everywhere it loops over a page's
        // worth of Loan objects the caller already needed as AR instances
        // anyway) would otherwise hydrate every open loan as a full
        // ActiveRecord purely to sum one derived number - wasted work and
        // memory on a cache-refresh path that runs unattended, worse as the
        // open-loan count grows. Same total open-balance formula as
        // ReportController::actionOutstanding()'s filter.
        $outstandingBalance = (float) $db->createCommand(
            'SELECT COALESCE(SUM(l.total_repayment - COALESCE(r.paid, 0)), 0)
             FROM {{%loan}} l
             LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM {{%repayment}} GROUP BY loan_id) r ON r.loan_id = l.id
             WHERE l.status IN (' . implode(',', array_map(fn ($s) => $db->quoteValue($s), Loan::OPEN_STATUSES)) . ')'
        )->queryScalar();

        $todaysCollections = (float) $db->createCommand(
            'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE payment_date = :today'
        )->bindValue(':today', date('Y-m-d'))->queryScalar();

        // Management (admin + manager) excluded from staff performance by
        // explicit request - this table tracks loan officers doing
        // collections, not the accounts managing them. See
        // User::staffPerformanceRows()'s own docblock for where this used
        // to be a duplicated raw query (consolidated 2026-09-18).
        $staffPerformance = User::staffPerformanceRows();

        // Every market, unscoped - like $staffPerformance above, this is one
        // shared cache entry, so the view filters by role rather than the
        // cache depending on who warmed it.
        $marketBalances = Market::balanceRows();

        return [
            'activeLoanCount' => $activeLoanCount,
            'overdueLoanCount' => $overdueLoanCount,
            'outstandingBalance' => round($outstandingBalance, 2),
            'todaysCollections' => round($todaysCollections, 2),
            'staffPerformance' => $staffPerformance,
            'marketBalances' => $marketBalances,
        ];
    }
}
