<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */

use app\models\User;
use yii\helpers\Html;

$this->title = 'Edit ' . $model->full_name;
?>
<h1>Edit staff account</h1>

<?php foreach ($model->getErrors() as $errors): ?>
    <?php foreach ($errors as $error): ?>
        <div><?= Html::encode($error) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?= Html::beginForm('', 'post', ['enctype' => 'multipart/form-data']) ?>
    <?php if ($model->avatar_filename): ?>
        <div>
            <img class="avatar avatar-large" src="<?= Html::encode(\yii\helpers\Url::to(['avatar/view', 'id' => $model->id, 'v' => $model->avatar_filename])) ?>" alt="">
        </div>
    <?php endif; ?>
    <div>
        <label>
            Profile picture
            <?= Html::fileInput('User[avatarFile]') ?>
        </label>
        <?php if ($model->avatar_filename): ?>
            <p>Leave blank to keep the current picture.</p>
        <?php endif; ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'full_name') ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
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
        <?= Html::activeLabel($model, 'password', ['label' => 'New password']) ?>
        <?= Html::activeInput('password', $model, 'password') ?>
        <p>Leave blank to keep the current password.</p>
    </div>
    <div>
        <?= Html::activeLabel($model, 'role') ?>
        <?= Html::activeDropDownList($model, 'role', array_combine(User::ROLES, User::ROLES)) ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'status') ?>
        <?= Html::activeDropDownList($model, 'status', [
            User::STATUS_ACTIVE => 'Active',
            User::STATUS_INACTIVE => 'Deactivated',
        ]) ?>
    </div>

    <div>
        <?= Html::submitButton('Save') ?>
    </div>
<?= Html::endForm() ?>
