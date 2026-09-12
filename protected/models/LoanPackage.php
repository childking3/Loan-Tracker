<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the loan_package table: five fixed loan packages per
 * the client brief. Read-only from the loan module (LoanController reads
 * terms via applyPackageTerms(); editing is LoanPackageController's job).
 *
 * No create or delete action exists by design - is_active retires a
 * package without breaking loans that reference it via loan.package_id
 * (RESTRICT), the same pattern as Customer's soft-delete and User's
 * deactivation.
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
            // A loan that repays less than it lent isn't one this business
            // would issue - sanity check now that an admin enters figures
            // by hand instead of a reviewed migration.
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
