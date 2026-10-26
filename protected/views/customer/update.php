<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var array $marketOptions */
/** @var array $ownerOptions staff id => name; empty when the viewer may not reassign */

use yii\helpers\Html;

$this->title = 'Edit ' . $model->full_name;
?>
<div class="page-header-row">
    <h1>Edit Customer</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Customer', ['customer/view', 'id' => $model->id], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('_form', ['model' => $model, 'marketOptions' => $marketOptions, 'ownerOptions' => $ownerOptions]) ?>
