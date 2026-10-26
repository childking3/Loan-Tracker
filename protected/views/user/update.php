<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */
/** @var bool $canManageAllRoles */

use app\models\User;
use yii\helpers\Html;

$this->title = 'Edit ' . $model->full_name;
?>
<div class="page-header-row">
    <h1>Edit Staff Account</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Staff', ['user/index'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm('', 'post', ['enctype' => 'multipart/form-data', 'class' => 'narrow-form']) ?>
    <?php if ($model->avatar_filename): ?>
        <div>
            <img class="avatar avatar-large" src="<?= Html::encode(\yii\helpers\Url::to(['avatar/view', 'id' => $model->id, 'v' => $model->avatar_filename])) ?>" alt="">
        </div>
    <?php endif; ?>
    <label>Profile picture
        <?= Html::fileInput('User[avatarFile]') ?>
    </label>
    <?php if ($model->avatar_filename): ?>
        <p class="muted field-hint">Leave blank to keep the current picture.</p>
    <?php endif; ?>
    <label><?= Html::encode($model->getAttributeLabel('full_name')) ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('username')) ?>
        <?= Html::activeTextInput($model, 'username') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('email')) ?>
        <?= Html::activeInput('email', $model, 'email') ?>
    </label>
    <label>New password
        <?= Html::activeInput('password', $model, 'password') ?>
    </label>
    <p class="muted field-hint">Leave blank to keep the current password.</p>
    <label>Role
        <?php if ($canManageAllRoles): ?>
            <?= Html::activeDropDownList($model, 'role', array_combine(User::ROLES, User::ROLES)) ?>
        <?php else: ?>
            <input type="text" value="Staff" disabled>
            <?= Html::activeHiddenInput($model, 'role', ['value' => User::ROLE_STAFF]) ?>
            <p class="muted field-hint-inset">As a manager, you can't change an account's role above staff - the server also enforces this regardless of what's submitted here.</p>
        <?php endif; ?>
    </label>
    <label>Status
        <?= Html::activeDropDownList($model, 'status', [
            User::STATUS_ACTIVE => 'Active',
            User::STATUS_INACTIVE => 'Deactivated',
        ]) ?>
    </label>

    <?= Html::submitButton('Save') ?>
<?= Html::endForm() ?>
