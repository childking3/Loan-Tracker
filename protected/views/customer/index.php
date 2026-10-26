<?php

/** @var \yii\web\View $this */
/** @var \yii\data\ActiveDataProvider $dataProvider */
/** @var string $search */
/** @var string $sort 'newest' or 'oldest' */
/** @var string $from Y-m-d or '' */
/** @var string $to Y-m-d or '' */
/** @var array $staffOptions staff id => name; empty unless the viewer holds viewAllCustomers */
/** @var string $staffFilter the selected staff id as a string, or '' */
/** @var string $filter 'active' | 'deleted' | 'all' - which soft-delete state is listed */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = $staffFilter !== '' && isset($staffOptions[(int) $staffFilter])
    ? $staffOptions[(int) $staffFilter] . "'s customers"
    : 'Customers';
?>
<div class="page-header-row">
    <h1><?= Html::encode($this->title) ?></h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg> Add Customer', ['customer/create'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('//_flashes') ?>

<div class="search-bar search-bar-wide">
    <?= Html::beginForm(['customer/index'], 'get') ?>
        <div class="search-input">
            <svg class="icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?= Html::input('text', 'q', $search, ['placeholder' => 'Search name, phone or date']) ?>
        </div>
        <?= Html::submitButton('Search') ?>
        <div class="search-filters">
            <label>Registered from <?= Html::input('date', 'from', $from) ?></label>
            <label>Registered to <?= Html::input('date', 'to', $to) ?></label>
            <label>Order
                <?= Html::dropDownList('sort', $sort, ['newest' => 'Newest first', 'oldest' => 'Oldest first']) ?>
            </label>
            <?php if ($staffOptions !== []): ?>
                <label>Staff
                    <?= Html::dropDownList('staff', $staffFilter, $staffOptions, ['prompt' => 'All staff']) ?>
                </label>
            <?php endif; ?>
            <?php if ($search !== '' || $from !== '' || $to !== '' || $sort !== 'newest' || $staffFilter !== ''): ?>
                <?= Html::a('Clear', ['customer/index'], ['class' => 'view-all-link']) ?>
            <?php endif; ?>
        </div>
    <?= Html::endForm() ?>
</div>

<?= $this->render('//_record_tabs', ['filter' => $filter]) ?>

<?php if ($dataProvider->getCount() === 0): ?>
    <p class="muted">No customers found.</p>
<?php else: ?>
    <ul class="record-list">
        <?php foreach ($dataProvider->getModels() as $customer): ?>
            <?php $isDeleted = $customer->deleted_at !== null; ?>
            <li class="record-card<?= $isDeleted ? ' is-deleted' : '' ?>">
                <?= $this->render('_photo', ['customer' => $customer, 'imgClass' => 'avatar record-avatar', 'placeholderClass' => 'record-avatar']) ?>
                <div class="record-details">
                    <span class="record-name">
                        <?= Html::encode($customer->full_name) ?>
                        <?php if ($isDeleted): ?>
                            <span class="badge badge-deleted badge-inline">Deleted</span>
                        <?php endif; ?>
                    </span>
                    <span class="record-meta">
                        <span class="record-meta-item">
                            <svg class="icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.8 19.8 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.68 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.9.32 1.85.55 2.81.68A2 2 0 0122 16.92z"/></svg>
                            <?= Html::encode($customer->phone) ?>
                        </span>
                        <?php if ($customer->address): ?>
                            <span class="record-meta-item">
                                <svg class="icon" viewBox="0 0 24 24"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <?= Html::encode($customer->address) ?>
                            </span>
                        <?php endif; ?>
                        <span class="record-meta-item">
                            <svg class="icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Registered <?= Html::encode(date('j M Y', $customer->created_at)) ?>
                        </span>
                        <?php if ($customer->market !== null): ?>
                            <span class="record-meta-item">
                                <svg class="icon" viewBox="0 0 24 24"><path d="M20.59 13.41L13 21l-9-9V4h8l8.59 8.59a2 2 0 010 2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                                <?= Html::encode($customer->market->name) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($staffOptions !== []): ?>
                            <span class="record-meta-item">
                                <svg class="icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?= $customer->assignedStaff !== null
                                    ? Html::encode($customer->assignedStaff->full_name)
                                    : '<span class="muted">Unassigned</span>' ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($isDeleted): ?>
                    <?php // Restore instead of a view link: customer/view 404s on a
                          // soft-deleted row (findModel excludes trashed), and restore
                          // is re-checked against isVisibleToCurrentUser() server-side. ?>
                    <?= Html::beginForm(['customer/restore', 'id' => $customer->id], 'post', ['class' => 'inline-form record-action-form']) ?>
                        <?= Html::submitButton('Restore', ['class' => 'btn-restore']) ?>
                    <?= Html::endForm() ?>
                <?php else: ?>
                    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>', ['customer/view', 'id' => $customer->id], ['class' => 'record-action', 'aria-label' => 'View customer']) ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
