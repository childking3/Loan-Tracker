<?php

/** @var \yii\web\View $this */
/** @var array $totals */
/** @var int $version */

use app\helpers\Currency;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Dashboard';

$pollUrlJson = Json::htmlEncode(Url::to(['dashboard/poll']));
$versionJson = Json::htmlEncode($version);

$js = <<<JS
(function () {
    var pollUrl = {$pollUrlJson};
    var lastVersion = {$versionJson};
    var intervalMs = 1000;

    function formatCurrency(amount) {
        return '₦' + Number(amount).toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function applyTotals(totals) {
        document.getElementById('stat-active-loans').textContent = totals.activeLoanCount;
        document.getElementById('stat-overdue-loans').textContent = totals.overdueLoanCount;
        document.getElementById('stat-outstanding').textContent = formatCurrency(totals.outstandingBalance);
        document.getElementById('stat-today-collections').textContent = formatCurrency(totals.todaysCollections);
    }

    // null when no poll is scheduled - lets visibilitychange below know
    // whether it needs to restart the loop when the tab is un-hidden.
    var timer = null;

    function poll() {
        if (document.hidden) {
            // Skip fetching while hidden - a backgrounded tab shouldn't
            // poll every second for no one to see it.
            timer = null;
            return;
        }

        fetch(pollUrl + '?last=' + lastVersion, {credentials: 'same-origin'})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                lastVersion = data.version;
                if (data.changed) {
                    applyTotals(data.totals);
                }
            })
            .catch(function () {
                // Network hiccup - retry next tick, don't surface an error.
            })
            .finally(function () {
                timer = setTimeout(poll, intervalMs);
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && timer === null) {
            poll();
        }
    });

    timer = setTimeout(poll, intervalMs);
})();
JS;

// Echoed as a plain <script nonce="..."> tag rather than registerJs():
// the CSP requires a nonce on every inline script, which registerJs() has
// no option to add, and its default POS_READY wraps output in a
// jQuery-ready handler this Composer-free setup doesn't have.
$scriptTag = Html::script($js, ['nonce' => \Yii::$app->csp->getNonce()]);
?>
<h1>Dashboard</h1>

<div class="stat-group">
    <h3 class="stat-group-label">Loans</h3>
    <div class="stats">
        <div class="stat-tile stat-tile--primary">
            <span class="stat-label">Active loans</span>
            <span class="stat-value" id="stat-active-loans"><?= Html::encode($totals['activeLoanCount']) ?></span>
        </div>
        <div class="stat-tile stat-tile--warning">
            <span class="stat-label">Overdue loans</span>
            <span class="stat-value" id="stat-overdue-loans"><?= Html::encode($totals['overdueLoanCount']) ?></span>
        </div>
    </div>
</div>

<div class="stat-group">
    <h3 class="stat-group-label">Collections</h3>
    <div class="stats">
        <div class="stat-tile">
            <span class="stat-label">Outstanding balance</span>
            <span class="stat-value" id="stat-outstanding"><?= Html::encode(Currency::format($totals['outstandingBalance'])) ?></span>
        </div>
        <div class="stat-tile stat-tile--success">
            <span class="stat-label">Today's collections</span>
            <span class="stat-value" id="stat-today-collections"><?= Html::encode(Currency::format($totals['todaysCollections'])) ?></span>
        </div>
    </div>
</div>

<h2>Staff performance</h2>
<table>
    <thead>
    <tr>
        <th>Staff</th>
        <th>Active loans</th>
        <th>Total collected</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($totals['staffPerformance'] as $row): ?>
        <tr>
            <td><?= Html::a(Html::encode($row['full_name']), ['dashboard/staff', 'id' => $row['id']]) ?></td>
            <td><?= Html::encode($row['active_loans']) ?></td>
            <td><?= Html::encode(Currency::format($row['total_collected'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $scriptTag ?>
