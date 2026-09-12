<?php

/** @var \yii\web\View $this */
/** @var \yii\data\ActiveDataProvider $dataProvider */
/** @var string $search */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Customers';
?>
<h1>Customers</h1>

<?= Html::beginForm(['customer/index'], 'get') ?>
    <?= Html::input('text', 'q', $search, ['placeholder' => 'Search by name or phone']) ?>
    <?= Html::submitButton('Search') ?>
<?= Html::endForm() ?>

<p>
    <?= Html::a('New customer', ['customer/create']) ?>
    <?= Html::a('Deleted customers', ['customer/trash']) ?>
</p>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Phone</th>
        <th>Address</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($dataProvider->getModels() as $customer): ?>
        <tr>
            <td><?= Html::encode($customer->full_name) ?></td>
            <td><?= Html::encode($customer->phone) ?></td>
            <td><?= Html::encode($customer->address) ?></td>
            <td><?= Html::a('View', ['customer/view', 'id' => $customer->id]) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
