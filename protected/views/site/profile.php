<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'My account';
?>
<h1>My account</h1>

<?= $this->render('//_flashes') ?>

<?php if ($model->avatar_filename): ?>
    <img class="avatar avatar-large" src="<?= Html::encode(Url::to(['avatar/view', 'id' => $model->id, 'v' => $model->avatar_filename])) ?>" alt="">
<?php else: ?>
    <span class="avatar avatar-large avatar-placeholder"><?= Html::encode(mb_strtoupper(mb_substr($model->full_name, 0, 1))) ?></span>
<?php endif; ?>

<p>Username: <?= Html::encode($model->username) ?></p>
<p>Full name: <?= Html::encode($model->full_name) ?></p>
<p>Email: <?= Html::encode($model->email) ?></p>

<?= Html::beginForm('', 'post', ['enctype' => 'multipart/form-data']) ?>
    <div>
        <label>
            Profile picture
            <?= Html::fileInput('User[avatarFile]') ?>
        </label>
    </div>
    <?= Html::submitButton('Upload') ?>
<?= Html::endForm() ?>
