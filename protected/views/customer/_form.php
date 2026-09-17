<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var bool $confirmDuplicate */
/** @var array $marketOptions market id => name, active markets only */
/** @var \app\models\Guarantor|null $guarantor set only on create - the baseline guarantor is captured once at registration, not editable afterward (see customer/view.php, which shows it read-only) */

use yii\helpers\Html;

$confirmDuplicate = $confirmDuplicate ?? false;
$guarantor = $guarantor ?? null;
?>
<?= Html::beginForm(null, 'post', ['style' => 'max-width: 480px;']) ?>
    <?= $this->render('//_error_list', ['model' => $model]) ?>

    <label><?= Html::encode($model->getAttributeLabel('full_name')) ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('phone')) ?>
        <?= Html::activeTextInput($model, 'phone') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('address')) ?>
        <?= Html::activeTextarea($model, 'address') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('market_id')) ?>
        <?= Html::activeDropDownList($model, 'market_id', $marketOptions, ['prompt' => 'Select market…']) ?>
    </label>
    <?php if ($guarantor !== null): ?>
        <p class="muted" style="margin-top: -0.6rem;">Defaults to your assigned market - change it if this customer is from somewhere else.</p>
    <?php endif; ?>

    <?php if ($guarantor !== null): ?>
        <h2 style="margin-top:1.5rem;">Guarantor</h2>
        <p class="muted" style="margin-top: -0.6rem;">Optional - leave blank to skip, or fill in all three fields to record one now.</p>
        <?= $this->render('//_error_list', ['model' => $guarantor]) ?>
        <label>Guarantor's name
            <?= Html::activeTextInput($guarantor, 'full_name') ?>
        </label>
        <label>Guarantor's phone
            <?= Html::activeTextInput($guarantor, 'phone') ?>
        </label>
        <label>Guarantor's address
            <?= Html::activeTextarea($guarantor, 'address') ?>
        </label>
    <?php endif; ?>

    <?php if ($confirmDuplicate): ?>
        <input type="hidden" name="confirmDuplicate" value="1">
    <?php endif; ?>

    <?= Html::submitButton($model->isNewRecord ? 'Save Customer' : 'Save') ?>
<?= Html::endForm() ?>
