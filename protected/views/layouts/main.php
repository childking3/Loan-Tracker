<?php

/** @var \yii\web\View $this */
/** @var string $content */

use app\components\NavMenu;
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

/**
 * Same active-route logic as $navLink, wrapping an icon + label instead of
 * plain text - the bottom-nav's own item shape.
 */
$bottomNavLink = static function (string $route, string $label, string $icon, bool $exact = false) use ($currentController, $currentAction) {
    [$controllerId, $actionId] = array_pad(explode('/', $route), 2, 'index');
    $isActive = $exact
        ? ($controllerId === $currentController && $actionId === $currentAction)
        : ($controllerId === $currentController);
    $class = 'bottom-nav-item' . ($isActive ? ' is-active' : '');
    return Html::a('<svg class="icon" viewBox="0 0 24 24">' . $icon . '</svg>' . Html::encode($label), [$route], ['class' => $class]);
};

/**
 * Import is positioned between Customers and Loans in the top nav
 * (matching the mockups and this app's pre-existing order), while every
 * other secondary item lists after Loans - so it's pulled out of
 * NavMenu::secondaryItems()'s otherwise-uniform list for top-nav rendering
 * only. The bottom-nav/More-page logic below uses the full, unsplit list -
 * position doesn't matter there the way it does in a horizontal top nav.
 */
$secondaryItems = !\Yii::$app->user->isGuest ? NavMenu::secondaryItems() : [];
$importItem = null;
$restSecondaryItems = [];
foreach ($secondaryItems as $item) {
    if ($item['route'] === 'import/customers') {
        $importItem = $item;
    } else {
        $restSecondaryItems[] = $item;
    }
}
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
    <title><?= Html::encode($this->title ?? 'DMC Sparkle Services') ?></title>
    <?= Html::script($themeInitJs, ['nonce' => \Yii::$app->csp->getNonce()]) ?>
    <link rel="stylesheet" href="/static/css/app.css">
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<header>
    <?php if (!\Yii::$app->user->isGuest): ?>
        <button type="button" id="nav-toggle" class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="header-controls">
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
            <span class="nav-toggle-bar"></span>
        </button>
    <?php endif; ?>
    <a class="brand" href="<?= Html::encode(Url::to(['/'])) ?>">
        DMC <span class="brand-mark">SPARKLE SERVICES</span>
    </a>
    <?php if (\Yii::$app->user->isGuest): ?>
        <a href="<?= Html::encode(Url::to(['site/login'])) ?>">Login</a>
    <?php else: ?>
        <nav id="header-controls">
            <?= $navLink('dashboard/index', 'Dashboard') ?>
            <?= $navLink('customer/index', 'Customers') ?>
            <?php if ($importItem !== null): ?>
                <?= $navLink($importItem['route'], $importItem['label']) ?>
            <?php endif; ?>
            <?= $navLink('loan/index', 'Loans') ?>
            <?php foreach ($restSecondaryItems as $item): ?>
                <?= $navLink($item['route'], $item['label'], $item['exact'] ?? false) ?>
            <?php endforeach; ?>
        </nav>
        <?php $identity = \Yii::$app->user->identity; ?>
        <a class="user-chip" href="<?= Html::encode(Url::to(['site/profile'])) ?>">
            <?= $this->render('//_avatar', ['model' => $identity, 'imgClass' => 'avatar', 'placeholderClass' => 'avatar avatar-placeholder']) ?>
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
<?php if (!\Yii::$app->user->isGuest): ?>
    <nav class="bottom-nav">
        <?= $bottomNavLink('dashboard/index', 'Dashboard', '<path d="M3 11L12 3l9 8"/><path d="M5 10v10h14V10"/>') ?>
        <?= $bottomNavLink('customer/index', 'Customers', '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6"/><circle cx="18" cy="9" r="2.5"/><path d="M16.5 20c0-2 1-3.5 2.5-4.2"/>') ?>
        <?= $bottomNavLink('loan/index', 'Loans', '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>') ?>
        <?php if (count($secondaryItems) === 1): ?>
            <?php $only = $secondaryItems[0]; ?>
            <?= $bottomNavLink($only['route'], $only['label'], $only['icon'], $only['exact'] ?? false) ?>
        <?php elseif (count($secondaryItems) > 1): ?>
            <?= $bottomNavLink('site/more', 'More', '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>', true) ?>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
