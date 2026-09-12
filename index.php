<?php

/**
 * Front controller. No Composer involved: requires the Yii2 framework's
 * own Yii.php directly (its @yii alias + autoload() handle everything
 * under the yii\ namespace), then our app config for the rest.
 */

require __DIR__ . '/protected/framework/Yii.php';

\Yii::setAlias('@app', __DIR__ . '/protected');

$config = require __DIR__ . '/protected/config/web.php';

// Explicit, matching how HumHub's own bootstrap (BootstrapService) and
// every standard Yii2 entry script define this - previously left
// undefined here entirely, which happened to be safe only because
// nothing had ever set it true, not because anything guarded it. This
// matters specifically for bootstrap-level failures (an exception
// thrown constructing a component, before this app's own
// errorAction-based error page can exist to catch it) - that class of
// error falls through to Yii2's own built-in exception renderer, which
// checks this constant directly. See .env's APP_DEBUG comment for the
// concrete incident that makes this worth being explicit about rather
// than implicit.
defined('YII_DEBUG') or define('YII_DEBUG', filter_var(env('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN));

(new \yii\web\Application($config))->run();
