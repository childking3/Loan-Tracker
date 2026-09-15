<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var \app\models\Loan[] $loans */

use yii\helpers\Html;

$this->title = $model->full_name;

$visibleLoans = array_values(array_filter($loans, function ($loan) {
    return \Yii::$app->user->can('viewAllLoans') || \Yii::$app->user->can('viewAssignedLoans', ['loan' => $loan]);
}));

// Active/overdue loans (still being paid off) vs completed/cancelled ones
// (history) split into separate tables - a customer with one loan still
// running and another long since paid off looked like a single
// undifferentiated list otherwise, easy to misread as "which of these is
// the one I actually need to act on".
$activeLoans = array_values(array_filter($visibleLoans, function ($loan) {
    return in_array($loan->status, ['active', 'overdue'], true);
}));
$pastLoans = array_values(array_filter($visibleLoans, function ($loan) {
    return in_array($loan->status, ['completed', 'cancelled'], true);
}));
?>
<h1><?= Html::encode($model->full_name) ?></h1>

<?= $this->render('//_flashes') ?>

<p>Phone: <?= Html::encode($model->phone) ?></p>
<p>Address: <?= Html::encode($model->address ?? '') ?></p>

<p><?= Html::a('Edit', ['customer/update', 'id' => $model->id]) ?></p>
<?= Html::beginForm(['customer/delete', 'id' => $model->id], 'post') ?>
    <?= Html::submitButton('Delete', ['onclick' => "return confirm('Delete this customer?');"]) ?>
<?= Html::endForm() ?>

<h2>Loans</h2>
<?php if (\Yii::$app->user->can('manageLoans')): ?>
    <p><?= Html::a('New loan', ['loan/create', 'customerId' => $model->id]) ?></p>
<?php endif; ?>
<?php if ($loans === []): ?>
    <p>No loans yet.</p>
<?php elseif ($visibleLoans === []): ?>
    <p>This customer has loan history, but you don't have access to view it individually.</p>
<?php else: ?>
    <h3>Active</h3>
    <?php if ($activeLoans === []): ?>
        <p>No active loans.</p>
    <?php else: ?>
        <?= $this->render('_loan_table', ['loans' => $activeLoans]) ?>
    <?php endif; ?>

    <h3>History</h3>
    <?php if ($pastLoans === []): ?>
        <p>No completed or cancelled loans.</p>
    <?php else: ?>
        <?= $this->render('_loan_table', ['loans' => $pastLoans]) ?>
    <?php endif; ?>
<?php endif; ?>
