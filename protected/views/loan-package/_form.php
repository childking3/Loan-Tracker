<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage $model */

use yii\helpers\Html;
?>
<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm(null, 'post', ['class' => 'narrow-form']) ?>
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
    <p class="muted">Weekdays only - Saturdays and Sundays don't count towards the period.</p>
    <?= $this->render('//_is_active_checkbox', ['model' => $model]) ?>
    <?php if (!$model->isNewRecord): ?>
        <p class="muted">
            Changes here only apply to loans created after saving - existing
            loans keep the terms they were issued with (see Loan::applyPackageTerms,
            which copies these figures at creation time and never re-reads them).
        </p>
    <?php endif; ?>

    <?= Html::submitButton($model->isNewRecord ? 'Create package' : 'Save') ?>
<?= Html::endForm() ?>
