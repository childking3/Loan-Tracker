<?php

/** @var \yii\web\View $this */
/** @var \app\models\Customer $model */
/** @var \app\models\Loan[] $loans */

use app\helpers\Avatar;
use yii\helpers\Html;

$this->title = $model->full_name;

$visibleLoans = array_values(array_filter($loans, fn ($loan) => $loan->isVisibleToCurrentUser()));

// Active/overdue loans (still being paid off) vs completed/cancelled ones
// (history) split into separate tables - a customer with one loan still
// running and another long since paid off looked like a single
// undifferentiated list otherwise, easy to misread as "which of these is
// the one I actually need to act on".
$activeLoans = array_values(array_filter($visibleLoans, fn ($loan) => $loan->isOpenForActivity()));
$pastLoans = array_values(array_filter($visibleLoans, fn ($loan) => !$loan->isOpenForActivity()));
?>
<div class="page-header-row">
    <div style="display:flex; align-items:center; gap:1rem;">
        <span class="record-avatar" style="width:3.5rem;height:3.5rem;font-size:1.1rem;"><?= Avatar::initial($model->full_name) ?></span>
        <div>
            <h1 style="margin-bottom:0.2rem;"><?= Html::encode($model->full_name) ?></h1>
            <span class="muted">Customer since <?= Html::encode(date('M Y', $model->created_at)) ?></span>
        </div>
    </div>
    <div style="display:flex; align-items:center; gap:0.5rem; flex-shrink:0;">
        <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg> Edit', ['customer/update', 'id' => $model->id], ['class' => 'btn-icon']) ?>
        <?= Html::beginForm(['customer/delete', 'id' => $model->id], 'post', ['class' => 'inline-form']) ?>
            <?= Html::submitButton('Delete', ['onclick' => "return confirm('Delete this customer?');"]) ?>
        <?= Html::endForm() ?>
    </div>
</div>

<?= $this->render('//_flashes') ?>

<ul class="record-list" style="margin-bottom: 2rem;">
    <li class="record-card" style="cursor: default;">
        <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.8 19.8 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.68 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.32 1.85.55 2.81.68A2 2 0 0122 16.92z"/></svg></span>
        <div class="record-details">
            <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Phone</span>
            <span style="display:block; font-size:0.95rem;"><?= Html::encode($model->phone) ?></span>
        </div>
    </li>
    <li class="record-card" style="cursor: default;">
        <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
        <div class="record-details">
            <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Address</span>
            <span style="display:block; font-size:0.95rem;"><?= Html::encode($model->address ?? '') ?></span>
        </div>
    </li>
    <li class="record-card" style="cursor: default;">
        <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><path d="M20.59 13.41L13 21l-9-9V4h8l8.59 8.59a2 2 0 010 2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></span>
        <div class="record-details">
            <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Market</span>
            <span style="display:block; font-size:0.95rem;">
                <?php if ($model->market !== null): ?>
                    <?= Html::a(Html::encode($model->market->name), ['market/index']) ?>
                <?php else: ?>
                    <span class="muted">&mdash; Unassigned</span>
                <?php endif; ?>
            </span>
        </div>
    </li>
</ul>

<?php if ($model->guarantor !== null): ?>
    <div class="section-header-row">
        <h2>Guarantor</h2>
    </div>
    <ul class="record-list" style="margin-bottom: 2rem;">
        <li class="record-card" style="cursor: default;">
            <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/></svg></span>
            <div class="record-details">
                <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Guarantor's name</span>
                <span style="display:block; font-size:0.95rem;"><?= Html::encode($model->guarantor->full_name) ?></span>
            </div>
        </li>
        <li class="record-card" style="cursor: default;">
            <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.8 19.8 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.68 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.32 1.85.55 2.81.68A2 2 0 0122 16.92z"/></svg></span>
            <div class="record-details">
                <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Guarantor's phone</span>
                <span style="display:block; font-size:0.95rem;"><?= Html::encode($model->guarantor->phone) ?></span>
            </div>
        </li>
        <?php if ($model->guarantor->address): ?>
            <li class="record-card" style="cursor: default;">
                <span class="record-icon"><svg class="icon" viewBox="0 0 24 24"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                <div class="record-details">
                    <span class="record-name" style="font-size: 0.78rem; color: var(--color-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Guarantor's address</span>
                    <span style="display:block; font-size:0.95rem;"><?= Html::encode($model->guarantor->address) ?></span>
                </div>
            </li>
        <?php endif; ?>
    </ul>
<?php endif; ?>

<div class="section-header-row">
    <h2>Loans</h2>
</div>
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
