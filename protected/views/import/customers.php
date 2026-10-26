<?php

/** @var \yii\web\View $this */
/** @var array|null $summary */

use yii\helpers\Html;

$this->title = 'Import Customers';
?>
<h1>Import Customers</h1>
<p class="muted">Upload a CSV file with columns <code>full_name, phone, address</code> to bulk-register customers.</p>

<div class="record-card import-card">
    <span class="quick-action-icon">
        <svg class="icon" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    </span>
    <?= Html::beginForm(['import/customers'], 'post', ['enctype' => 'multipart/form-data']) ?>
        <label>Choose CSV file
            <input type="file" name="csvFile" accept=".csv" required>
        </label>
        <?= Html::submitButton('Upload & Import') ?>
    <?= Html::endForm() ?>
</div>

<?= Html::a('Download CSV template', ['import/customers-template'], ['class' => 'view-all-link']) ?>

<?php if ($summary !== null): ?>
    <h2>Last Import Summary</h2>
    <?php if ($summary['errors'] === []): ?>
        <div class="flash-success"><?= Html::encode($summary['imported']) ?> customer(s) imported successfully.</div>
    <?php else: ?>
        <div class="flash-error">
            <?= Html::encode($summary['imported']) ?> customer(s) imported.
            <ul>
                <?php foreach ($summary['errors'] as $error): ?>
                    <li><?= Html::encode($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
