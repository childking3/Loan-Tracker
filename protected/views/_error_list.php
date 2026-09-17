<?php

/** @var \yii\web\View $this */
/** @var \yii\base\Model $model */

use yii\helpers\Html;

foreach ($model->getErrors() as $errors) {
    foreach ($errors as $error) {
        echo Html::tag('div', Html::encode($error));
    }
}
