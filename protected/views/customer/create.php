<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var \app\models\Guarantor $guarantor */
/** @var \app\models\Customer[] $duplicates */
/** @var array $marketOptions */

use yii\helpers\Html;

$this->title = 'Register New Customer';
?>
<div class="page-header-row">
    <h1>Register New Customer</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Back to Customers', ['customer/index'], ['class' => 'btn-icon']) ?>
</div>

<?php if ($duplicates !== []): ?>
    <div>
        <p>Warning: <?= count($duplicates) ?> existing customer(s) already use this phone number:</p>
        <ul>
            <?php foreach ($duplicates as $duplicate): ?>
                <li><?= Html::a(Html::encode($duplicate->full_name), ['customer/view', 'id' => $duplicate->id]) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Submitting again will create this customer anyway.</p>
    </div>
<?php endif; ?>

<?= $this->render('_form', [
    'model' => $model,
    'guarantor' => $guarantor,
    'marketOptions' => $marketOptions,
    'confirmDuplicate' => $duplicates !== [],
]) ?>
