<?php

namespace app\models;

use app\components\DashboardCache;
use app\helpers\WorkingDays;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the loan table.
 *
 * principal_amount, total_repayment, daily_payment and
 * expected_completion_date are absent from rules() so mass assignment can
 * never set them from user input - they're written only by
 * applyPackageTerms(), keeping a loan's terms tied to a real package even
 * if a client tampers with hidden form fields.
 *
 * loan_number is generated in afterSave() from the row's own auto-increment
 * id, not computed before insert. beforeSave() writes a disposable unique
 * placeholder to satisfy the NOT NULL + UNIQUE constraint during insert,
 * avoiding a race between two concurrent loan creations that a
 * precomputed number could hit.
 */
class Loan extends ActiveRecord
{
    use HasAssignedStaff;
    use IncludesTrashedRelations;

    /**
     * Every value the `status` enum column accepts. Was previously
     * copied as a literal array in both LoanController::actionIndex()
     * and ReportController::actionLoans() for validating a posted
     * `?status=` filter - consolidated here after a duplicate-code scan
     * found both copies.
     */
    public const STATUSES = ['active', 'completed', 'overdue', 'cancelled'];

    public static function tableName(): string
    {
        return '{{%loan}}';
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
            [['customer_id', 'package_id', 'start_date', 'assigned_staff_id'], 'required'],
            [['customer_id', 'package_id', 'assigned_staff_id'], 'integer'],
            // strictDateFormat: without it "2026-9-2" passes, and the controllers
            // compare these dates as plain strings ("2026-9-2" > "2026-09-15").
            ['start_date', 'date', 'format' => 'php:Y-m-d', 'strictDateFormat' => true],
            // Year bounds keep WorkingDays::add() and the DATE column in range.
            ['start_date', 'compare', 'compareValue' => '2000-01-01', 'operator' => '>=', 'message' => 'Start date is too far in the past.'],
            ['start_date', 'compare', 'compareValue' => '2100-12-31', 'operator' => '<=', 'message' => 'Start date is too far in the future.'],
            ['customer_id', 'exist', 'targetClass' => Customer::class, 'targetAttribute' => 'id'],
            ['package_id', 'exist', 'targetClass' => LoanPackage::class, 'targetAttribute' => 'id'],
            ['assigned_staff_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['assigned_staff_id', 'validateIsStaff'],
        ];
    }

    /**
     * The create form's dropdown (User::activeStaffOptions()) only offers
     * staff-role users, but that alone doesn't stop a manager/admin from
     * POSTing another manager/admin's id directly - the prior 'exist' rule
     * only confirms the id belongs to *some* user, not a staff one.
     */
    public function validateIsStaff(string $attribute): void
    {
        if ($this->hasErrors($attribute)) {
            return;
        }

        if (!User::isStaffId($this->$attribute !== null ? (int) $this->$attribute : null)) {
            $this->addError($attribute, 'Assigned staff must be an active staff-role account.');
        }
    }

    /**
     * Includes a soft-deleted customer: the loan and its history stay, so
     * every list/report/dashboard row that prints the customer's name must
     * still resolve one (Customer::find() alone hides deleted rows, which
     * made loan/index and the dashboard throw "property on null").
     */
    public function getCustomer(): ActiveQuery
    {
        return $this->hasOneIncludingTrashed(Customer::findWithTrashed(), ['id' => 'customer_id']);
    }

    public function getPackage(): ActiveQuery
    {
        return $this->hasOne(LoanPackage::class, ['id' => 'package_id']);
    }

    /** Includes a soft-deleted staff account, for the same reason as getCustomer(). */

    public function getGuarantors(): ActiveQuery
    {
        return $this->hasMany(Guarantor::class, ['loan_id' => 'id']);
    }

    /**
     * Whether this loan is still "open" - the only two statuses that may
     * ever receive a new repayment or guarantor. Consolidated from two
     * identical copies (RepaymentController::actionCreate(),
     * GuarantorController::actionCreate()) found during a duplicate-code
     * scan - each controller still writes its own flash message text,
     * since "Repayments"/"Guarantors can only be added to..." are
     * genuinely different strings, only the underlying predicate was
     * duplicated. A later, wider scan found the same literal
     * `['active', 'overdue']` pair in 4 more places (ReportController,
     * DashboardCache, loan/view.php x2, customer/view.php) that predate
     * this method entirely - all now route through here (or
     * OPEN_STATUSES below, for the two that build a SQL `IN (...)`
     * condition rather than checking one loan instance).
     */
    public const OPEN_STATUSES = ['active', 'overdue'];

