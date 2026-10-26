<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan $model */
/** @var float $remainingBalance */
/** @var \app\models\Repayment[] $repayments */
/** @var array $editedRepaymentIds */
/** @var bool $canManageRepayments record a repayment, and correct one already recorded */
/** @var \app\models\Guarantor[] $guarantors */
/** @var bool $canManageLoan */

use app\helpers\Badge;
use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $model->loan_number;
?>
<div class="page-header-row">
    <h1><?= Html::encode($model->loan_number) ?></h1>
    <?php if ($canManageLoan && $model->isOpenForActivity()): ?>
        <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg> Edit', ['loan/update', 'id' => $model->id], ['class' => 'btn-icon']) ?>
    <?php endif; ?>
</div>

<?= $this->render('//_flashes') ?>

<p>Customer: <?= Html::a(Html::encode($model->customer->full_name), ['customer/view', 'id' => $model->customer_id]) ?></p>
<p>Package: <?= Html::encode($model->package->name) ?></p>
<p>Principal amount: <?= Html::encode(Currency::format($model->principal_amount)) ?></p>
<p>Total repayment: <?= Html::encode(Currency::format($model->total_repayment)) ?></p>
<p>Daily payment: <?= Html::encode(Currency::format($model->daily_payment)) ?></p>
<p>Remaining balance: <?= Html::encode(Currency::format($remainingBalance)) ?></p>
<p>Status: <?= Badge::status($model->status) ?></p>
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
            <?php if ($canManageLoan): ?><th></th><?php endif; ?>
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
                <?php if ($canManageLoan): ?>
                    <td><?= Html::a('Edit', ['guarantor/update', 'id' => $guarantor->id]) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php if ($canManageLoan && $model->isOpenForActivity()): ?>
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

<?php if ($canManageRepayments && $model->isOpenForActivity()): ?>
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
            <?php if ($canManageRepayments): ?><th></th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($repayments as $repayment): ?>
            <tr>
                <td><?= Html::encode(Currency::format($repayment->amount)) ?><?php if (isset($editedRepaymentIds[$repayment->id])): ?> <span class="muted-note" title="Amount or date corrected after it was recorded - see the audit log for the original value.">(edited)</span><?php endif; ?></td>
                <td><?= Html::encode($repayment->payment_date) ?></td>
                <td><?= Html::encode($repayment->recordedByStaff->full_name ?? '') ?></td>
                <?php if ($canManageRepayments): ?>
                    <td><?= Html::a('Edit', ['repayment/update', 'id' => $repayment->id]) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
