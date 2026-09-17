<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage $model */

use yii\helpers\Html;

$this->title = 'Edit ' . $model->name;
?>
<h1>Edit loan package</h1>

<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm() ?>
    <label><?= Html::encode($model->getAttributeLabel('name')) ?>
        <?= Html::activeTextInput($model, 'name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('loan_amount')) ?>
        <?= Html::activeInput('number', $model, 'loan_amount', ['step' => '0.01']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('total_repayment')) ?>
        <?= Html::activeInput('number', $model, 'total_repayment', ['step' => '0.01']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('daily_payment')) ?>
        <?= Html::activeInput('number', $model, 'daily_payment', ['step' => '0.01']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('repayment_period_days')) ?>
        <?= Html::activeInput('number', $model, 'repayment_period_days', ['step' => '1']) ?>
    </label>
    <label class="checkbox-label">
        <?= Html::activeCheckbox($model, 'is_active', ['label' => null]) ?>
        <span><?= Html::encode($model->getAttributeLabel('is_active')) ?></span>
    </label>
    <p class="muted">
        Changes here only apply to loans created after saving - existing
        loans keep the terms they were issued with (see Loan::applyPackageTerms,
        which copies these figures at creation time and never re-reads them).
    </p>

    <?= Html::submitButton('Save') ?>
<?= Html::endForm() ?>
