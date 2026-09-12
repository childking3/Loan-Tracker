<?php

/** @var \yii\web\View $this */
/** @var \app\models\Loan $model */
/** @var \app\models\Customer $customer */
/** @var array $packageOptions */
/** @var array $staffOptions */

use yii\helpers\Html;

$this->title = 'New loan for ' . $customer->full_name;
?>
<h1>New loan for <?= Html::encode($customer->full_name) ?></h1>

<?php foreach ($model->getErrors() as $errors): ?>
    <?php foreach ($errors as $error): ?>
        <div><?= Html::encode($error) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?= Html::beginForm() ?>
    <?= Html::hiddenInput('Loan[customer_id]', $customer->id) ?>

    <div>
        <?= Html::activeLabel($model, 'package_id', ['label' => 'Loan package']) ?>
        <?= Html::activeDropDownList($model, 'package_id', $packageOptions, ['prompt' => 'Select a package']) ?>
    </div>

    <div>
        <?= Html::activeLabel($model, 'start_date', ['label' => 'Start date']) ?>
        <?= Html::activeInput('date', $model, 'start_date') ?>
    </div>

    <div>
        <?= Html::activeLabel($model, 'assigned_staff_id', ['label' => 'Assign to staff']) ?>
        <?= Html::activeDropDownList($model, 'assigned_staff_id', $staffOptions, ['prompt' => 'Select a staff member']) ?>
    </div>

    <p>
        Principal amount, total repayment, daily payment and expected
        completion date are set automatically from the selected package and
        start date - they are not entered manually.
    </p>

    <div>
        <?= Html::submitButton('Create loan') ?>
    </div>
<?= Html::endForm() ?>
