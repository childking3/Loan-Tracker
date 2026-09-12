<?php

/** @var \yii\web\View $this */
/** @var array|null $summary */

use yii\helpers\Html;

$this->title = 'Import customers';
?>
<h1>Import customers</h1>

<p><?= Html::a('Download CSV template', ['import/customers-template']) ?></p>

<?= Html::beginForm(['import/customers'], 'post', ['enctype' => 'multipart/form-data']) ?>
    <input type="file" name="csvFile" accept=".csv" required>
    <?= Html::submitButton('Import') ?>
<?= Html::endForm() ?>

<?php if ($summary !== null): ?>
    <h2>Import result</h2>
    <p><?= Html::encode($summary['imported']) ?> customer(s) imported.</p>
    <?php if ($summary['errors'] !== []): ?>
        <ul>
            <?php foreach ($summary['errors'] as $error): ?>
                <li><?= Html::encode($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
