<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'My account';
?>
<h1>My account</h1>

<?= $this->render('//_flashes') ?>

<?= $this->render('//_avatar', ['model' => $model, 'imgClass' => 'avatar avatar-large', 'placeholderClass' => 'avatar avatar-large avatar-placeholder']) ?>

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
