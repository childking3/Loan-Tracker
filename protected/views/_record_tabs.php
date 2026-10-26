<?php

/** @var \yii\web\View $this */
/** @var string $filter 'active' | 'deleted' | 'all' - the current soft-delete filter */

/**
 * Active / Deleted / All filter tabs, shared by the customers and markets
 * lists. Url::current() keeps every other query param (search/date/sort/
 * staff) and only swaps `filter` - null for Active so its URL stays clean.
 * Route-agnostic: it reads the current request, so both lists render it the
 * same way with just the active-tab marker differing.
 */

use yii\helpers\Html;
use yii\helpers\Url;

$tabs = ['active' => 'Active', 'deleted' => 'Deleted', 'all' => 'All'];
?>
<div class="record-tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <?= Html::a(
            $label,
            Url::current(['filter' => $key === 'active' ? null : $key]),
            ['class' => 'record-tab' . ($filter === $key ? ' is-active' : '')]
        ) ?>
    <?php endforeach; ?>
</div>
