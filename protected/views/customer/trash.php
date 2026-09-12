<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer[] $customers */

use yii\helpers\Html;

$this->title = 'Deleted customers';
?>
<h1>Deleted customers</h1>

<?= $this->render('//_flashes') ?>

<p><?= Html::a('Back to customers', ['customer/index']) ?></p>

<?php if ($customers === []): ?>
    <p>No deleted customers.</p>
<?php else: ?>
<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Phone</th>
        <th>Deleted</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($customers as $customer): ?>
        <tr>
            <td><?= Html::encode($customer->full_name) ?></td>
            <td><?= Html::encode($customer->phone) ?></td>
            <td><?= Html::encode(date('Y-m-d H:i', $customer->deleted_at)) ?></td>
            <td>
                <?= Html::beginForm(['customer/restore', 'id' => $customer->id], 'post', ['class' => 'inline-form']) ?>
                    <?= Html::submitButton('Restore') ?>
                <?= Html::endForm() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
