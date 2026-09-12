<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the loan_package table: the client's five fixed loan
 * packages. Read-only from the loan module's point of view (LoanController
 * only ever reads a package's terms via applyPackageTerms() - editing them
 * is LoanPackageController's job, added in Phase 9 alongside rules(),
 * attributeLabels() and TimestampBehavior, none of which existed before
 * since every row was previously written only by a migration).
 *
 * There is no create or delete action anywhere for this model, by explicit
 * decision: the client brief describes exactly five fixed packages, not an
 * open-ended set, and is_active already exists for retiring a package
 * without erasing loans that reference it via loan.package_id (RESTRICT) -
 * the same reasoning as Customer's soft-delete and User's deactivation.
 */
class LoanPackage extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%loan_package}}';
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
            [['name', 'loan_amount', 'total_repayment', 'daily_payment', 'repayment_period_days'], 'required'],
            ['name', 'string', 'max' => 100],
            [['loan_amount', 'total_repayment', 'daily_payment'], 'number', 'min' => 0.01],
            ['repayment_period_days', 'integer', 'min' => 1],
            // A loan that repays less than it lent isn't a loan this
            // business would issue - a plain sanity check on real money
            // figures an admin now enters by hand, which nothing validated
            // before since every prior row came from a migration the
            // developer wrote once and could review by eye.
            ['total_repayment', 'compare', 'compareAttribute' => 'loan_amount', 'operator' => '>=', 'message' => 'Total repayment cannot be less than the loan amount.'],
            ['is_active', 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Package name',
            'loan_amount' => 'Loan amount',
            'total_repayment' => 'Total repayment',
            'daily_payment' => 'Daily payment',
            'repayment_period_days' => 'Repayment period (days)',
            'is_active' => 'Active',
        ];
    }

    /**
     * @return static[]
     */
    public static function activePackages(): array
    {
        return static::find()
            ->andWhere(['is_active' => true])
            ->orderBy(['loan_amount' => SORT_ASC])
            ->all();
    }
}
