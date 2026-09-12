<?php

namespace app\components;

use app\models\Loan;
use app\models\User;
use Yii;

/**
 * Caches dashboard totals in KeyDB and tracks a single incrementing
 * "version" integer, bumped by bumpVersion() from every write that affects
 * those totals (see Loan::afterSave() for new loans,
 * RepaymentController::actionCreate() for repayments/loan completion, and
 * the console loan/mark-overdue command).
 *
 * Adapted from HumHub's polling approach (protected/humhub/modules/live),
 * not copied - see CODEBASE.md's "Borrowed from HumHub" section for the
 * comparison and why the shape differs here. HumHub tracks "what changed"
 * with an append-only event-log table filtered by created_at, which suits
 * a busy multi-tenant social feed where many different things can change
 * independently. This dashboard only ever needs to answer one question -
 * "has anything changed since the totals I already have" - so a single
 * KeyDB integer, bumped on write and compared by the poll endpoint, is the
 * proportionate equivalent without needing an extra database table.
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

        // remainingBalancesFor(): one GROUP BY query for every active/
        // overdue loan's amount paid, instead of one KeyDB round trip (or,
        // cold, one SQL query) per loan via getRemainingBalance()'s own
        // per-loan cache. This whole result is itself cached by
        // getTotals() above, so this only runs on a cache miss - but a
        // cache miss is exactly when the per-loan cache is coldest too,
        // which used to mean this loop was the worst case for both caches
        // missing at once.
        $activeOrOverdueLoans = Loan::find()->andWhere(['in', 'status', ['active', 'overdue']])->all();
        $outstandingBalance = array_sum(Loan::remainingBalancesFor($activeOrOverdueLoans));

        $todaysCollections = (float) $db->createCommand(
            'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE payment_date = :today'
        )->bindValue(':today', date('Y-m-d'))->queryScalar();

        // Admins are excluded from this table at the user's explicit
        // request: an admin can be assigned loans/repayments like anyone
        // else technically, but "staff performance" is meant to track the
        // loan officers actually doing collections, not the account(s)
        // managing them. Excluded by RBAC role, not by a column on `user`
        // (there isn't one - role lives entirely in auth_assignment), so
        // the id list is fetched separately and passed in as bound
        // placeholders rather than interpolated directly.
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
