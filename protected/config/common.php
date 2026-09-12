<?php
/**
 * Config shared between the web and console apps.
 * web.php / console.php each merge this with their own overrides —
 * same pattern as HumHub's protected/config/common.php.
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');
requireEnv(['DB_DSN', 'DB_USER', 'DB_PASSWORD', 'COOKIE_VALIDATION_KEY', 'APP_HOST_INFO']);

return [
    // 'log' must be in bootstrap, not just 'components' - a component only
    // listed under 'components' is built lazily on first use, but nothing
    // in the normal request path ever asks for 'log' by name, so its
    // Dispatcher (which wires itself to Yii::getLogger() in its own
    // constructor) never got built and errors were silently dropped.
    // Listing it in bootstrap forces it to construct every request.
    'bootstrap' => ['log'],

    'components' => [
        'db' => require __DIR__ . '/db.php',

        // Own Redis-protocol cache component (php-redis ext, no Composer
        // yii2-redis package). KeyDB speaks the same protocol.
        // Uses igbinary serializer instead of Cache's default serialize()/
        // unserialize() - already installed on this box, faster/smaller,
        // and nothing reads raw unserialized values out of Redis directly,
        // so it's a safe drop-in. Not a HumHub-derived choice.
        'cache' => [
            'class' => 'app\components\KeydbCache',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DATABASE', 0),
            'keyPrefix' => 'loantracker_cache:',
            'serializer' => ['igbinary_serialize', 'igbinary_unserialize'],
        ],

        'cacheSchema' => [
            'class' => 'app\components\KeydbCache',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DATABASE', 0),
            'keyPrefix' => 'loantracker_schema:',
            'serializer' => ['igbinary_serialize', 'igbinary_unserialize'],
        ],

        'authManager' => [
            'class' => 'yii\rbac\DbManager',
            'cache' => 'cache',
        ],

        // Added because every caught exception was rendering the generic
        // error page and then vanishing - no log target meant Yii::error()
        // had nowhere to write, and APP_DEBUG can't be flipped on since
        // this deployment is public. 404s excluded so routine bot/scanner
        // probing doesn't bury real errors.
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logFile' => '@app/runtime/logs/app.log',
                    'maxFileSize' => 5120,
                    'maxLogFiles' => 5,
                    'except' => ['yii\web\HttpException:404'],
                    // Yii's default logVars dumps $_COOKIE/$_SESSION
                    // (session id, CSRF token) into every log entry.
                    // $_SERVER.HTTP_COOKIE carries the same session id
                    // again, so it's excluded specifically rather than
                    // dropping all of $_SERVER, keeping the rest
                    // (REQUEST_URI, method, etc.) for debugging.
                    'logVars' => ['_GET', '_POST', '_FILES', '_SERVER', '!_SERVER.HTTP_COOKIE'],
                ],
            ],
        ],
    ],

    'on beforeRequest' => function () {
        if (Yii::$app->has('db')) {
            Yii::$app->db->schemaCache = 'cacheSchema';
            Yii::$app->db->schemaCacheDuration = 86400;
        }
    },
];
