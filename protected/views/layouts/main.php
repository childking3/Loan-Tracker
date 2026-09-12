<?php

/** @var \yii\web\View $this */
/** @var string $content */

use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Highlights the active nav link. Compares controller id only by default,
 * so any action within a controller (view/create/update) still counts as
 * "on this section". LogController is the exception - it serves two nav
 * items (Audit log, Access log), so those pass $exact=true to compare the
 * full controller/action, or viewing either would highlight both.
 */
$currentController = \Yii::$app->controller->id;
$currentAction = \Yii::$app->controller->action->id ?? '';
$navLink = static function (string $route, string $label, bool $exact = false) use ($currentController, $currentAction) {
    [$controllerId, $actionId] = array_pad(explode('/', $route), 2, 'index');
    $isActive = $exact
        ? ($controllerId === $currentController && $actionId === $currentAction)
        : ($controllerId === $currentController);
    return Html::a($label, [$route], ['class' => $isActive ? 'is-active' : '']);
};
$themeInitJs = <<<'JS'
(function () {
    try {
        var stored = localStorage.getItem('theme');
        var wantsDark = stored ? stored === 'dark' : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (wantsDark) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {
        // localStorage can throw in a locked-down/private context - fall
        // back to the light theme rather than break page load over this.
    }
})();
JS;

$themeToggleJs = <<<'JS'
(function () {
    var button = document.getElementById('theme-toggle');
    if (!button) {
        return;
    }
    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }
    function updateLabel() {
        button.textContent = isDark() ? 'Light mode' : 'Dark mode';
        button.setAttribute('aria-label', isDark() ? 'Switch to light mode' : 'Switch to dark mode');
    }
    button.addEventListener('click', function () {
        var next = isDark() ? 'light' : 'dark';
        if (next === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try {
            localStorage.setItem('theme', next);
        } catch (e) {
            // Preference just won't persist across visits - the toggle
            // itself still works for the rest of this page view.
        }
        updateLabel();
    });
    updateLabel();
})();
JS;

/**
 * Below 640px the nav/user chip/logout/theme toggle are hidden by CSS
 * until this button opens them. Toggles one class on <header> instead of
 * each element, so CSS alone decides what "open" looks like. Above 640px
 * the button is hidden and the class has no visible effect.
 */
$navToggleJs = <<<'JS'
(function () {
    var button = document.getElementById('nav-toggle');
    var header = document.querySelector('header');
    if (!button || !header) {
        return;
    }
    button.addEventListener('click', function () {
        var isOpen = header.classList.toggle('nav-open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
})();
JS;
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title ?? 'Loan Tracker') ?></title>
    <?= Html::script($themeInitJs, ['nonce' => \Yii::$app->csp->getNonce()]) ?>
    <link rel="stylesheet" href="/static/css/app.css">
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<header>
    <a class="brand" href="<?= Html::encode(Url::to(['/'])) ?>">
        Loan <span class="brand-mark">Tracker</span>
    </a>
    <?php if (!\Yii::$app->user->isGuest): ?>
        <button type="button" id="nav-toggle" class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="header-controls">
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
        </button>
    <?php endif; ?>
    <?php if (\Yii::$app->user->isGuest): ?>
        <a href="<?= Html::encode(Url::to(['site/login'])) ?>">Login</a>
    <?php else: ?>
        <nav id="header-controls">
            <?= $navLink('dashboard/index', 'Dashboard') ?>
            <?= $navLink('customer/index', 'Customers') ?>
            <?php if (\Yii::$app->user->can('manageCustomers')): ?>
                <?= $navLink('import/customers', 'Import') ?>
            <?php endif; ?>
            <?= $navLink('loan/index', 'Loans') ?>
            <?php if (\Yii::$app->user->can('viewReports')): ?>
                <?= $navLink('report/index', 'Reports') ?>
            <?php endif; ?>
            <?php if (\Yii::$app->user->can('manageUsers')): ?>
                <?= $navLink('user/index', 'Staff') ?>
            <?php endif; ?>
            <?php if (\Yii::$app->user->can('manageSettings')): ?>
                <?= $navLink('loan-package/index', 'Loan packages') ?>
            <?php endif; ?>
            <?php if (\Yii::$app->user->can('viewAuditLog')): ?>
                <?= $navLink('log/audit', 'Audit log', true) ?>
            <?php endif; ?>
            <?php if (\Yii::$app->user->can('viewAccessLogs')): ?>
                <?= $navLink('log/access', 'Access log', true) ?>
            <?php endif; ?>
        </nav>
        <?php $identity = \Yii::$app->user->identity; ?>
        <a class="user-chip" href="<?= Html::encode(Url::to(['site/profile'])) ?>">
            <?php if ($identity->avatar_filename): ?>
                <img class="avatar" src="<?= Html::encode(Url::to(['avatar/view', 'id' => $identity->id, 'v' => $identity->avatar_filename])) ?>" alt="">
            <?php else: ?>
                <span class="avatar avatar-placeholder"><?= Html::encode(mb_strtoupper(mb_substr($identity->full_name, 0, 1))) ?></span>
            <?php endif; ?>
            <?= Html::encode($identity->username) ?>
        </a>
        <?= Html::beginForm(['site/logout'], 'post') ?>
            <?= Html::submitButton('Logout') ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
    <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Switch to dark mode">Dark mode</button>
    <?= Html::script($themeToggleJs, ['nonce' => \Yii::$app->csp->getNonce()]) ?>
    <?= Html::script($navToggleJs, ['nonce' => \Yii::$app->csp->getNonce()]) ?>
</header>
<main>
    <?= $content ?>
</main>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
