<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan[] $loans */
/** @var string $status */
/** @var string $search */

use yii\helpers\Html;

$this->title = 'Loans';
?>
<h1>Loans</h1>

<?= Html::beginForm(['loan/index'], 'get') ?>
    <label>
        Loan number
        <?= Html::textInput('q', $search, ['placeholder' => 'e.g. LN-000023 or 23']) ?>
    </label>
    <label>
        Status
        <?= Html::dropDownList('status', $status, [
            '' => 'All',
            'active' => 'Active',
            'completed' => 'Completed',
            'overdue' => 'Overdue',
            'cancelled' => 'Cancelled',
        ]) ?>
    </label>
    <?= Html::submitButton('Filter') ?>
<?= Html::endForm() ?>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Loan number</th>
        <th>Customer</th>
        <th>Status</th>
        <th>Assigned staff</th>
        <th>Start date</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($loans as $loan): ?>
        <tr>
            <td><?= Html::encode($loan->loan_number) ?></td>
            <td><?= Html::encode($loan->customer->full_name) ?></td>
            <td><span class="badge badge-<?= Html::encode($loan->status) ?>"><?= Html::encode($loan->status) ?></span></td>
            <td><?= Html::encode($loan->assignedStaff->full_name) ?></td>
            <td><?= Html::encode($loan->start_date) ?></td>
            <td><?= Html::a('View', ['loan/view', 'id' => $loan->id]) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
