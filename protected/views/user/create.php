<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */

use app\models\User;
use yii\helpers\Html;

$this->title = 'New staff account';
?>
<h1>New staff account</h1>

<?php foreach ($model->getErrors() as $errors): ?>
    <?php foreach ($errors as $error): ?>
        <div><?= Html::encode($error) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?= Html::beginForm('', 'post', ['enctype' => 'multipart/form-data']) ?>
    <div>
        <?= Html::activeLabel($model, 'full_name') ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </div>
    <div>
        <label>
            Profile picture
            <?= Html::fileInput('User[avatarFile]') ?>
        </label>
    </div>
    <div>
        <?= Html::activeLabel($model, 'username') ?>
        <?= Html::activeTextInput($model, 'username') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'email') ?>
        <?= Html::activeInput('email', $model, 'email') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'password') ?>
        <?= Html::activeInput('password', $model, 'password') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'role') ?>
        <?= Html::activeDropDownList($model, 'role', array_combine(User::ROLES, User::ROLES)) ?>
    </div>

    <div>
        <?= Html::submitButton('Create account') ?>
    </div>
<?= Html::endForm() ?>
