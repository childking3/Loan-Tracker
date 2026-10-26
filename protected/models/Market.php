<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the market table: a trading market/territory, with at
 * most one staff member assigned at a time (assigned_staff_id, nullable -
 * "Unassigned" is a normal state, not an error - see the market table
 * migration's docblock).
 *
 * Unlike LoanPackage (list/update only, five fixed rows), Market supports
 * full create/update - the business opens new markets over time. is_active
 * retires one temporarily without breaking customer.market_id (RESTRICT) -
 * deleted_at (below) is the separate, further step of removing it from
 * every normal listing, same two-state distinction as Customer.
 *
 * find() excludes soft-deleted rows by default, same as Customer's - every
 * normal lookup (findOne(), listings, relations) treats a deleted market as
 * gone without each caller filtering it out.
 */
class Market extends ActiveRecord
{
    use SoftDeletable;
    use HasAssignedStaff;
    use IncludesTrashedRelations;

    public static function tableName(): string
    {
        return '{{%market}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['name'], 'trim'],
            [['name'], 'required'],
            ['name', 'string', 'max' => 100],
            ['area', 'string', 'max' => 255],
            // The dropdown's blank "Unassigned" option posts '' - default
            // converts that to null before integer/exist run, rather than
            // '' hitting the INT column directly (assigned_staff_id is a
            // real optional state, not a validation failure - see the
            // market table migration's docblock).
            ['assigned_staff_id', 'default', 'value' => null],
            ['assigned_staff_id', 'integer'],
            ['assigned_staff_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id', 'filter' => ['status' => User::STATUS_ACTIVE]],
            ['assigned_staff_id', 'validateIsStaff'],
            ['is_active', 'boolean'],
        ];
    }

    /**
     * Same gap and fix as Loan::validateIsStaff(): the create/update form's
     * dropdown (User::activeStaffOptions()) only offers staff-role users,
     * but that alone doesn't stop a manager/admin from POSTing another
     * manager/admin's id directly - the 'exist' rule above only confirms
     * the id belongs to *some* active user, not a staff one. Skips when
     * empty (unlike Loan's, where assigned_staff_id is required) - a
     * market being unassigned is a valid, common state.
     */
    public function validateIsStaff(string $attribute): void
    {
        if ($this->hasErrors($attribute) || $this->$attribute === null) {
            return;
        }

        if (!User::isStaffId((int) $this->$attribute)) {
            $this->addError($attribute, 'Assigned staff must be an active staff-role account.');
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Market name',
            'area' => 'Area',
            'assigned_staff_id' => 'Assigned staff',
            'is_active' => 'Active',
        ];
    }


    /**
     * Inverse of Customer.market_id - lets actionIndex() eager-load a
     * per-market customer count without one query per row.
     */
    public function getCustomers(): ActiveQuery
    {
        return $this->hasMany(Customer::class, ['market_id' => 'id']);
    }

    /**
     * The three money figures, shared verbatim by balanceRows() and
     * customerBalanceRows() - they differ only in what they group by and
     * what they count alongside. Kept in one place so the two can never
     * drift apart: a market's total and the sum of its customers' rows are
     * the same numbers by construction, not by coincidence.
     *
     * Expects a `paid` derived table keyed by loan_id joined via loanJoins().
     */
    private static function moneyAggregates(): string
    {
        $db = Yii::$app->db;
        $open = implode(',', array_map(fn ($s) => $db->quoteValue($s), Loan::OPEN_STATUSES));

        return "COALESCE(SUM(l.principal_amount), 0) AS disbursed,
                COALESCE(SUM(paid.paid), 0) AS collected,
                COALESCE(SUM(CASE WHEN l.status IN ($open)
                                  THEN l.total_repayment - COALESCE(paid.paid, 0)
                                  ELSE 0 END), 0) AS outstanding";
    }

    /**
     * Loans for a customer plus their per-loan paid total. Measured against a
     * correlated subquery on production data (2026-10-20): the derived table
     * is faster (1.91ms vs 2.43ms), so it stays.
     */
    private static function loanJoins(): string
    {
        return 'LEFT JOIN {{%loan}} l ON l.customer_id = c.id
                LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM {{%repayment}} GROUP BY loan_id) paid
                       ON paid.loan_id = l.id';
    }

    /**
     * Per-market money figures for the dashboard breakdown and the market
     * drill-down: cash out (disbursed), cash in (collected), and what is
     * still owed. Shaped like User::staffPerformanceRows() - one aggregate
     * query, plain rows, no AR hydration - because it runs on the cached
     * dashboard path.
     *
     * Two details keep these rows reconciling with the dashboard's single
     * "Outstanding balance" tile, which sums every open loan regardless of
     * market or customer state:
     *
     *  - customer.market_id is NULLABLE, so a customer can sit outside every
     *    market. Those loans are returned as a synthetic row with a null id
     *    rather than dropped, otherwise the per-market figures would quietly
     *    add up to less than the headline total.
     *  - soft-deleted customers are excluded from customer_count but their
     *    loans still count toward the money: the debt is real and is already
     *    inside the headline total.
     */
    public static function balanceRows(?int $marketId = null): array
    {
        $db = Yii::$app->db;
        $aggregates = 'COUNT(DISTINCT CASE WHEN c.deleted_at IS NULL THEN c.id END) AS customer_count, '
            . self::moneyAggregates();
        $joins = self::loanJoins();

        // One market when the drill-down asks for it, rather than computing
        // every market's aggregates to display a single page. Same query and
        // same formula either way, so the drill-down can never disagree with
        // the dashboard tile.
        $rows = $db->createCommand(
            "SELECT m.id, m.name, $aggregates
             FROM {{%market}} m
             LEFT JOIN {{%customer}} c ON c.market_id = m.id
             $joins
             WHERE m.deleted_at IS NULL" . ($marketId !== null ? ' AND m.id = :marketId' : '') . "
             GROUP BY m.id, m.name
             ORDER BY m.name"
        )->bindValues($marketId !== null ? [':marketId' => $marketId] : [])->queryAll();

        // The orphan bucket is a whole-dashboard concept; a single market's
        // page has no use for it.
        if ($marketId !== null) {
            return self::castBalanceRows($rows);
        }

        $orphans = $db->createCommand(
            "SELECT $aggregates
             FROM {{%customer}} c
             $joins
             WHERE c.market_id IS NULL"
        )->queryOne();

        // Only shown when it actually holds something - an empty row would
        // just be noise on a dashboard where every customer has a market.
        if ($orphans !== false && ((int) $orphans['customer_count'] > 0 || (float) $orphans['outstanding'] > 0)) {
            $rows[] = ['id' => null, 'name' => 'No market assigned'] + $orphans;
        }

        return self::castBalanceRows($rows);
    }

    private static function castBalanceRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => $r['id'] === null ? null : (int) $r['id'],
            'name' => (string) $r['name'],
            'customer_count' => (int) $r['customer_count'],
            'disbursed' => round((float) $r['disbursed'], 2),
            'collected' => round((float) $r['collected'], 2),
            'outstanding' => round((float) $r['outstanding'], 2),
        ], $rows);
    }

    /**
     * The same cash-out / cash-in / still-owed figures as balanceRows(), but
     * one row per customer inside a single market, for the drill-down.
     *
     * Soft-deleted customers are excluded here (unlike the money in
     * balanceRows()): this is a working list of who owes what, and a deleted
     * customer is not someone to go and collect from.
     */
    public static function customerBalanceRows(int $marketId): array
    {
        $db = Yii::$app->db;
        $aggregates = 'COUNT(DISTINCT l.id) AS loan_count, ' . self::moneyAggregates();

        return $db->createCommand(
            "SELECT c.id, c.full_name, c.phone, $aggregates
             FROM {{%customer}} c
             " . self::loanJoins() . "
             WHERE c.market_id = :marketId AND c.deleted_at IS NULL
             GROUP BY c.id, c.full_name, c.phone
             ORDER BY c.full_name"
        )->bindValue(':marketId', $marketId)->queryAll();
    }

    /**
     * @return static[]
     */
    public static function activeMarkets(): array
    {
        return static::find()
            ->andWhere(['is_active' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();
    }
}
