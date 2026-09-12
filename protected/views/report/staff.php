<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */

use yii\helpers\Url;

$this->title = 'Staff performance report';
?>
<h1>Staff performance report</h1>
<?= $this->render('_table', ['header' => $header, 'rows' => $rows, 'exportUrl' => Url::current(['export' => 'csv'])]) ?>
