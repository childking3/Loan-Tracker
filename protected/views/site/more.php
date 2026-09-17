<?php

/** @var \yii\web\View $this */
/** @var array $items NavMenu::secondaryItems()'s shape */

use yii\helpers\Html;

$this->title = 'More';
?>
<h1>More</h1>
<p class="muted">Everything else, in one place for a phone-width screen where the full nav is tucked behind the menu button instead.</p>

<?php if ($items === []): ?>
    <p>Nothing else to show here.</p>
<?php else: ?>
    <ul class="record-list">
        <?php foreach ($items as $item): ?>
            <li class="record-card">
                <span class="record-icon"><?= '<svg class="icon" viewBox="0 0 24 24">' . $item['icon'] . '</svg>' ?></span>
                <div class="record-details">
                    <?= Html::a(Html::encode($item['label']), [$item['route']], ['class' => 'record-name']) ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
