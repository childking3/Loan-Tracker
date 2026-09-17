<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market[] $markets */
/** @var array $customerCounts market_id => count */

use app\helpers\Badge;
use yii\helpers\Html;

$this->title = 'Markets';
?>
<div class="page-header-row">
    <h1>Markets</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> New Market', ['market/create'], ['class' => 'btn-icon']) ?>
</div>
<p class="muted">Each staff account is assigned to one market. When a staff member registers a customer, the customer's market is recorded so activity can be tracked per market.</p>

<?= $this->render('//_flashes') ?>

<p><?= Html::a('Deleted markets', ['market/trash'], ['class' => 'view-all-link']) ?></p>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>Market</th>
        <th>Area</th>
        <th>Assigned staff</th>
        <th>Customers</th>
        <th>Active</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($markets as $market): ?>
        <tr>
            <td><?= Html::encode($market->name) ?></td>
            <td><?= Html::encode($market->area ?? '') ?></td>
            <td>
                <?php if ($market->assignedStaff !== null): ?>
                    <?= Html::encode($market->assignedStaff->full_name) ?>
                <?php else: ?>
                    <span class="muted">&mdash; Unassigned</span>
                <?php endif; ?>
            </td>
            <td><?= (int) ($customerCounts[$market->id] ?? 0) ?></td>
            <td><?= Badge::yesNo($market->is_active) ?></td>
            <td>
                <?= Html::a('Edit', ['market/update', 'id' => $market->id]) ?>
                <?= Html::beginForm(['market/delete', 'id' => $market->id], 'post', ['class' => 'inline-form', 'style' => 'display:inline']) ?>
                    <?= Html::submitButton('Delete', ['onclick' => "return confirm('Delete this market?');"]) ?>
                <?= Html::endForm() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
