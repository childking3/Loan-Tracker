<?php

use yii\db\Migration;

/**
 * Seeds the loan_package table with five placeholder packages.
 *
 * These figures are NOT the client's real numbers. They exist only so the
 * rest of the application (loan creation, dashboard totals, calculations)
 * can be built and exercised against realistic-looking data while the exact
 * figures from the client's Excel calculator are still pending. Every row
 * here must be replaced - not merely reviewed - before the client performs
 * acceptance testing, since the brief requires these values to match the
 * original spreadsheet exactly.
 *
 * daily_payment is total_repayment / repayment_period_days, rounded to two
 * decimal places, consistent with how the real packages are expected to be
 * defined.
 */
class m260910_190600_seed_loan_packages extends Migration
{
    public function safeUp()
    {
        $now = time();

        $packages = [
            ['name' => 'Package A (placeholder)', 'loan_amount' => 5000.00, 'total_repayment' => 5750.00, 'daily_payment' => 191.67, 'repayment_period_days' => 30],
            ['name' => 'Package B (placeholder)', 'loan_amount' => 10000.00, 'total_repayment' => 11500.00, 'daily_payment' => 383.33, 'repayment_period_days' => 30],
            ['name' => 'Package C (placeholder)', 'loan_amount' => 20000.00, 'total_repayment' => 23000.00, 'daily_payment' => 511.11, 'repayment_period_days' => 45],
            ['name' => 'Package D (placeholder)', 'loan_amount' => 50000.00, 'total_repayment' => 57500.00, 'daily_payment' => 958.33, 'repayment_period_days' => 60],
            ['name' => 'Package E (placeholder)', 'loan_amount' => 100000.00, 'total_repayment' => 115000.00, 'daily_payment' => 1277.78, 'repayment_period_days' => 90],
        ];

        foreach ($packages as $package) {
            $this->insert('{{%loan_package}}', $package + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function safeDown()
    {
        $this->delete('{{%loan_package}}');
    }
}
