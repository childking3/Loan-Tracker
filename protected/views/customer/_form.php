<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var bool $confirmDuplicate */
/** @var array $marketOptions market id => name, active markets only */
/** @var array|null $ownerOptions staff id => name - the customer's assigned staff member. Empty/absent for anyone who may not reassign (staff), which is what keeps the control off their form; CustomerController::actionUpdate() is what actually enforces it. */
/** @var \app\models\Guarantor|null $guarantor set only on create - the baseline guarantor is captured here at registration; afterward it is edited through guarantor/update (linked from customer/view.php), not this form */
/** @var \app\models\Loan|null $loan set only on create - assigning a package here creates the customer's first loan in the same submit, same optional-sub-form shape as $guarantor */
/** @var array|null $packageOptions required when $loan is set */
/** @var array|null $staffOptions required when $loan is set */

use yii\helpers\Html;

$confirmDuplicate = $confirmDuplicate ?? false;
$guarantor = $guarantor ?? null;
$loan = $loan ?? null;
$ownerOptions = $ownerOptions ?? [];
?>
<?= Html::beginForm(null, 'post', ['class' => 'narrow-form', 'enctype' => 'multipart/form-data']) ?>
    <?= $this->render('//_error_list', ['model' => $model]) ?>

    <label><?= Html::encode($model->getAttributeLabel('full_name')) ?>
        <?= Html::activeTextInput($model, 'full_name') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('phone')) ?>
        <?= Html::activeTextInput($model, 'phone') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('gender')) ?>
        <?= Html::activeDropDownList($model, 'gender', \app\models\Customer::GENDERS, ['prompt' => 'Not specified']) ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('address')) ?>
        <?= Html::activeTextarea($model, 'address') ?>
    </label>
    <label><?= Html::encode($model->getAttributeLabel('photoFile')) ?> <span class="muted">(optional)</span>
        <?= Html::fileInput('Customer[photoFile]', null, ['id' => 'customer-photofile', 'accept' => 'image/jpeg,image/png,image/webp']) ?>
    </label>
    <?php if (!$model->isNewRecord && $model->photo_filename): ?>
        <div class="photo-edit-row">
            <?= $this->render('_photo', ['customer' => $model, 'imgClass' => 'avatar record-avatar', 'placeholderClass' => 'record-avatar']) ?>
            <label class="checkbox-label">
                <?= Html::activeCheckbox($model, 'removePhoto', ['label' => null]) ?>
                <span><?= Html::encode($model->getAttributeLabel('removePhoto')) ?></span>
            </label>
        </div>
    <?php endif; ?>
    <label><?= Html::encode($model->getAttributeLabel('market_id')) ?>
        <?= Html::activeDropDownList($model, 'market_id', $marketOptions, ['prompt' => 'Select market…']) ?>
    </label>
    <?php if ($ownerOptions !== []): ?>
        <label>Assigned staff
            <?= Html::activeDropDownList($model, 'assigned_staff_id', $ownerOptions, ['prompt' => 'Unassigned']) ?>
        </label>
        <p class="muted field-hint">Who this customer belongs to. Only this staff member sees them; unassigned customers are visible to admins and managers only.</p>
    <?php endif; ?>
    <?php if ($guarantor !== null): ?>
        <p class="muted field-hint">Defaults to your assigned market - change it if this customer is from somewhere else.</p>
    <?php endif; ?>

    <?php if ($guarantor !== null): ?>
        <h2 class="section-heading">Guarantor</h2>
        <p class="muted field-hint">Optional - leave blank to skip, or fill in all three fields to record one now.</p>
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

    <?php if ($loan !== null): ?>
        <h2 class="section-heading">Loan</h2>
        <p class="muted field-hint">Optional - leave the package on "Select a package" to just register the customer, or pick one to also create their first loan now.</p>
        <?= $this->render('//_error_list', ['model' => $loan]) ?>
        <label>Loan package
            <?= Html::activeDropDownList($loan, 'package_id', $packageOptions, ['prompt' => 'Select a package']) ?>
        </label>
        <label>Start date
            <?= Html::activeInput('date', $loan, 'start_date') ?>
        </label>
        <label>Assign to staff
            <?= Html::activeDropDownList($loan, 'assigned_staff_id', $staffOptions, ['prompt' => 'Select a staff member']) ?>
        </label>
    <?php endif; ?>

    <?php if ($confirmDuplicate): ?>
        <input type="hidden" name="confirmDuplicate" value="1">
    <?php endif; ?>

    <?= Html::submitButton($model->isNewRecord ? 'Save Customer' : 'Save') ?>
<?= Html::endForm() ?>
