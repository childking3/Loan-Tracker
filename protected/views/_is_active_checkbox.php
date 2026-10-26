<?php

/** @var \yii\web\View $this */
/** @var \yii\base\Model $model must have an is_active attribute */

use yii\helpers\Html;
?>
<label class="checkbox-label">
    <?= Html::activeCheckbox($model, 'is_active', ['label' => null]) ?>
    <span><?= Html::encode($model->getAttributeLabel('is_active')) ?></span>
</label>
