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
     * The create form's dropdown (LoanController::staffOptions()) only
     * offers staff-role users, but that alone doesn't stop a manager/admin
     * from POSTing another manager/admin's id directly - the prior 'exist'
     * rule only confirms the id belongs to *some* user, not a staff one.
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

    private static function balanceCacheKey(int $loanId): string
    {
        return "loan_balance:{$loanId}";
    }
}
