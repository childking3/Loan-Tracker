<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var \app\models\Loan[] $loans */

use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $model->full_name;
?>
<h1><?= Html::encode($model->full_name) ?></h1>

<?= $this->render('//_flashes') ?>

<p>Phone: <?= Html::encode($model->phone) ?></p>
<p>Address: <?= Html::encode($model->address ?? '') ?></p>

<p><?= Html::a('Edit', ['customer/update', 'id' => $model->id]) ?></p>
<?= Html::beginForm(['customer/delete', 'id' => $model->id], 'post') ?>
    <?= Html::submitButton('Delete', ['onclick' => "return confirm('Delete this customer?');"]) ?>
<?= Html::endForm() ?>

<h2>Loan history</h2>
<?php if (\Yii::$app->user->can('manageLoans')): ?>
    <p><?= Html::a('New loan', ['loan/create', 'customerId' => $model->id]) ?></p>
<?php endif; ?>
<?php if ($loans === []): ?>
    <p>No loans yet.</p>
<?php else: ?>
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
                <td><?= Html::encode(Currency::format($loan->getRemainingBalance())) ?></td>
                <td><span class="badge badge-<?= Html::encode($loan->status) ?>"><?= Html::encode($loan->status) ?></span></td>
                <td><?= Html::encode($loan->start_date) ?></td>
                <td><?= Html::encode($loan->expected_completion_date) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
