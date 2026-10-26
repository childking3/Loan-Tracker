<?php
/**
 * Web app config.
 */

$common = require __DIR__ . '/common.php';

// Trusted reverse-proxy subnets (not single IPs - more than one proxy
// address has been seen in practice). Env-configurable so adding one is
// a .env edit, not a code change. Empty by default = nothing trusted.
$trustedProxyCidrs = parseCidrList(env('TRUSTED_PROXY_CIDRS', ''));

// Whether this request genuinely arrived over HTTPS, for marking the
// session/CSRF cookies Secure (a Secure cookie is silently dropped over
// plain HTTP, so APP_HOST_INFO being pinned to https:// isn't enough -
// this app has no TLS of its own, the proxy terminates it and signals the
// original scheme via X-Forwarded-Proto).
//
// This deliberately trusts the header WITHOUT first checking REMOTE_ADDR
// against $trustedProxyCidrs. That earlier gate was correct in isolation
// but broke in production (found 2026-10-19): Apache's mod_remoteip
// replaces REMOTE_ADDR with the real end-user IP whenever the request
// arrives from an address in RemoteIPInternalProxy, so the CIDR test then
// failed and these cookies shipped with NO Secure flag. It affected
// exactly half of all requests, because the proxy reaches the wg1 upstream
// peers from an address mod_remoteip rewrites for and the wg0 peers from
// one it doesn't - so the same user got a protected or unprotected cookie
// depending purely on which node served their login.
//
// Trusting the header is safe here, specifically:
//  - Apache :81 binds only to the WireGuard addresses and the nodes' public
//    IPs are closed on that port, so nothing off-tunnel can reach the app.
//  - nginx sets `X-Forwarded-Proto $scheme` unconditionally in every
//    proxying location, so a client cannot strip or override it.
//  - Forging it to "https" could only turn the Secure flag ON, which is
//    strictly safer. It cannot be used to turn the flag off, so there is no
//    attack the old gate was preventing.
//
// Still computed by hand against $_SERVER (not Yii::$app->request->isSecure)
// because Yii::$app doesn't exist yet at this point in the bootstrap.
$isSecureRequest = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

$config = [
    'id' => 'loantracker',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@app' => dirname(__DIR__),
        '@webroot' => dirname(__DIR__, 2),
        '@web' => '',
    ],
    'components' => [
        // hostInfo is pinned to a fixed trusted value rather than Yii's
        // default of trusting the incoming Host header. This vhost has no
        // ServerName and is the only one on its port, so Apache accepts
        // any Host and hands it straight through - unpinned, an
        // attacker-chosen Host gets reflected into every absolute URL the
        // app generates, including the login-redirect Location header.
        // See CODEBASE.md Phase 9 for the full write-up.
        'request' => [
            'class' => \app\components\Request::class,
            'cookieValidationKey' => env('COOKIE_VALIDATION_KEY'),
            'hostInfo' => env('APP_HOST_INFO'),
            // Trust X-Forwarded-For/-Proto only from the proxy's own
            // trusted subnets, so userIP (used by AuditLogger on every
            // access-log row) resolves the real client, not the proxy.
            // X-Forwarded-Host deliberately not trusted - hostInfo is
            // already pinned above, so no host-related header needs trust.
            'trustedHosts' => array_fill_keys($trustedProxyCidrs, ['X-Forwarded-For', 'X-Forwarded-Proto']),
            // _csrf cookie hardened to match the session cookie's explicit
            // SameSite=Lax below - belt-and-suspenders since browsers
            // already default to Lax, not a live gap being closed.
            'csrfCookie' => [
                'httpOnly' => true,
                'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
                'secure' => $isSecureRequest,
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'user' => [
            // Custom subclass so the dashboard's once-a-second poll cannot keep
            // re-arming the idle timeout - see app\components\WebUser.
            'class' => 'app\components\WebUser',
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => false,
            'loginUrl' => ['site/login'],
            // 30-minute idle timeout (explicit security baseline, not from
            // the brief). yii\web\User enforces it itself per request via a
            // session-stored timestamp, so no separate cron/GC is needed.
            'authTimeout' => 1800,
            // Absolute session ceiling: a session is force-logged-out this
            // long after login regardless of activity. Originally needed
            // because the idle timeout above is renewed on every request and
            // the dashboard poller renewed it perpetually, so the idle timeout
            // could never fire for an open tab. That specific hole is closed
            // now - app\components\WebUser suppresses renewal for the poll
            // route, and the poller backs off 5s..45s rather than running flat
            // out (2026-10-19) - but this ceiling is kept on its own merit as a
            // hard cap. Unlike authTimeout, this is written
            // once at login (yii\web\User::switchIdentity) and never renewed
            // (renewAuthStatus only reads it), so it's a true hard cap - a
            // walked-away unlocked session can't stay alive indefinitely.
            // 12h covers a long working day; staff re-login each morning.
            'absoluteAuthTimeout' => 43200,
        ],
        // Session storage depends on the save handler the pool gives us.
        // On prod the loan FPM pool sets `session.save_handler = redis`
        // pointing at the loan KeyDB (127.0.0.1:6381 db1), which is
        // active-active replicated between both app nodes - so a session
        // created on one node is readable on the other and a login survives
        // whichever node the reverse proxy picks. Previously sessions were
        // per-node files, which split logins ~50/50 across the two nodes.
        // Where the handler is still `files` (dev, or any host without the
        // pool override) they stay under the project's own runtime dir, so
        // session data is easy to find/clear and isolated from other apps.
        // The guard matters: Session::setSavePath() only accepts a real
        // directory and would throw on a tcp:// URL, so Yii must not call
        // session_save_path() at all under the redis handler.
        'session' => [
            'class' => 'yii\web\Session',
            ...(ini_get('session.save_handler') === 'files'
                ? ['savePath' => '@runtime/sessions']
                : []),
            // Match user.authTimeout (1800s): PHP's default gc_maxlifetime
            // (1440s) would otherwise expire sessions 6 minutes early wherever
            // session GC runs.
            'timeout' => 1800,
            // Explicit SameSite=Lax, matching the _csrf cookie - makes
            // behavior audit-proof instead of relying on browser defaults.
            'cookieParams' => [
                'httponly' => true,
                'samesite' => \yii\web\Cookie::SAME_SITE_LAX,
                'secure' => $isSecureRequest,
            ],
            // session.use_strict_mode is off at the php.ini level here, so
            // PHP would otherwise start a session using any PHPSESSID a
            // client sends, even one it never issued (session fixation).
            // Login already regenerates the ID via switchIdentity(), but
            // strict mode is what stops an attacker-planted ID from being
            // accepted as valid at all, logged in or not. Fixed here via
            // Yii's ini_set() wrapper since php.ini can't be touched
            // without sudo.
            'useStrictMode' => true,
        ],
        'csp' => [
            'class' => 'app\components\Csp',
        ],
        // Hooked on 'response' beforeSend rather than a second top-level
        // 'on beforeRequest' here - common.php already defines one for the
        // DB schema cache, and a plain config array can only hold one
        // handler per event name, so a second key would replace it.
        'response' => [
            'on beforeSend' => function () {
                Yii::$app->csp->applyHeaders();
            },
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return \yii\helpers\ArrayHelper::merge($common, $config);
