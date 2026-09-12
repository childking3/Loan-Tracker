<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */
/** @var array $filters */
/** @var \yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Repayment report';
?>
<h1>Repayment report</h1>

<?= Html::beginForm(['report/repayments'], 'get') ?>
    <label>From <?= Html::input('date', 'from', $filters['from']) ?></label>
    <label>To <?= Html::input('date', 'to', $filters['to']) ?></label>
    <?= Html::submitButton('Filter') ?>
<?= Html::endForm() ?>

<?= $this->render('_table', ['header' => $header, 'rows' => $rows, 'dataProvider' => $dataProvider, 'exportUrl' => Url::current(['export' => 'csv'])]) ?>
