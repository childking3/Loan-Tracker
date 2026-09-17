<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */
/** @var bool $canManageAllRoles */

use app\models\User;
use yii\helpers\Html;

$this->title = 'New staff account';
?>
<div class="page-header-row">
    <h1>New Staff Account</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Staff', ['user/index'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('//_error_list', ['model' => $model]) ?>

<?= Html::beginForm('', 'post', ['enctype' => 'multipart/form-data', 'style' => 'max-width: 480px;']) ?>
    <label><?= Html::encode($model->getAttributeLabel('full_name')) ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </label>
    <label>Profile picture
        <?= Html::fileInput('User[avatarFile]') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('username')) ?>
        <?= Html::activeTextInput($model, 'username') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('email')) ?>
        <?= Html::activeInput('email', $model, 'email') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('password')) ?>
        <?= Html::activeInput('password', $model, 'password') ?>
    </label>
    <label>Role
        <?php if ($canManageAllRoles): ?>
            <?= Html::activeDropDownList($model, 'role', array_combine(User::ROLES, User::ROLES)) ?>
        <?php else: ?>
            <input type="text" value="Staff" disabled>
            <?= Html::activeHiddenInput($model, 'role', ['value' => User::ROLE_STAFF]) ?>
            <p class="muted" style="margin-top: 0.3rem;">As a manager, new accounts you create are always staff-role - the server also enforces this regardless of what's submitted here.</p>
        <?php endif; ?>
    </label>

    <?= Html::submitButton('Create account') ?>
<?= Html::endForm() ?>
