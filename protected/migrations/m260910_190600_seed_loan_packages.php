<?php

use yii\db\Migration;

/**
 * Seeds loan_package with five placeholder packages so the rest of the app
 * (loan creation, dashboard, reports) can be built against realistic data
 * before the client's real Excel-calculator figures are available. Every row
 * must be replaced, not merely reviewed, before client acceptance testing.
 *
 * daily_payment = total_repayment / repayment_period_days, rounded to 2dp.
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
