<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan $model */
/** @var array $packageOptions */
/** @var array $staffOptions */
/** @var bool $hasRepayments */

use yii\helpers\Html;

$this->title = 'Edit ' . $model->loan_number;
?>
<div class="page-header-row">
    <h1>Edit <?= Html::encode($model->loan_number) ?></h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to loan', ['loan/view', 'id' => $model->id], ['class' => 'btn-icon']) ?>
</div>

<p>Customer: <?= Html::a(Html::encode($model->customer->full_name), ['customer/view', 'id' => $model->customer_id]) ?></p>

<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm(null, 'post', ['class' => 'narrow-form']) ?>
    <label>Loan package
        <?= Html::activeDropDownList($model, 'package_id', $packageOptions, ['disabled' => $hasRepayments]) ?>
    </label>
    <?php if ($hasRepayments): ?>
        <p class="muted">The package is locked because a repayment has already been recorded on this loan.</p>
    <?php endif; ?>

    <label>Start date
        <?= Html::activeInput('date', $model, 'start_date') ?>
    </label>

    <label>Assigned staff
        <?= Html::activeDropDownList($model, 'assigned_staff_id', $staffOptions, ['prompt' => 'Select a staff member']) ?>
    </label>

    <p class="muted">
        Principal, total repayment and daily payment come from the package.
        The expected completion date follows the start date and skips
        Saturdays and Sundays.
    </p>

    <?= Html::submitButton('Save') ?>
<?= Html::endForm() ?>
