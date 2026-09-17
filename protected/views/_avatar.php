<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $model */
/** @var string $imgClass */
/** @var string $placeholderClass */

use app\helpers\Avatar;
use yii\helpers\Html;
use yii\helpers\Url;
?>
<?php if ($model->avatar_filename): ?>
    <img class="<?= Html::encode($imgClass) ?>" src="<?= Html::encode(Url::to(['avatar/view', 'id' => $model->id, 'v' => $model->avatar_filename])) ?>" alt="">
<?php else: ?>
    <span class="<?= Html::encode($placeholderClass) ?>"><?= Avatar::initial($model->full_name) ?></span>
<?php endif; ?>
