<?php

/** @var \yii\web\View $this */
/** @var \yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Deleted markets';
?>
<h1>Deleted markets</h1>

<?= $this->render('//_flashes') ?>

<p><?= Html::a('Back to markets', ['market/index']) ?></p>

<?php if ($dataProvider->getTotalCount() === 0): ?>
    <p>No deleted markets.</p>
<?php else: ?>
<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Market</th>
        <th>Area</th>
        <th>Deleted</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($dataProvider->getModels() as $market): ?>
        <tr>
            <td><?= Html::encode($market->name) ?></td>
            <td><?= Html::encode($market->area ?? '') ?></td>
            <td><?= Html::encode(date('Y-m-d H:i', $market->deleted_at)) ?></td>
            <td>
                <?= Html::beginForm(['market/restore', 'id' => $market->id], 'post', ['class' => 'inline-form']) ?>
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
