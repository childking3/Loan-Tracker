<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $staff */
/** @var \app\models\Loan[] $loans */
/** @var array $countsByStatus */
/** @var float $totalCollected */

use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $staff->full_name;
?>
<h1><?= Html::encode($staff->full_name) ?></h1>

<p class="muted"><?= Html::encode($staff->username) ?> &middot; <?= Html::encode($staff->email) ?></p>

<div class="stat-group">
    <h3 class="stat-group-label">Loans</h3>
    <div class="stats">
        <div class="stat-tile stat-tile--primary">
            <span class="stat-label">Total loans</span>
            <span class="stat-value"><?= count($loans) ?></span>
        </div>
        <div class="stat-tile">
            <span class="stat-label">Active loans</span>
            <span class="stat-value"><?= $countsByStatus['active'] ?></span>
        </div>
        <div class="stat-tile stat-tile--warning">
            <span class="stat-label">Overdue loans</span>
            <span class="stat-value"><?= $countsByStatus['overdue'] ?></span>
        </div>
    </div>
</div>

<div class="stat-group">
    <h3 class="stat-group-label">Collections</h3>
    <div class="stats">
        <div class="stat-tile stat-tile--success">
            <span class="stat-label">Total collected</span>
            <span class="stat-value"><?= Html::encode(Currency::format($totalCollected)) ?></span>
        </div>
    </div>
</div>

<h2>Loans</h2>
<?php if ($loans === []): ?>
    <p>No loans assigned to this staff member yet.</p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr>
            <th>Loan number</th>
            <th>Customer</th>
            <th>Status</th>
            <th>Principal</th>
            <th>Remaining balance</th>
            <th>Start date</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $loan): ?>
            <tr>
                <td><?= Html::a(Html::encode($loan->loan_number), ['loan/view', 'id' => $loan->id]) ?></td>
                <td><?= Html::a(Html::encode($loan->customer->full_name), ['customer/view', 'id' => $loan->customer_id]) ?></td>
                <td><span class="badge badge-<?= Html::encode($loan->status) ?>"><?= Html::encode($loan->status) ?></span></td>
                <td><?= Html::encode(Currency::format($loan->principal_amount)) ?></td>
                <td><?= Html::encode(Currency::format($loan->getRemainingBalance())) ?></td>
                <td><?= Html::encode($loan->start_date) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
