<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage[] $packages */

use app\helpers\Badge;
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
        <th>Period</th>
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
            <td><?= Html::encode($package->repayment_period_days) ?> days</td>
            <td><?= Badge::yesNo($package->is_active) ?></td>
            <td><?= Html::a('Edit', ['loan-package/update', 'id' => $package->id]) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
