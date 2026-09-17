<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market $model */
/** @var array $staffOptions */

use yii\helpers\Html;

$this->title = 'New Market';
?>
<div class="page-header-row">
    <h1>New Market</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Markets', ['market/index'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('_form', ['model' => $model, 'staffOptions' => $staffOptions]) ?>
