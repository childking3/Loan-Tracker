<?php

/** @var \yii\web\View $this */

use yii\helpers\Html;

$this->title = 'Reports';
?>
<h1>Reports</h1>
<ul>
    <li><?= Html::a('Customers', ['report/customers']) ?></li>
    <li><?= Html::a('Loans', ['report/loans']) ?></li>
    <li><?= Html::a('Repayments', ['report/repayments']) ?></li>
    <li><?= Html::a('Outstanding balances', ['report/outstanding']) ?></li>
    <li><?= Html::a('Overdue loans', ['report/overdue']) ?></li>
    <li><?= Html::a('Daily collections', ['report/daily-collections']) ?></li>
</ul>
