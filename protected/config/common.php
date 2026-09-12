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
    // Every stock Yii2 app template lists 'log' here - not a redundant
    // formality. yii\log\Dispatcher only ever connects itself to
    // Yii::getLogger() (the static logger every Yii::error()/warning()
    // call and the framework's own exception handler write through)
    // from inside its own constructor - see yii\log\Dispatcher::
    // __construct()'s "connect logger and dispatcher" comment. A
    // component merely listed under 'components' is built lazily, the
    // first time something asks for it by name - and nothing in this
    // app, nor anywhere in the framework's normal request path, ever
    // did that for 'log'. Confirmed concretely: added the 'log'
    // component + a FileTarget below to chase down a real 500 on
    // /log/audit, hit the page for real through the live site several
    // times over, and runtime/logs/app.log never appeared - the
    // Dispatcher was configured but had simply never been born. Listing
    // it here forces yii\base\Application::bootstrap() to fetch it (see
    // that method's `if ($this->has($mixed)) { $component =
    // $this->get($mixed); }` branch for a bootstrap array of string
    // component ids) once, up front, every request - which is what
    // actually wires the connection before any exception has a chance
    // to happen.
    'bootstrap' => ['log'],

    'components' => [
        'db' => require __DIR__ . '/db.php',

        // Our own Redis-protocol cache component (php-redis ext, no Composer
        // yii2-redis package). KeyDB speaks the same protocol.
        //
        // serializer uses igbinary instead of yii\caching\Cache's own
        // default (plain PHP serialize()/unserialize()) - checked this
        // isn't a HumHub-inspired change, since HumHub's Redis cache
        // config never sets a serializer either (confirmed via a direct
        // read of its own tree - the only place it sets 'serializer' at
        // all is its unrelated per-request ArrayCache). Done on this
        // app's own merit instead: the igbinary PHP extension is already
        // installed on this box (confirmed via `php -m`), the framework's
        // own Cache::$serializer docblock names it as the standard faster/
        // smaller-footprint alternative, and this app has no code that
        // reads a raw, unserialized value directly out of Redis by hand
        // (checked - every read/write of a serialized value goes through
        // Cache::get()/set(), which apply the same serializer on both
        // ends) - a drop-in change with no reachable downside.
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

        // No 'log' component existed anywhere in this app until now -
        // every caught exception (anything short of the bootstrap-level
        // failures covered by APP_DEBUG's own docblock) was being
        // rendered as the generic "An internal server error occurred"
        // page and then silently discarded, since Yii::error() has
        // nowhere to write without a log target configured. Found this
        // by hitting a real 500 with no way to see why: not in Apache's
        // error log (that only ever caught the earlier, unrelated
        // bootstrap-level .env-permission incident, which fails before
        // Yii's own error handler exists to catch and swallow anything),
        // and APP_DEBUG can't be flipped on to check because this
        // deployment is genuinely public now. 404s are excluded - a
        // small internal tool with no public signup gets a steady trickle
        // of bot/scanner probes hitting nonexistent paths, and logging
        // every one of those would bury the errors this exists to catch.
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    'logFile' => '@app/runtime/logs/app.log',
                    'maxFileSize' => 5120,
                    'maxLogFiles' => 5,
                    'except' => ['yii\web\HttpException:404'],
                    // Yii2's own default `logVars` dumps $_COOKIE and
                    // $_SESSION verbatim into every logged error - found
                    // this only by reading a real log entry this same
                    // session, which had a live PHPSESSID and a raw CSRF
                    // token sitting in it in plain text. $_SERVER carries
                    // the same session id a second time via its own
                    // HTTP_COOKIE header value, so dropping _COOKIE/
                    // _SESSION from the list isn't enough on its own -
                    // excluded via the `!key.path` syntax instead (see
                    // yii\helpers\BaseArrayHelper::filter()) so the rest
                    // of $_SERVER (REQUEST_URI, method, etc. - genuinely
                    // useful for debugging) is still kept.
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
