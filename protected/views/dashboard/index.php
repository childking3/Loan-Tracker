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
$loginUrlJson = Json::htmlEncode(Url::to(['site/login']));
$versionJson = Json::htmlEncode($version);

$js = <<<JS
(function () {
    var pollUrl = {$pollUrlJson};
    var loginUrl = {$loginUrlJson};
    var lastVersion = {$versionJson};

    // Cadence borrowed from HumHub's live poller (min/max interval with idle
    // growth) instead of the flat 1s this used to run at. 1s from every open
    // dashboard tab was a lot of traffic for four aggregate numbers, and it was
    // also what let a dead session hammer the edge's login rate limiter.
    // HumHub uses 10s..45s for a social feed; 5s base is a little tighter since
    // this is a figures screen staff watch while taking payments.
    var minIntervalMs = 5000;
    var maxIntervalMs = 45000;
    var idleGrowth = 1.5;
    var intervalMs = minIntervalMs;

    // Random spread added to every scheduled poll. Without it, tabs opened
    // together keep firing in the same millisecond and each one's broadcast
    // arrives too late to stop the others.
    var jitterMs = 750;

    // When a result was last seen, from any tab. Used so un-hiding a tab that
    // already has fresh figures does not fire a redundant immediate poll.
    var lastResultAt = 0;

    // Set once the session is gone: stops the loop for good so it cannot keep
    // retrying behind a login page.
    var stopped = false;

    // null when no poll is scheduled - lets visibilitychange below know
    // whether it needs to restart the loop when the tab is un-hidden.
    var timer = null;

    // Cross-tab coordination, also from HumHub: a tab that polls shares the
    // result, and every other tab applies it and stands down to the ceiling
    // instead of duplicating the request. Guarded - not every browser has it.
    var channel = window.BroadcastChannel ? new BroadcastChannel('loan.dashboard.poll') : null;

    function formatCurrency(amount) {
        return '₦' + Number(amount).toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function applyTotals(totals) {
        document.getElementById('stat-active-loans').textContent = totals.activeLoanCount;
        document.getElementById('stat-overdue-loans').textContent = totals.overdueLoanCount;
        document.getElementById('stat-outstanding').textContent = formatCurrency(totals.outstandingBalance);
        document.getElementById('stat-today-collections').textContent = formatCurrency(totals.todaysCollections);
    }

    function debounce(fn, wait) {
        var handle = null;
        return function () {
            if (handle !== null) {
                clearTimeout(handle);
            }
            handle = setTimeout(fn, wait);
        };
    }

    function nextDelay() {
        return intervalMs + Math.floor(Math.random() * jitterMs);
    }

    function schedule(delay) {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
        if (!stopped) {
            timer = setTimeout(poll, delay);
        }
    }

    function poll() {
        if (stopped || document.hidden) {
            // Skip fetching while hidden - a backgrounded tab shouldn't
            // poll for no one to see it. (HumHub only tracks focus/blur and
            // keeps polling a hidden tab at its ceiling; stopping outright is
            // strictly cheaper.)
            timer = null;
            return;
        }

        // Announce the attempt BEFORE the request, not just the result.
        // Broadcasting only on completion leaves the whole request latency as
        // a window in which another tab's timer can fire - measured as two
        // collisions with 5 tabs, which jitter cannot close because it is
        // latency, not clock alignment. HumHub does the same thing
        // (broadCast(EVENT_TYPE_REQUEST) at the top of its update()).
        if (channel) {
            channel.postMessage({type: 'poll-start'});
        }

        fetch(pollUrl + '?last=' + lastVersion, {
            credentials: 'same-origin',
            // Makes Yii see this as AJAX. Without it fetch() sends no
            // X-Requested-With, so Request::getIsAjax() was false and the
            // return URL got set to this poll URL - logging in then landed the
            // user on raw JSON. WebUser::loginRequired() handles that centrally
            // now, so this is belt-and-braces for any other redirecting path.
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(function (response) {
                if (response.status === 401) {
                    // Session ended. Hand the whole page to the login screen
                    // once, rather than retrying forever behind it.
                    stopped = true;
                    // Tell the other tabs too. Without this each one had to
                    // discover the dead session on its own poll, so with three
                    // tabs they redirected 5s apart and the last sat showing
                    // stale figures ~11s after the session had gone.
                    if (channel) {
                        channel.postMessage({type: 'unauthenticated'});
                    }
                    window.location.assign(loginUrl);
                    return null;
                }
                if (!response.ok) {
                    // Never parse a non-OK body as JSON: an HTML error page
                    // threw, got swallowed as a "hiccup", and was retried at
                    // full rate.
                    throw new Error('poll failed: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (data === null) {
                    return;
                }
                lastVersion = data.version;
                lastResultAt = Date.now();
                if (data.changed) {
                    applyTotals(data.totals);
                    // Something is moving - go back to the fast cadence.
                    intervalMs = minIntervalMs;
                } else {
                    intervalMs = Math.min(Math.round(intervalMs * idleGrowth), maxIntervalMs);
                }
                if (channel) {
                    channel.postMessage({
                        type: 'poll-result',
                        version: lastVersion,
                        totals: data.changed ? data.totals : null,
                        interval: intervalMs
                    });
                }
            })
            .catch(function () {
                // Network hiccup or error response - back off rather than
                // hammering through an outage.
                intervalMs = Math.min(intervalMs * 2, maxIntervalMs);
            })
            .finally(function () {
                if (!stopped) {
                    schedule(nextDelay());
                }
            });
    }

    if (channel) {
        channel.onmessage = function (event) {
            var message = event.data || {};
            if (stopped) {
                return;
            }
            if (message.type === 'unauthenticated') {
                stopped = true;
                if (timer !== null) {
                    clearTimeout(timer);
                    timer = null;
                }
                window.location.assign(loginUrl);
                return;
            }
            if (message.type === 'poll-start') {
                // Someone else is mid-request; don't duplicate it.
                if (!document.hidden) {
                    schedule(nextDelay());
                }
                return;
            }
            if (message.type !== 'poll-result') {
                return;
            }
            lastVersion = message.version;
            lastResultAt = Date.now();
            if (message.totals) {
                applyTotals(message.totals);
            }
            // Adopt the cadence of whoever polled, so all tabs agree.
            if (message.interval) {
                intervalMs = Math.min(message.interval, maxIntervalMs);
            }
            if (document.hidden) {
                // visibilitychange restarts the loop; nothing to schedule.
                return;
            }
            // Re-arm one interval after THIS result - deliberately NOT a
            // stand-down to maxIntervalMs. Standing down meant that tabs
            // opened together all polled at once, all heard each other, and
            // all went quiet for 45s: measured 3 tabs -> 3 simultaneous polls
            // then zero for the next 35s, i.e. the figures silently stopped
            // updating. Anchoring to the last observed result keeps the
            // aggregate rate at roughly one poll per interval no matter how
            // many tabs are open, with no gap and no leader to elect.
            schedule(nextDelay());
        };
    }

    // Real interaction means the figures matter right now, so drop back to the
    // fast cadence. Debounced because mousemove fires constantly.
    ['mousemove', 'keydown', 'mousedown', 'touchstart'].forEach(function (eventName) {
        document.addEventListener(eventName, debounce(function () {
            if (stopped || document.hidden || intervalMs === minIntervalMs) {
                return;
            }
            intervalMs = minIntervalMs;
            schedule(minIntervalMs);
        }, 200), {passive: true});
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden || stopped || timer !== null) {
            return;
        }
        intervalMs = minIntervalMs;
        if (Date.now() - lastResultAt < minIntervalMs) {
            // A peer already refreshed these figures moments ago, so there is
            // nothing to catch up on. Matters when a window holding several
            // dashboard tabs is restored and they all un-hide at once.
            schedule(nextDelay());
            return;
        }
        poll();
    });

    // schedule(), not a bare setTimeout: one scheduling path only, so the
    // clear-existing-timer guard always applies.
    // nextDelay(), not intervalMs: tabs opened together must not line up on the
    // exact same first tick. Measured before this - 3 tabs all polled within
    // 0.1s of each other at startup, because each one's broadcast landed after
    // the others had already fired.
    schedule(nextDelay());
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

<?php
// Per-market balances (client request 2026-10-20): the owner wants each
// market's balance on its own line instead of one combined figure.
//
// Gated on viewAllCustomers (manager/admin). A market's totals aggregate
// customers belonging to several different staff, so showing one staff
// member a market figure would expose their colleagues' collections.
// Filtered here, not in DashboardCache, for the same reason as the staff
// table below: getTotals() is one shared cache entry for every user.
$marketRows = Yii::$app->user->can('viewAllCustomers')
    ? ($totals['marketBalances'] ?? [])
    : [];
?>
<?php if ($marketRows !== []): ?>
    <div class="stat-group">
        <h3 class="stat-group-label">Market balances</h3>
        <div class="stats">
            <?php foreach ($marketRows as $row): ?>
                <?php
                $tileBody = '<span class="stat-label">' . Html::encode($row['name']) . '</span>'
                    . '<span class="stat-value">' . Html::encode(Currency::format($row['outstanding'])) . '</span>'
                    . '<span class="muted">' . Html::encode($row['customer_count']) . ' customer'
                    . ($row['customer_count'] === 1 ? '' : 's') . '</span>';
                ?>
                <?php if ($row['id'] !== null): ?>
                    <?= Html::a($tileBody, ['market/view', 'id' => $row['id']], ['class' => 'stat-tile']) ?>
                <?php else: ?>
                    <div class="stat-tile"><?= $tileBody ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
// Per-staff customer separation (2026-10-16): the rows link through to
// dashboard/staff, which lists that staff member's loans and the customer
// names behind them. A staff member therefore sees only their own row;
// manager/admin (viewAllLoans) keep the full team table this exists for.
// Filtered here rather than in DashboardCache because getTotals() is a
// single shared cache entry for every user - scoping it there would make
// the cached value depend on who warmed it.
$canSeeWholeTeam = Yii::$app->user->can('viewAllLoans');
$staffRows = $canSeeWholeTeam
    ? $totals['staffPerformance']
    : array_values(array_filter(
        $totals['staffPerformance'],
        static fn ($row) => (int) $row['id'] === (int) Yii::$app->user->id
    ));
?>
<h2><?= $canSeeWholeTeam ? 'Staff performance' : 'Your performance' ?></h2>
<table>
    <thead>
    <tr>
        <th>Staff</th>
        <th>Active loans</th>
        <th>Total collected</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($staffRows as $row): ?>
        <tr>
            <td><?= Html::a(Html::encode($row['full_name']), ['dashboard/staff', 'id' => $row['id']]) ?></td>
            <td><?= Html::encode($row['active_loans']) ?></td>
            <td><?= Html::encode(Currency::format($row['total_collected'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $scriptTag ?>
