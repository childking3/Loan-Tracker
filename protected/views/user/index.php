<?php

/** @var \yii\web\View $this */
/** @var \yii\data\ActiveDataProvider $dataProvider */
/** @var array $roles user id => role name */
/** @var array $marketNames user id => market name, staff role only */

use app\models\User;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Staff accounts';

$roleBadgeClass = [
    User::ROLE_STAFF => 'badge-active',
    User::ROLE_MANAGER => 'badge-completed',
    User::ROLE_ADMIN => 'badge-cancelled',
];
$roleLabel = [
    User::ROLE_STAFF => 'Staff',
    User::ROLE_MANAGER => 'Manager',
    User::ROLE_ADMIN => 'Admin',
];

// A manager holding only manageStaffAccounts (not manageUsers) sees
// every account here but can only act on staff-role ones - see
// UserController::canManageAllRoles()'s docblock for the full scope.
// Enforced server-side regardless of this flag; this only controls
// which controls are even shown.
$canManageAllRoles = \Yii::$app->user->can('manageUsers');
?>
<div class="page-header-row">
    <h1>Staff Accounts</h1>
    <?= Html::a('<svg class="icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg> New Staff Account', ['user/create'], ['class' => 'btn-icon']) ?>
</div>

<?= $this->render('//_flashes') ?>

<ul class="record-list">
    <?php foreach ($dataProvider->getModels() as $user): ?>
        <?php $role = $roles[$user->id] ?? User::ROLE_STAFF; ?>
        <li class="record-card">
            <?= $this->render('//_avatar', ['model' => $user, 'imgClass' => 'avatar record-avatar', 'placeholderClass' => 'record-avatar']) ?>
            <div class="record-details">
                <span class="record-name">
                    <?= Html::encode($user->full_name) ?>
                    <span class="badge <?= $roleBadgeClass[$role] ?? 'badge-active' ?> badge-inline"><?= Html::encode($roleLabel[$role] ?? $role) ?></span>
                    <?php if ($user->status !== User::STATUS_ACTIVE): ?>
                        <span class="badge badge-overdue badge-inline">Deactivated</span>
                    <?php endif; ?>
                </span>
                <span class="record-meta">
                    <span class="record-meta-item"><?= Html::encode($user->username) ?></span>
                    <span class="record-meta-item"><?= Html::encode($user->email) ?></span>
                    <span class="record-meta-item">
                        <svg class="icon" viewBox="0 0 24 24"><path d="M20.59 13.41L13 21l-9-9V4h8l8.59 8.59a2 2 0 010 2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                        <?php if ($role === User::ROLE_STAFF): ?>
                            <?= isset($marketNames[$user->id]) ? Html::encode($marketNames[$user->id]) : 'Unassigned' ?>
                        <?php else: ?>
                            All markets
                        <?php endif; ?>
                    </span>
                </span>
            </div>
            <div class="record-actions">
                <?php if ($canManageAllRoles || $role === User::ROLE_STAFF): ?>
                    <?= Html::a('Edit', ['user/update', 'id' => $user->id]) ?>
                    <?php if ($user->id !== \Yii::$app->user->id): ?>
                        <?php if ($user->status === User::STATUS_ACTIVE): ?>
                            <?= Html::beginForm(['user/deactivate', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                                <?= Html::submitButton('Deactivate', ['data-confirm' => 'Deactivate this staff account? This can be undone from here afterward.']) ?>
                            <?= Html::endForm() ?>
                        <?php else: ?>
                            <?= Html::beginForm(['user/activate', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                                <?= Html::submitButton('Reactivate') ?>
                            <?= Html::endForm() ?>
                            <?= Html::beginForm(['user/delete', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                                <?= Html::submitButton('Delete', ['data-confirm' => 'Permanently delete this staff account? This cannot be undone from here - do this only once you are sure they will not be reactivated.']) ?>
                            <?= Html::endForm() ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">View only</span>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<?= LinkPager::widget(['pagination' => $dataProvider->getPagination()]) ?>
