<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan[] $loans */
/** @var array<int, float> $balances */

use app\helpers\Badge;
use app\helpers\Currency;
use yii\helpers\Html;
?>
<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Loan number</th>
        <th>Assigned staff</th>
        <th>Principal</th>
        <th>Total repayment</th>
        <th>Remaining balance</th>
        <th>Status</th>
        <th>Start date</th>
        <th>Expected completion</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($loans as $loan): ?>
        <tr>
            <td><?= Html::a(Html::encode($loan->loan_number), ['loan/view', 'id' => $loan->id]) ?></td>
            <td><?= Html::encode($loan->assignedStaff->full_name ?? '') ?></td>
            <td><?= Html::encode(Currency::format($loan->principal_amount)) ?></td>
            <td><?= Html::encode(Currency::format($loan->total_repayment)) ?></td>
            <td><?= Html::encode(Currency::format($balances[(int) $loan->id] ?? 0.0)) ?></td>
            <td><?= Badge::status($loan->status) ?></td>
            <td><?= Html::encode($loan->start_date) ?></td>
            <td><?= Html::encode($loan->expected_completion_date) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
