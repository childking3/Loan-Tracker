<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage[] $packages */

use app\helpers\Currency;
use yii\helpers\Html;

$this->title = 'Loan packages';
?>
<h1>Loan packages</h1>

<?= $this->render('//_flashes') ?>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Loan amount</th>
        <th>Total repayment</th>
        <th>Daily payment</th>
        <th>Repayment period (days)</th>
        <th>Active</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($packages as $package): ?>
        <tr>
            <td><?= Html::encode($package->name) ?></td>
            <td><?= Html::encode(Currency::format($package->loan_amount)) ?></td>
            <td><?= Html::encode(Currency::format($package->total_repayment)) ?></td>
            <td><?= Html::encode(Currency::format($package->daily_payment)) ?></td>
            <td><?= Html::encode($package->repayment_period_days) ?></td>
            <td><?= $package->is_active ? 'Yes' : 'No' ?></td>
            <td><?= Html::a('Edit', ['loan-package/update', 'id' => $package->id]) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
