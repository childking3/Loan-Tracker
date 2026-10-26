<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage[] $packages */

use app\helpers\Badge;
use app\helpers\Currency;
use yii\helpers\Html;

$this->title = 'Loan packages';
?>
<div class="page-header-row">
    <h1>Loan packages</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> New package', ['loan-package/create'], ['class' => 'btn-icon']) ?>
</div>

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
            <td><?= Html::encode($package->repayment_period_days) ?> working days</td>
            <td><?= Badge::yesNo($package->is_active) ?></td>
            <td>
                <?= Html::a('Edit', ['loan-package/update', 'id' => $package->id]) ?>
                <?= Html::beginForm(['loan-package/delete', 'id' => $package->id], 'post', ['class' => 'inline-form']) ?>
                    <?= Html::submitButton('Delete', ['data-confirm' => 'Delete this loan package? This only works if no loan uses it yet.']) ?>
                <?= Html::endForm() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
