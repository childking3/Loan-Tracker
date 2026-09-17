<?php

/** @var \yii\web\View $this */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'DMC Sparkle Services';
?>
<div class="auth-page">
    <div class="auth-card">
        <h1>DMC <span class="brand-mark">SPARKLE SERVICES</span></h1>
        <p class="muted">Sign in to manage customers, loans, and repayments.</p>
        <?= Html::a('Login', ['site/login'], ['class' => 'btn']) ?>
    </div>
</div>
