<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoginForm $model */

use yii\helpers\Html;

$this->title = 'Login';
?>
<div class="auth-page">
    <div class="auth-card">
        <h1>DMC <span class="brand-mark">SPARKLE SERVICES</span></h1>
        <span class="auth-subtitle">Loan Management System</span>

        <?php if ($model->hasErrors()): ?>
            <ul class="auth-error">
                <?php foreach ($model->getFirstErrors() as $error): ?>
                    <li><?= Html::encode($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?= Html::beginForm(['site/login'], 'post') ?>
            <label>Username
                <?= Html::textInput('LoginForm[username]', $model->username) ?>
            </label>
            <label>Password
                <?= Html::passwordInput('LoginForm[password]', $model->password) ?>
            </label>
            <?= Html::submitButton('Login') ?>
        <?= Html::endForm() ?>
    </div>
</div>
