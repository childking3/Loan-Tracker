<?php

/** @var \yii\web\View $this */
/** @var string $pageTitle */
/** @var \yii\data\ActiveDataProvider $dataProvider */
/** @var array $entityNames */
/** @var bool $showChanges */

use app\models\ActivityLog;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = $pageTitle;

/**
 * Groups by month within the current page only, not the whole table -
 * a header row is inserted whenever the month changes between rows
 * (already fetched newest-first). A month can span two pages at the
 * pagination boundary; a fully month-aligned pager would need pagination
 * reworked around date boundaries, not worth it for this view.
 */
$currentGroup = null;
?>
<h1><?= Html::encode($pageTitle) ?></h1>

<?php $columnCount = $showChanges ? 6 : 5; ?>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th>When</th>
        <th>User</th>
        <th>Action</th>
        <th>Entity</th>
        <?php if ($showChanges): ?>
            <th>Changes</th>
        <?php endif; ?>
        <th>IP address</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($dataProvider->getModels() as $entry): ?>
        <?php
        $group = date('F Y', $entry->created_at);
        if ($group !== $currentGroup):
            $currentGroup = $group;
        ?>
        <tr class="log-group-row">
            <th colspan="<?= $columnCount ?>"><?= Html::encode($group) ?></th>
        </tr>
        <?php endif; ?>
        <tr>
            <td><?= Html::encode(date('Y-m-d H:i:s', $entry->created_at)) ?></td>
            <td><?= Html::encode($entry->user->username ?? 'system') ?></td>
            <td><?= Html::encode($entry->action) ?></td>
            <td>
                <?php if ($entry->entity_type === null): ?>
                    <?= Html::encode('') ?>
                <?php elseif ($entry->entity_type === 'user' && $entry->entity_id === null && isset($entry->new_value['username'])): ?>
                    <?php // login_failed: no real user row to resolve (bad/unknown username attempted), the attempted value itself - already redacted at write time unless it matched a real username - is the only display value there is. ?>
                    user &mdash; <?= Html::encode($entry->new_value['username']) ?>
                <?php else: ?>
                    <?php $name = $entityNames[$entry->entity_type][$entry->entity_id] ?? null; ?>
                    <?= Html::encode($entry->entity_type) ?>
                    <?php if ($name !== null): ?>
                        &mdash; <?= Html::encode($name) ?>
                    <?php elseif ($entry->entity_id !== null): ?>
                        #<?= Html::encode($entry->entity_id) ?> <span class="muted">(record no longer exists)</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <?php if ($showChanges): ?>
                <td>
                    <?php $changes = $entry->getChanges(); ?>
                    <?php if ($changes === []): ?>
                        <span class="muted">&ndash;</span>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($changes as $field => $value): ?>
                                <li>
                                    <strong><?= Html::encode(ActivityLog::fieldLabel($field, $entry->entity_type)) ?>:</strong>
                                    <?php if ($value['old'] === null): ?>
                                        <?= Html::encode(ActivityLog::formatChangeValue($field, $value['new'], $entityNames)) ?>
                                    <?php elseif ($value['new'] === null): ?>
                                        <?= Html::encode(ActivityLog::formatChangeValue($field, $value['old'], $entityNames)) ?>
                                    <?php else: ?>
                                        <?= Html::encode(ActivityLog::formatChangeValue($field, $value['old'], $entityNames)) ?>
                                        &rarr;
                                        <?= Html::encode(ActivityLog::formatChangeValue($field, $value['new'], $entityNames)) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            <td><?= Html::encode($entry->ip_address ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
