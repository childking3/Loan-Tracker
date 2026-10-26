<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market $model */
/** @var array $staffOptions user id => full name, staff-role + active only */

use yii\helpers\Html;
?>
<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm(null, 'post', ['class' => 'narrow-form']) ?>
    <label><?= Html::encode($model->getAttributeLabel('name')) ?>
        <?= Html::activeTextInput($model, 'name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('area')) ?>
        <?= Html::activeTextInput($model, 'area') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('assigned_staff_id')) ?>
        <?= Html::activeDropDownList($model, 'assigned_staff_id', $staffOptions, ['prompt' => '— Unassigned —']) ?>
    </label>
    <?= $this->render('//_is_active_checkbox', ['model' => $model]) ?>
    <?= Html::submitButton('Save Market') ?>
<?= Html::endForm() ?>
