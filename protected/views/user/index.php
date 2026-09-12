<?php

/** @var \yii\web\View $this */
/** @var \app\models\User[] $users */
/** @var array $roles */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Staff accounts';
?>
<h1>Staff accounts</h1>

<?= $this->render('//_flashes') ?>

<p><?= Html::a('New staff account', ['user/create']) ?></p>

<div class="table-scroll">
<table>
    <thead>
    <tr>
        <th></th>
        <th>Name</th>
        <th>Username</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $user): ?>
        <tr>
            <td>
                <?php if ($user->avatar_filename): ?>
                    <img class="avatar" src="<?= Html::encode(Url::to(['avatar/view', 'id' => $user->id, 'v' => $user->avatar_filename])) ?>" alt="">
                <?php else: ?>
                    <span class="avatar avatar-placeholder"><?= Html::encode(mb_strtoupper(mb_substr($user->full_name, 0, 1))) ?></span>
                <?php endif; ?>
            </td>
            <td><?= Html::encode($user->full_name) ?></td>
            <td><?= Html::encode($user->username) ?></td>
            <td><?= Html::encode($user->email) ?></td>
            <td><?= Html::encode($roles[$user->id] ?? '') ?></td>
            <td><?= $user->status === \app\models\User::STATUS_ACTIVE ? 'Active' : 'Deactivated' ?></td>
            <td>
                <?= Html::a('Edit', ['user/update', 'id' => $user->id]) ?>
                <?php if ($user->id !== \Yii::$app->user->id): ?>
                    <?php if ($user->status === \app\models\User::STATUS_ACTIVE): ?>
                        <?= Html::beginForm(['user/deactivate', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                            <?= Html::submitButton('Deactivate', ['onclick' => "return confirm('Deactivate this staff account? This can be undone from here afterward.');"]) ?>
                        <?= Html::endForm() ?>
                    <?php else: ?>
                        <?= Html::beginForm(['user/activate', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                            <?= Html::submitButton('Reactivate') ?>
                        <?= Html::endForm() ?>
                        <?= Html::beginForm(['user/delete', 'id' => $user->id], 'post', ['class' => 'inline-form']) ?>
                            <?= Html::submitButton('Delete', ['onclick' => "return confirm('Permanently delete this staff account? This cannot be undone from here - do this only once you are sure they will not be reactivated.');"]) ?>
                        <?= Html::endForm() ?>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
