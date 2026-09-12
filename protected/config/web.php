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
// session/CSRF cookies Secure only when true (a Secure cookie is silently
// dropped over plain HTTP, so APP_HOST_INFO being pinned to https:// isn't
// enough - this app has no TLS of its own, the proxy terminates it and
// signals the original scheme via X-Forwarded-Proto). Trusted only when
// REMOTE_ADDR is inside $trustedProxyCidrs, not from the header alone, or
// anyone could spoof it directly. Computed by hand against $_SERVER
// (not via 'request.trustedHosts' below / Yii::$app->request->isSecure)
// because Yii::$app doesn't exist yet at this point in the bootstrap.
$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isFromTrustedProxy = false;
foreach ($trustedProxyCidrs as $cidr) {
    if (ipInCidr($remoteAddr, $cidr)) {
        $isFromTrustedProxy = true;
        break;
    }
}
$isSecureRequest = $isFromTrustedProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

$config = [
    'id' => 'loantracker',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@app' => dirname(__DIR__),
        '@webroot' => dirname(__DIR__, 2),
        '@web' => '',
        '@runtime' => dirname(__DIR__) . '/runtime',
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
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => false,
            'loginUrl' => ['site/login'],
            // 30-minute idle timeout (explicit security baseline, not from
            // the brief). yii\web\User enforces it itself per request via a
            // session-stored timestamp, so no separate cron/GC is needed.
            'authTimeout' => 1800,
        ],
        // File-based sessions under the project's own runtime dir (not
        // PHP's system-wide path) so session data is easy to find/clear in
        // dev and isolated from other apps on the host.
        'session' => [
            'class' => 'yii\web\Session',
            'savePath' => '@runtime/sessions',
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
