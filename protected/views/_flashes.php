<?php

/** @var \yii\web\View $this */

use yii\helpers\Html;

foreach (['success', 'error'] as $type) {
    if (\Yii::$app->session->hasFlash($type)) {
        echo Html::tag('div', Html::encode(\Yii::$app->session->getFlash($type)), ['class' => "flash-{$type}"]);
    }
}
