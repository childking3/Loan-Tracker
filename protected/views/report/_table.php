<?php

/** @var \yii\web\View $this */
/** @var array $header */
/** @var array $rows */
/** @var string $exportUrl */

use yii\helpers\Html;
?>
<p><?= Html::a('Export CSV', $exportUrl) ?></p>
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
