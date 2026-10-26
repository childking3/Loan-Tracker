<?php

/** @var \yii\web\View $this */
/** @var \app\models\Repayment $model */
/** @var \app\models\Loan $loan */

use yii\helpers\Html;

$this->title = 'Edit repayment';
?>
<div class="page-header-row">
    <h1><?= Html::encode($this->title) ?></h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back', ['loan/view', 'id' => $loan->id], ['class' => 'btn-icon']) ?>
</div>

<p>Loan <?= Html::encode($loan->loan_number) ?>. This change is recorded in the audit log, with
    the previous amount and date kept alongside the new ones - it is a correction, not a
    replacement of history.</p>

<?= Html::beginForm(null, 'post', ['class' => 'narrow-form']) ?>
    <?= $this->render('//_error_list', ['model' => $model]) ?>

    <label><?= Html::encode($model->getAttributeLabel('amount')) ?>
        <?= Html::activeInput('number', $model, 'amount', ['step' => '0.01', 'min' => '0.01']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('payment_date')) ?>
        <?= Html::activeInput('date', $model, 'payment_date', ['max' => date('Y-m-d')]) ?>
    </label>

    <?= Html::submitButton('Save correction') ?>
<?= Html::endForm() ?>
