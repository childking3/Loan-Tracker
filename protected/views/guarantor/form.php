<?php

/** @var \yii\web\View $this */
/** @var \app\models\Guarantor $model */
/** @var string $title */
/** @var array $backUrl */

use yii\helpers\Html;

$this->title = $title;
?>
<div class="page-header-row">
    <h1><?= Html::encode($title) ?></h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back', $backUrl, ['class' => 'btn-icon']) ?>
</div>

<?= Html::beginForm(null, 'post', ['class' => 'narrow-form']) ?>
    <?= $this->render('//_error_list', ['model' => $model]) ?>

    <label><?= Html::encode($model->getAttributeLabel('full_name')) ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('phone')) ?>
        <?= Html::activeInput('tel', $model, 'phone') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('relationship')) ?>
        <?= Html::activeTextInput($model, 'relationship', ['placeholder' => 'e.g. Brother, Employer, Colleague']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('occupation')) ?>
        <?= Html::activeTextInput($model, 'occupation') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('address')) ?>
        <?= Html::activeTextarea($model, 'address') ?>
    </label>

    <?= Html::submitButton('Save') ?>
<?= Html::endForm() ?>
