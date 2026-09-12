<?php

/** @var \yii\web\View $this */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Loan Tracker';
?>
<div class="auth-page">
    <div class="auth-card">
        <h1>Loan Tracker</h1>
        <p class="muted">Sign in to manage customers, loans, and repayments.</p>
        <?= Html::a('Login', ['site/login'], ['class' => 'btn']) ?>
    </div>
</div>
