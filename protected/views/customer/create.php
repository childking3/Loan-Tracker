<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var \app\models\Customer[] $duplicates */

use yii\helpers\Html;

$this->title = 'New customer';
?>
<h1>New customer</h1>

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
    'confirmDuplicate' => $duplicates !== [],
]) ?>