    public function isOpenForActivity(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Whether the current logged-in user may view this specific loan -
     * true for anyone holding viewAllLoans, or the loan's own assigned
     * staff member (IsAssignedStaffRule, via viewAssignedLoans). This
     * exact composition was being hand-copied at every call site
     * (LoanController::actionView()/actionIndex()'s repayment-permission
     * check, RepaymentController::actionCreate(), and the loan-visibility
     * filters in dashboard/staff.php and customer/view.php) - centralized
     * here after the same drift already produced two real bugs found and
     * fixed independently (a staff member seeing, in their dashboard and
     * a customer's page, loans they had no access to open), rather than
     * risk a sixth hand-copy silently composing the check wrong.
     */
    public function isVisibleToCurrentUser(): bool
    {
        return Yii::$app->user->can('viewAllLoans') || Yii::$app->user->can('viewAssignedLoans', ['loan' => $this]);
    }

    /**
     * Copies terms from the selected package and computes the expected
     * completion date from start_date. Must be called, with an active
     * package, before save() on a new loan.
     *
     * The package's repayment period counts working days (Monday-Friday) -
     * repayments aren't collected on Saturdays and Sundays - so the
     * expected completion date skips weekends (see WorkingDays).
     */
    public function applyPackageTerms(LoanPackage $package): void
    {
        $this->principal_amount = $package->loan_amount;
        $this->total_repayment = $package->total_repayment;
        $this->daily_payment = $package->daily_payment;
        $this->expected_completion_date = WorkingDays::add($this->start_date, (int) $package->repayment_period_days);
    }

    /**
     * How many working days this loan was issued to run for. The package
     * isn't consulted: a package's period can be edited after a loan is
     * issued, but the loan keeps the period it was issued with, and that
     * period is exactly what its own start and expected completion dates
     * encode.
     */
    public function scheduledWorkingDays(): int
    {
        return WorkingDays::between($this->start_date, $this->expected_completion_date);
    }

    /**
     * Moves the schedule to a new start date, keeping the loan's own
     * period (see scheduledWorkingDays()). Caller saves.
     */
    public function rescheduleFrom(string $newStartDate): void
    {
        $period = $this->scheduledWorkingDays();
        $this->start_date = $newStartDate;
        $this->expected_completion_date = WorkingDays::add($newStartDate, $period);
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert && $this->loan_number === null) {
            $this->loan_number = 'TEMP-' . uniqid('', true);
        }

        return true;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            $this->updateAttributes([
                'loan_number' => 'LN-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            ]);
            DashboardCache::bumpVersion();
        }
    }

    /**
     * Remaining balance = total_repayment - SUM(repayment.amount), computed
     * on read and cached per loan in KeyDB (per the client brief), since
     * this is recalculated on every loan view and dashboard render.
     */
    public function getRemainingBalance(): float
    {
        $cached = Yii::$app->cache->get(self::balanceCacheKey($this->id));
        if ($cached !== false) {
            return (float) $cached;
        }

        $paid = (float) static::getDb()->createCommand(
            'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE loan_id = :id'
        )->bindValue(':id', $this->id)->queryScalar();

        $balance = round((float) $this->total_repayment - $paid, 2);
        // Short TTL on purpose: with two app nodes each node has its own KeyDB, and a
        // repayment only invalidates the local node's copy. 60s bounds cross-node staleness.
        Yii::$app->cache->set(self::balanceCacheKey($this->id), $balance, 60);

        return $balance;
    }

    /**
     * Batch equivalent of getRemainingBalance() for report/dashboard loops:
     * one GROUP BY query for all loans instead of one cache/SQL round trip
     * per loan. Deliberately bypasses the per-loan cache - a report reads
     * each loan once, so there's nothing to gain from warming it, and this
     * way it always reads live data.
     *
     * @param Loan[] $loans
     * @return array<int, float> balance keyed by loan id
     */
    public static function remainingBalancesFor(array $loans): array
    {
        if ($loans === []) {
            return [];
        }

        $ids = array_map(static fn (self $loan) => (int) $loan->id, $loans);
        $paidByLoan = static::getDb()->createCommand(
            'SELECT loan_id, COALESCE(SUM(amount), 0) AS paid FROM {{%repayment}} WHERE loan_id IN (' . implode(',', $ids) . ') GROUP BY loan_id'
        )->queryAll();
        $paidByLoan = array_column($paidByLoan, 'paid', 'loan_id');

        $balances = [];
        foreach ($loans as $loan) {
            $paid = (float) ($paidByLoan[$loan->id] ?? 0);
            $balances[(int) $loan->id] = round((float) $loan->total_repayment - $paid, 2);
        }

        return $balances;
    }

    /**
     * Invalidate-on-write - called wherever a repayment is recorded
     * against this loan, so a stale cached balance isn't served next read.
     */
    public static function invalidateBalanceCache(int $loanId): void
    {
        Yii::$app->cache->delete(self::balanceCacheKey($loanId));
    }

    /**
     * Keeps status truthful against a freshly-computed (never cached)
     * remaining balance, for any action that can move that balance after
     * the fact - currently only editing a repayment (RepaymentController::
     * actionUpdate() and, for the one-directional "just paid off" case,
     * actionCreate()). Mutates $this->status and returns whether it
     * actually changed; the caller owns save()/audit()/cache-invalidation,
     * same division of responsibility as applyPackageTerms()/
     * rescheduleFrom() above.
     *
     * 'cancelled' is left untouched on purpose: cancelling a loan is a
     * deliberate manual decision elsewhere, and a balance correction must
     * never silently resurrect it back into 'active'.
     *
     * LoanController::actionUpdate() has its own narrower active<->overdue
     * flip for a package/start-date edit; it never needs the "paid off"/
     * "un-paid" transitions this method adds; because a package or start
     * date can only be changed before any repayment exists, so that
     * balance is always the package's full one, unpaid. Not
     * consolidated into that call site in this pass - it's an already
     * live, already-tested path, and this method's "completed" transition
     * doesn't apply to it.
     */
    public function syncStatusFromBalance(float $remaining): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }

        $newStatus = $remaining <= 0.0
            ? 'completed'
            : ($this->expected_completion_date < date('Y-m-d') ? 'overdue' : 'active');

        if ($newStatus === $this->status) {
            return false;
        }

        $this->status = $newStatus;
        return true;
    }

    private static function balanceCacheKey(int $loanId): string
    {
        return "loan_balance:{$loanId}";
    }
}
