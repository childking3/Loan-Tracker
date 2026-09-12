<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var bool $confirmDuplicate */

use yii\helpers\Html;

$confirmDuplicate = $confirmDuplicate ?? false;
?>
<?= Html::beginForm() ?>
    <?php foreach ($model->getErrors() as $errors): ?>
        <?php foreach ($errors as $error): ?>
            <div><?= Html::encode($error) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div>
        <?= Html::activeLabel($model, 'full_name') ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'phone') ?>
        <?= Html::activeTextInput($model, 'phone') ?>
    </div>
    <div>
        <?= Html::activeLabel($model, 'address') ?>
        <?= Html::activeTextarea($model, 'address') ?>
    </div>

    <?php if ($confirmDuplicate): ?>
        <input type="hidden" name="confirmDuplicate" value="1">
    <?php endif; ?>

    <div>
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save') ?>
    </div>
<?= Html::endForm() ?>
