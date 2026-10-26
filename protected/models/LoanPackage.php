<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the loan_package table: the business's loan packages,
 * editable in number (create/delete) as well as in terms. Read-only from
 * the loan module (LoanController reads terms via applyPackageTerms();
 * editing is LoanPackageController's job).
 *
 * Deleting a package a loan references fails on loan.package_id's own
 * RESTRICT foreign key - LoanPackageController checks for that first and
 * points the user at is_active instead, the same retire-without-breaking-
 * history pattern as Customer's soft-delete and User's deactivation.
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
            'repayment_period_days' => 'Repayment period (working days)',
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

    /**
     * id => label options for a package <select>, active packages only.
     * Shared by LoanController's and CustomerController's loan-create
     * forms - was a private copy in each until this consolidation.
     *
     * @return array<int, string>
     */
    public static function selectOptions(): array
    {
        $options = [];
        foreach (static::activePackages() as $package) {
            $options[$package->id] = sprintf(
                '%s (Amount: %s, Repay: %s over %d working days)',
                $package->name,
                $package->loan_amount,
                $package->total_repayment,
                $package->repayment_period_days
            );
        }

        return $options;
    }
}
