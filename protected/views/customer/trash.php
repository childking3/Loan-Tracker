<?php

/** @var \yii\web\View $this */
/** @var \yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Deleted customers';
?>
<h1>Deleted customers</h1>

<?= $this->render('//_flashes') ?>

<p><?= Html::a('Back to customers', ['customer/index']) ?></p>

<?php if ($dataProvider->getTotalCount() === 0): ?>
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
    <?php foreach ($dataProvider->getModels() as $customer): ?>
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

<?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
<?php endif; ?>
