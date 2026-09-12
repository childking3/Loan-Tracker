<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan $model */
/** @var float $remainingBalance */
/** @var \app\models\Repayment[] $repayments */
/** @var bool $canRecordRepayment */
/** @var \app\models\Guarantor[] $guarantors */
/** @var bool $canManageLoan */

use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $model->loan_number;
?>
<h1><?= Html::encode($model->loan_number) ?></h1>

<?= $this->render('//_flashes') ?>

<p>Customer: <?= Html::a(Html::encode($model->customer->full_name), ['customer/view', 'id' => $model->customer_id]) ?></p>
<p>Package: <?= Html::encode($model->package->name) ?></p>
<p>Principal amount: <?= Html::encode(Currency::format($model->principal_amount)) ?></p>
<p>Total repayment: <?= Html::encode(Currency::format($model->total_repayment)) ?></p>
<p>Daily payment: <?= Html::encode(Currency::format($model->daily_payment)) ?></p>
<p>Remaining balance: <?= Html::encode(Currency::format($remainingBalance)) ?></p>
<p>Status: <span class="badge badge-<?= Html::encode($model->status) ?>"><?= Html::encode($model->status) ?></span></p>
<p>Start date: <?= Html::encode($model->start_date) ?></p>
<p>Expected completion: <?= Html::encode($model->expected_completion_date) ?></p>
<p>Assigned staff: <?= Html::encode($model->assignedStaff->full_name) ?></p>

<h2>Guarantors</h2>

<?php if ($guarantors === []): ?>
    <p>No guarantors recorded.</p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr>
            <th>Full name</th>
            <th>Phone</th>
            <th>Relationship to borrower</th>
            <th>Occupation</th>
            <th>Address</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($guarantors as $guarantor): ?>
            <tr>
                <td><?= Html::encode($guarantor->full_name) ?></td>
                <td><?= Html::encode($guarantor->phone) ?></td>
                <td><?= Html::encode($guarantor->relationship ?? '') ?></td>
                <td><?= Html::encode($guarantor->occupation ?? '') ?></td>
                <td><?= Html::encode($guarantor->address ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php if ($canManageLoan && in_array($model->status, ['active', 'overdue'], true)): ?>
    <?= Html::beginForm(['guarantor/create', 'loanId' => $model->id], 'post') ?>
        <div>
            <label>
                Full name
                <?= Html::input('text', 'Guarantor[full_name]', null, ['required' => true]) ?>
            </label>
        </div>
        <div>
            <label>
                Phone
                <?= Html::input('tel', 'Guarantor[phone]', null, ['required' => true]) ?>
            </label>
        </div>
        <div>
            <label>
                Relationship to borrower
                <?= Html::input('text', 'Guarantor[relationship]', null, ['placeholder' => 'e.g. Brother, Employer, Colleague']) ?>
            </label>
        </div>
        <div>
            <label>
                Occupation
                <?= Html::input('text', 'Guarantor[occupation]') ?>
            </label>
        </div>
        <div>
            <label>
                Address
                <?= Html::textarea('Guarantor[address]') ?>
            </label>
        </div>
        <?= Html::submitButton('Add guarantor') ?>
    <?= Html::endForm() ?>
<?php endif; ?>

<h2>Repayments</h2>

<?php if ($canRecordRepayment && in_array($model->status, ['active', 'overdue'], true)): ?>
    <?= Html::beginForm(['repayment/create', 'loanId' => $model->id], 'post') ?>
        <label>
            Amount
            <?= Html::input('number', 'Repayment[amount]', null, ['step' => '0.01', 'min' => '0.01', 'required' => true]) ?>
        </label>
        <label>
            Payment date
            <?= Html::input('date', 'Repayment[payment_date]', date('Y-m-d'), ['required' => true]) ?>
        </label>
        <?= Html::submitButton('Record repayment') ?>
    <?= Html::endForm() ?>
<?php endif; ?>

<?php if ($repayments === []): ?>
    <p>No repayments recorded yet.</p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr>
            <th>Amount</th>
            <th>Payment date</th>
            <th>Recorded by</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($repayments as $repayment): ?>
            <tr>
                <td><?= Html::encode(Currency::format($repayment->amount)) ?></td>
                <td><?= Html::encode($repayment->payment_date) ?></td>
                <td><?= Html::encode($repayment->recordedByStaff->full_name ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
