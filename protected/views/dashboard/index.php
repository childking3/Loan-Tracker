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

    // Pending timer id, or null while no poll is scheduled - used by the
    // visibilitychange listener below to know whether it needs to kick the
    // loop back off when the tab is un-hidden.
    var timer = null;

    function poll() {
        if (document.hidden) {
            // Don't fetch at all while this tab isn't visible - at a 1s
            // interval, a minimized/backgrounded dashboard would otherwise
            // poll forever for no one to see it. The visibilitychange
            // listener below fires an immediate catch-up poll and resumes
            // this loop as soon as the tab is shown again.
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
                // Network hiccup: quietly try again on the next tick rather
                // than surfacing an error for a background refresh.
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

// Echoed directly as a plain <script nonce="..."> tag, rather than
// registerJs(): the app's Content-Security-Policy requires every inline
// script to carry the current request's nonce (see app\components\Csp),
// and registerJs() has no option to add a custom attribute to the script
// tag it generates. This also sidesteps registerJs()'s default POS_READY
// position, which wraps the script in a jQuery-ready handler and pulls in
// yii\web\JqueryAsset - needing a vendor/bower/jquery directory that does
// not exist in this Composer-free setup.
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
