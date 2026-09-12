<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */
/** @var array $filters */
/** @var \yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Loan report';
?>
<h1>Loan report</h1>

<?= Html::beginForm(['report/loans'], 'get') ?>
    <label>
        Status
        <?= Html::dropDownList('status', $filters['status'], [
            '' => 'All',
            'active' => 'Active',
            'completed' => 'Completed',
            'overdue' => 'Overdue',
            'cancelled' => 'Cancelled',
        ]) ?>
    </label>
    <?= Html::submitButton('Filter') ?>
<?= Html::endForm() ?>

<?= $this->render('_table', ['header' => $header, 'rows' => $rows, 'dataProvider' => $dataProvider, 'exportUrl' => Url::current(['export' => 'csv'])]) ?>
