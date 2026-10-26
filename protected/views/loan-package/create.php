<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoanPackage $model */

use yii\helpers\Html;

$this->title = 'New loan package';
?>
<div class="page-header-row">
    <h1>New loan package</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Loan Packages', ['loan-package/index'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('_form', ['model' => $model]) ?>
