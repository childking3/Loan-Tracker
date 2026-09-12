<?php
/**
 * Web app config.
 */

$common = require __DIR__ . '/common.php';

// The reverse proxy's trusted subnets, if this deployment sits behind
// one - confirmed permanent as of 2026-09-11, not just a temporary
// tunnel, and reachable from more than one address on more than one
// local subnet (10.10.2.2 and 10.10.10.2 both seen) - a single exact
// trusted IP doesn't scale to that, so this trusts whole subnets
// instead (see TRUSTED_PROXY_CIDRS's own comment in .env for why
// full subnets rather than each individual address). Kept as env
// config, not hardcoded, so adding/changing a trusted subnet is a
// one-line .env edit rather than a code change. Empty by default (see
// .env.example) - blank means nothing is trusted, the safe default
// when there's no proxy.
$trustedProxyCidrs = parseCidrList(env('TRUSTED_PROXY_CIDRS', ''));

// Whether this specific request genuinely arrived over HTTPS, used below
// to mark the session/CSRF cookies Secure only when that's actually true
// - a Secure cookie is silently dropped by the browser on a plain HTTP
// connection, so this can't just be hardcoded true even though
// APP_HOST_INFO is pinned to an https:// URL: that pinning is a fixed
// label for URL generation, not a claim that every request reaching
// this PHP process arrived over TLS. This app has no direct TLS itself -
// real termination happens at the reverse proxy (see CODEBASE.md's
// "Public exposure discovered" note) - so PHP's own $_SERVER['HTTPS']
// is never set even for genuinely secure traffic; the proxy signals the
// original scheme via X-Forwarded-Proto instead. Trusted only when
// REMOTE_ADDR falls inside one of $trustedProxyCidrs (via ipInCidr(),
// see env.php), not from the header alone - anyone could send an
// X-Forwarded-Proto header directly to this box otherwise. Inspired by
// HumHub's own dynamic secure-cookie computation (CookieBuilder::build(),
// checked against isSecureConnection) but implemented directly against
// $_SERVER here instead of adopting its full Request/Cookie DI override
// - this app only needs the one resulting boolean, not a global
// cookie-construction hook. Computed independently of the
// 'request.trustedHosts' config below rather than reading
// Yii::$app->request->isSecure, since this value is needed to build the
// very config the request component is constructed from - Yii::$app
// doesn't exist yet at this point in the bootstrap.
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
        // hostInfo is pinned to a fixed, trusted value (APP_HOST_INFO in
        // .env) rather than left to Yii's default of trusting the
        // incoming Host header. This vhost has no ServerName set and is
        // the only vhost bound to its port, so Apache accepts any Host
        // header and hands the request straight to this app - without
        // this override, an attacker-chosen Host is reflected verbatim
        // into every absolute URL the app generates, including the
        // login-redirect Location header (confirmed live: a request sent
        // with Host: evil-attacker.com came back with
        // Location: http://evil-attacker.com/site/login). Pinning
        // hostInfo here neutralizes that regardless of what the web
        // server does with the header. See CODEBASE.md's Phase 9 section
        // for the full write-up.
        'request' => [
            'cookieValidationKey' => env('COOKIE_VALIDATION_KEY'),
            'hostInfo' => env('APP_HOST_INFO'),
            // Trust X-Forwarded-For/X-Forwarded-Proto only from inside
            // the reverse proxy's own trusted subnets ($trustedProxyCidrs
            // above - Yii's own trustedHosts matching understands CIDR
            // notation directly, no custom matching needed here unlike
            // the pre-bootstrap $isSecureRequest check above), so
            // Yii::$app->request->userIP (used by AuditLogger for every
            // access-log row - see AuditLogger::access()) resolves the
            // real visiting client's IP instead of the proxy's own.
            // Confirmed live this matters: every proxied request's
            // REMOTE_ADDR is the proxy's address, not the visitor's -
            // without this, every audit-log row would show the same IP
            // regardless of who actually connected. Deliberately NOT
            // trusting X-Forwarded-Host here even though it's part of
            // Yii's own default secureHeaders set for a trusted host -
            // hostInfo is already pinned above via APP_HOST_INFO
            // specifically so nothing needs to trust an incoming
            // host-related header at all.
            'trustedHosts' => array_fill_keys($trustedProxyCidrs, ['X-Forwarded-For', 'X-Forwarded-Proto']),
            // The session cookie already got an explicit SameSite=Lax during
            // the manual pentest pass (see the 'session' component below);
            // the _csrf cookie never did, since it wasn't the one flagged at
            // the time. Same reasoning applies to it - Yii's own default is
            // only ['httpOnly' => true], no SameSite, which just falls back
            // to the browser's own default (also Lax in every modern
            // browser) rather than declaring it, so this is a
            // belt-and-suspenders fix for an already-mitigated gap, not a
            // live vulnerability closed.
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
            // 30-minute idle timeout, at the user's explicit request as a
            // security baseline (not derived from the brief) - no absolute
            // timeout for now, only idle. yii\web\User enforces this itself
            // on every authenticated request by comparing against a
            // session-stored timestamp it refreshes on each request, so no
            // separate cron/session-GC mechanism is needed for it to take
            // effect - confirmed by reading yii\web\User::renewAuthStatus(),
            // not assumed from the property's docblock alone.
            'authTimeout' => 1800,
        ],
        // File-based session storage, kept inside the project's own runtime
        // directory rather than PHP's default system-wide session path, so
        // session data for this app is easy to find and clear in dev, and
        // isolated from other applications sharing the same host.
        'session' => [
            'class' => 'yii\web\Session',
            'savePath' => '@runtime/sessions',
            // Explicit SameSite=Lax, matching the _csrf cookie Yii already
            // sets this way. Modern browsers default an unmarked cookie to
            // Lax anyway, but a pentest pass flagged the session cookie as
            // the one cookie in the app with no explicit SameSite - this
            // makes the behavior explicit and audit-proof rather than
            // relying on browser defaults.
            'cookieParams' => [
                'httponly' => true,
                'samesite' => \yii\web\Cookie::SAME_SITE_LAX,
                'secure' => $isSecureRequest,
            ],
            // session.use_strict_mode is off at the php.ini level on this
            // box (confirmed via `php -i` during the Phase 9 pass) - with
            // it off, PHP will happily start a session using whatever
            // PHPSESSID value a client sends, even one it never issued
            // itself. That's the other half of session fixation beyond
            // what switchIdentity()'s regenerateID(true) call already
            // covers on login (see yii\web\User::switchIdentity -
            // confirmed by reading the vendored framework source, not
            // assumed): regeneration on login protects against reusing an
            // ID the attacker planted before authentication, but strict
            // mode is what stops the planted ID from being accepted as a
            // valid, initialized session at all, at any point, logged in
            // or not. Yii's Session class exposes this as a plain
            // ini_set() wrapper (see yii\web\Session::setUseStrictMode()),
            // so it's fixable here in code without touching php.ini or
            // needing sudo.
            'useStrictMode' => true,
        ],
        'csp' => [
            'class' => 'app\components\Csp',
        ],
        // Security headers are applied here, as a hook on the 'response'
        // component's own beforeSend event, rather than a second top-level
        // 'on beforeRequest' handler in this file: common.php (shared with
        // the console app) already defines one 'on beforeRequest' handler
        // for the DB schema cache, and a plain config array can only carry
        // one handler per event name - a second 'on beforeRequest' key
        // here would silently replace that one instead of running
        // alongside it. 'response' isn't configured anywhere else, so this
        // has no such collision risk.
        'response' => [
            'on beforeSend' => function () {
                Yii::$app->csp->applyHeaders();
            },
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return \yii\helpers\ArrayHelper::merge($common, $config);
