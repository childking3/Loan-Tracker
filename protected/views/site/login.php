<?php

/** @var \yii\web\View $this */
/** @var \app\models\LoginForm $model */

use yii\helpers\Html;

$this->title = 'Login';
?>
<div class="auth-page">
    <div class="auth-card">
        <h1>Loan Tracker</h1>

        <?php if ($model->hasErrors()): ?>
            <ul class="auth-error">
                <?php foreach ($model->getFirstErrors() as $error): ?>
                    <li><?= Html::encode($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?= Html::beginForm(['site/login'], 'post') ?>
            <div>
                <?= Html::label('Username', 'loginform-username') ?>
                <?= Html::textInput('LoginForm[username]', $model->username, ['id' => 'loginform-username']) ?>
            </div>
            <div>
                <?= Html::label('Password', 'loginform-password') ?>
                <?= Html::passwordInput('LoginForm[password]', $model->password, ['id' => 'loginform-password']) ?>
            </div>
            <div>
                <?= Html::submitButton('Login') ?>
            </div>
        <?= Html::endForm() ?>
    </div>
</div>
