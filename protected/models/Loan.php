<?php

namespace app\models;

use app\components\DashboardCache;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the loan table.
 *
 * principal_amount, total_repayment, daily_payment and
 * expected_completion_date are deliberately absent from rules(), so mass
 * assignment (load()) can never set them from user input - they are only
 * ever written by applyPackageTerms(), called from
 * LoanController::actionCreate with a package looked up server-side. This
 * guarantees a loan's terms always match a real, active package's figures,
 * even if a client tampered with hidden form fields to submit different
 * amounts.
 *
 * loan_number is generated in afterSave(), from the row's own
 * auto-increment id, rather than computed before insert. A temporary
 * placeholder (unique but disposable) is written by beforeSave() to satisfy
 * the NOT NULL + UNIQUE constraint during the initial insert, then replaced
 * with the real LN-000123-style number once the id is known. This avoids a
 * race condition between two concurrent loan creations that a
 * precomputed-before-insert number could hit.
 */
class Loan extends ActiveRecord
{
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
            ['start_date', 'date', 'format' => 'php:Y-m-d'],
            ['customer_id', 'exist', 'targetClass' => Customer::class, 'targetAttribute' => 'id'],
            ['package_id', 'exist', 'targetClass' => LoanPackage::class, 'targetAttribute' => 'id'],
            ['assigned_staff_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id'],
            ['assigned_staff_id', 'validateIsStaff'],
        ];
    }

    /**
     * LoanController::staffOptions() already restricts the create form's
     * dropdown to users holding the 'staff' role specifically ("not every
     * active account" - see that method's own docblock) - but that alone
     * only controls what the form *offers*, not what the model *accepts*.
     * A manager or admin (manageLoans is manager/admin-only, so this is
     * the actual attacker profile for this gap) could still POST an
     * assigned_staff_id belonging to another manager or admin directly,
     * bypassing the dropdown entirely, and the prior 'exist' rule alone
     * would accept it - it only confirms the id belongs to *some* user,
     * not specifically a staff-role one. Found during a security recheck
     * that deliberately looked for exactly this shape of gap (a rule
     * enforced only in the UI, not the model) after finding two others
     * like it earlier the same day.
     */
    public function validateIsStaff(string $attribute): void
    {
        if ($this->hasErrors($attribute)) {
            return;
        }

        $staffIds = Yii::$app->authManager->getUserIdsByRole(User::ROLE_STAFF);
        if (!in_array((string) $this->$attribute, array_map('strval', $staffIds), true)) {
            $this->addError($attribute, 'Assigned staff must be an active staff-role account.');
        }
    }

    public function getCustomer(): ActiveQuery
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }

    public function getPackage(): ActiveQuery
    {
        return $this->hasOne(LoanPackage::class, ['id' => 'package_id']);
    }

    public function getAssignedStaff(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'assigned_staff_id']);
    }

    public function getGuarantors(): ActiveQuery
    {
        return $this->hasMany(Guarantor::class, ['loan_id' => 'id']);
    }

    /**
     * Copies terms from the selected package and computes the expected
     * completion date from start_date. Must be called, with an active
     * package, before save() on a new loan.
     */
    public function applyPackageTerms(LoanPackage $package): void
    {
        $this->principal_amount = $package->loan_amount;
        $this->total_repayment = $package->total_repayment;
        $this->daily_payment = $package->daily_payment;
        $this->expected_completion_date = date(
            'Y-m-d',
            strtotime($this->start_date . " +{$package->repayment_period_days} days")
        );
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
        Yii::$app->cache->set(self::balanceCacheKey($this->id), $balance, 86400);

        return $balance;
    }

    /**
     * Batch equivalent of getRemainingBalance() for report/dashboard loops
     * that already hold a full list of Loan objects and just want each
     * one's balance - one GROUP BY query for every loan's amount paid,
     * instead of one KeyDB round trip (or, on a cold cache, one SQL query)
     * per loan via the per-loan cache above. Deliberately bypasses that
     * cache rather than warming it: a report scanning the whole loan book
     * reads every loan once and moves on, so there's nothing later in the
     * same request to benefit from a warm per-loan key, and this always
     * reads live data instead of possibly-stale cached figures.
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
     * Invalidate-on-write: called wherever a repayment is inserted against
     * this loan, per the brief's caching decision. Also bumps the
     * dashboard's polling version counter, since dashboard totals are
     * derived from the same underlying data - see
     * DashboardController::bumpVersion() in Phase 6.
     */
    public static function invalidateBalanceCache(int $loanId): void
    {
        Yii::$app->cache->delete(self::balanceCacheKey($loanId));
    }

    private static function balanceCacheKey(int $loanId): string
    {
        return "loan_balance:{$loanId}";
    }
}
