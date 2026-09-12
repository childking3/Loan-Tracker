<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */
/** @var string $exportUrl */
/** @var \yii\data\ActiveDataProvider|null $dataProvider set only on reports whose row count scales with business volume (customers, loans, repayments); the CSV export always covers every filtered row regardless. */

use yii\helpers\Html;
use yii\widgets\LinkPager;
?>
<p><?= Html::a('Export CSV (all matching rows)', $exportUrl) ?></p>
<div class="table-scroll">
<table>
    <thead>
    <tr>
        <?php foreach ($header as $column): ?>
            <th><?= Html::encode($column) ?></th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($row as $cell): ?>
                <td><?= Html::encode($cell) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if (isset($dataProvider)): ?>
    <?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
<?php endif; ?>
