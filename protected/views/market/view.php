<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market $model */
/** @var array|null $summary one row from Market::balanceRows() */
/** @var array $customers rows from Market::customerBalanceRows() */

use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $model->name;

$disbursed = (float) ($summary['disbursed'] ?? 0);
$collected = (float) ($summary['collected'] ?? 0);
$outstanding = (float) ($summary['outstanding'] ?? 0);
?>
<div class="page-header-row">
    <h1><?= Html::encode($model->name) ?></h1>
    <?= Html::a('Edit market', ['market/update', 'id' => $model->id], ['class' => 'btn-icon']) ?>
</div>

<p class="muted">
    <?= $model->area !== null && $model->area !== '' ? Html::encode($model->area) . ' &middot; ' : '' ?>
    <?= $model->assignedStaff !== null
        ? 'Assigned to ' . Html::encode($model->assignedStaff->full_name)
        : 'Unassigned' ?>
</p>

<?= $this->render('//_flashes') ?>

<div class="stat-group">
    <h3 class="stat-group-label">Money</h3>
    <div class="stats">
        <div class="stat-tile">
            <span class="stat-label">Given out</span>
            <span class="stat-value"><?= Html::encode(Currency::format($disbursed)) ?></span>
            <span class="muted">Loan principal disbursed</span>
        </div>
        <div class="stat-tile stat-tile--success">
            <span class="stat-label">Collected</span>
            <span class="stat-value"><?= Html::encode(Currency::format($collected)) ?></span>
            <span class="muted">Repayments received</span>
        </div>
        <div class="stat-tile stat-tile--primary">
            <span class="stat-label">Balance remaining</span>
            <span class="stat-value"><?= Html::encode(Currency::format($outstanding)) ?></span>
            <span class="muted">Still owed on open loans</span>
        </div>
    </div>
</div>

<h2>Customers</h2>

<?php if ($customers === []): ?>
    <p class="muted">No customers registered in this market yet.</p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr>
            <th>Customer</th>
            <th>Phone</th>
            <th>Loans</th>
            <th>Given out</th>
            <th>Collected</th>
            <th>Balance remaining</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $row): ?>
            <tr>
                <td><?= Html::a(Html::encode($row['full_name']), ['customer/view', 'id' => $row['id']]) ?></td>
                <td><?= Html::encode($row['phone'] ?? '') ?></td>
                <td><?= Html::encode($row['loan_count']) ?></td>
                <td><?= Html::encode(Currency::format((float) $row['disbursed'])) ?></td>
                <td><?= Html::encode(Currency::format((float) $row['collected'])) ?></td>
                <td><?= Html::encode(Currency::format((float) $row['outstanding'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<p><?= Html::a('Back to markets', ['market/index'], ['class' => 'view-all-link']) ?></p>
