<?php

/** @var \yii\web\View $this */
/** @var \app\models\Market[] $markets */
/** @var array $customerCounts market_id => count */
/** @var string $filter 'active' | 'deleted' | 'all' */

use app\helpers\Badge;
use yii\helpers\Html;

$this->title = 'Markets';
?>
<div class="page-header-row">
    <h1>Markets</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> New Market', ['market/create'], ['class' => 'btn-icon']) ?>
</div>
<p class="muted">A staff account can be assigned to one or more markets. When a staff member registers a customer, the customer's market is recorded so activity can be tracked per market.</p>

<?= $this->render('//_flashes') ?>

<?= $this->render('//_record_tabs', ['filter' => $filter]) ?>

<?php if ($markets === []): ?>
    <p class="muted">No markets to show.</p>
<?php else: ?>
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
        <?php $isDeleted = $market->deleted_at !== null; ?>
        <tr class="<?= $isDeleted ? 'is-deleted' : '' ?>">
            <td>
                <?php if ($isDeleted): ?>
                    <?= Html::encode($market->name) ?>
                    <span class="badge badge-deleted badge-inline">Deleted</span>
                <?php else: ?>
                    <?= Html::a(Html::encode($market->name), ['market/view', 'id' => $market->id]) ?>
                <?php endif; ?>
            </td>
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
                <?php if ($isDeleted): ?>
                    <?php // Restore instead of Edit/Delete: market/update 404s on a
                          // trashed row. Restore is POST + manageMarkets-gated. ?>
                    <?= Html::beginForm(['market/restore', 'id' => $market->id], 'post', ['class' => 'inline-form']) ?>
                        <?= Html::submitButton('Restore', ['class' => 'btn-restore']) ?>
                    <?= Html::endForm() ?>
                <?php else: ?>
                    <?= Html::a('Edit', ['market/update', 'id' => $market->id]) ?>
                    <?= Html::beginForm(['market/delete', 'id' => $market->id], 'post', ['class' => 'inline-form']) ?>
                        <?= Html::submitButton('Delete', ['data-confirm' => 'Delete this market?']) ?>
                    <?= Html::endForm() ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
