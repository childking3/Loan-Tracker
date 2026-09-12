<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */
/** @var \yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Url;

$this->title = 'Overdue loans report';
?>
<h1>Overdue loans report</h1>
<?= $this->render('_table', ['header' => $header, 'rows' => $rows, 'dataProvider' => $dataProvider, 'exportUrl' => Url::current(['export' => 'csv'])]) ?>
