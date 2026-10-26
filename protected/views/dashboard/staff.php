<?php

/** @var \yii\web\View $this */
/** @var \app\models\User $staff */
/** @var \app\models\Loan[] $loans */
/** @var array<int, float> $balances */
/** @var array $countsByStatus */
/** @var float $totalCollected */

use app\helpers\Badge;
use app\helpers\Currency;
use yii\helpers\Html;

$this->title = $staff->full_name;

$visibleLoans = array_values(array_filter($loans, fn ($loan) => $loan->isVisibleToCurrentUser()));
?>
<h1><?= Html::encode($staff->full_name) ?></h1>

<p class="muted"><?= Html::encode($staff->username) ?> &middot; <?= Html::encode($staff->email) ?></p>

<?php if (\Yii::$app->user->can('manageCustomers')): ?>
    <!--
    Deliberately plain customer/create, no staff id passed through:
    created_by must always reflect whoever is actually logged in and
    submits the form - not the staff member whose page happens to be
    open. CustomerController::actionCreate() sets created_by from
    Yii::$app->user->id itself, so there's nothing here that could
    override or spoof that even by mistake, but the link doesn't try to
    imply otherwise either (e.g. no "creating as <staff>" wording).

    (The older form of this comment said Customer had no staff-assignment
    concept at all, unlike Loan. That stopped being true on 2026-10-16,
    when customer.assigned_staff_id was added - but it remains correct
    that this link must not preset it: whoever a new customer is assigned
    to is decided by Customer::defaultOwnerForCurrentUser(), not by which
    page the Add link was clicked from.)
    -->
    <p>
        <?= Html::a('Add customer', ['customer/create']) ?>
        <?php if (\Yii::$app->user->can('viewAllCustomers')): ?>
            &middot;
            <?= Html::a(Html::encode($staff->full_name) . "'s customers", ['customer/index', 'staff' => $staff->id]) ?>
        <?php endif; ?>
    </p>
<?php endif; ?>

<div class="stat-group">
    <h3 class="stat-group-label">Loans</h3>
    <div class="stats">
        <div class="stat-tile stat-tile--primary">
            <span class="stat-label">Total loans</span>
            <span class="stat-value"><?= count($loans) ?></span>
        </div>
        <div class="stat-tile">
            <span class="stat-label">Active loans</span>
            <span class="stat-value"><?= $countsByStatus['active'] ?></span>
        </div>
        <div class="stat-tile stat-tile--warning">
            <span class="stat-label">Overdue loans</span>
            <span class="stat-value"><?= $countsByStatus['overdue'] ?></span>
        </div>
    </div>
</div>

<div class="stat-group">
    <h3 class="stat-group-label">Collections</h3>
    <div class="stats">
        <div class="stat-tile stat-tile--success">
            <span class="stat-label">Total collected</span>
            <span class="stat-value"><?= Html::encode(Currency::format($totalCollected)) ?></span>
        </div>
    </div>
</div>

<h2>Loans</h2>
<?php if ($loans === []): ?>
    <p>No loans assigned to this staff member yet.</p>
<?php elseif ($visibleLoans === []): ?>
    <p>This staff member has loans, but you don't have access to view them individually.</p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
        <tr>
            <th>Loan number</th>
            <th>Customer</th>
            <th>Status</th>
            <th>Principal</th>
            <th>Remaining balance</th>
            <th>Start date</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($visibleLoans as $loan): ?>
            <tr>
                <td><?= Html::a(Html::encode($loan->loan_number), ['loan/view', 'id' => $loan->id]) ?></td>
                <td><?= Html::a(Html::encode($loan->customer->full_name), ['customer/view', 'id' => $loan->customer_id]) ?></td>
                <td><?= Badge::status($loan->status) ?></td>
                <td><?= Html::encode(Currency::format($loan->principal_amount)) ?></td>
                <td><?= Html::encode(Currency::format($balances[(int) $loan->id] ?? 0.0)) ?></td>
                <td><?= Html::encode($loan->start_date) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
