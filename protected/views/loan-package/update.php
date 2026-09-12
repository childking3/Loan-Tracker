<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage $model */

use yii\helpers\Html;

$this->title = 'Edit ' . $model->name;
?>
<h1>Edit loan package</h1>

<?php foreach ($model->getErrors() as $errors): ?>
    <?php foreach ($errors as $error): ?>
        <div><?= Html::encode($error) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?= Html::beginForm() ?>
    <div>
        <?= Html::activeLabel($model, 'name') ?>
        <?= Html::activeTextInput($model, 'name') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'loan_amount') ?>
        <?= Html::activeInput('number', $model, 'loan_amount', ['step' => '0.01']) ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'total_repayment') ?>
        <?= Html::activeInput('number', $model, 'total_repayment', ['step' => '0.01']) ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'daily_payment') ?>
        <?= Html::activeInput('number', $model, 'daily_payment', ['step' => '0.01']) ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'repayment_period_days') ?>
        <?= Html::activeInput('number', $model, 'repayment_period_days', ['step' => '1']) ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'is_active') ?>
        <?= Html::activeCheckbox($model, 'is_active', ['label' => null]) ?>
    </div>
    <p>
        Changes here only apply to loans created after saving - existing
        loans keep the terms they were issued with (see Loan::applyPackageTerms,
        which copies these figures at creation time and never re-reads them).
    </p>

    <div>
        <?= Html::submitButton('Save') ?>
    </div>
<?= Html::endForm() ?>
