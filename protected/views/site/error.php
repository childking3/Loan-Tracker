<?php

/** @var \yii\web\View $this */
/** @var \Throwable $exception */
/** @var string $message */

use yii\helpers\Html;

$this->title = 'Error';
?>
<h1>An error occurred</h1>
<p><?= Html::encode($message) ?></p>
