<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $customer */
/** @var string $imgClass */
/** @var string $placeholderClass */

use app\helpers\Avatar;
use yii\helpers\Html;
use yii\helpers\Url;
?>
<?php if ($customer->photo_filename): ?>
    <img class="<?= Html::encode($imgClass) ?>" src="<?= Html::encode(Url::to(['customer/photo', 'id' => $customer->id, 'v' => $customer->photo_filename])) ?>" alt="">
<?php else: ?>
    <span class="<?= Html::encode($placeholderClass) ?>"><?= Avatar::initial($customer->full_name) ?></span>
<?php endif; ?>
