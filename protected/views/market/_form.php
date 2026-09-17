<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market $model */
/** @var array $staffOptions user id => full name, staff-role + active only */

use yii\helpers\Html;
?>
<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm(null, 'post', ['style' => 'max-width: 480px;']) ?>
    <label><?= Html::encode($model->getAttributeLabel('name')) ?>
        <?= Html::activeTextInput($model, 'name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('area')) ?>
        <?= Html::activeTextInput($model, 'area') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('assigned_staff_id')) ?>
        <?= Html::activeDropDownList($model, 'assigned_staff_id', $staffOptions, ['prompt' => '— Unassigned —']) ?>
    </label>
    <label class="checkbox-label">
        <?= Html::activeCheckbox($model, 'is_active', ['label' => null]) ?>
        <span><?= Html::encode($model->getAttributeLabel('is_active')) ?></span>
    </label>
    <?= Html::submitButton('Save Market') ?>
<?= Html::endForm() ?>
