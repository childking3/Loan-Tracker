<?php

namespace app\components;

use Yii;
use yii\base\Component;

/**
 * Generates a per-request CSP nonce and applies the
 * Content-Security-Policy plus a handful of other standard security
 * headers to the response.
 *
 * The nonce-generation technique (random_bytes + base64_encode) is
 * adapted from HumHub's own security module
 * (protected/humhub/modules/web/security/helpers/Security.php, Copyright
 * HumHub GmbH & Co. KG, https://www.humhub.com/licences - see
 * CODEBASE.md's "Borrowed from HumHub" section). Its lifetime is
 * deliberately different: HumHub stores its nonce in the PHP session and
 * only regenerates it on login, reusing the same value across every
 * request in a session. This class generates a fresh nonce every request
 * instead - an ordinary instance property is enough to scope it that way,
 * since this application has no persistent worker process; a brand new
 * object graph is built from scratch on every request regardless. A CSP
 * nonce is only a meaningful anti-XSS control if it cannot be predicted or
 * reused across responses; reusing one for a whole session widens the
 * window an attacker who obtained it through any other, unrelated leak
 * would have to exploit it.
 *
 * The policy itself is also deliberately stricter than HumHub's, which
 * uses wildcarded sources and 'unsafe-inline' on script-src/style-src -
 * appropriate for a platform that has to accommodate arbitrary
 * third-party modules, not appropriate here. This application only ever
 * loads its own same-origin CSS and one inline script, so the policy below
 * has no wildcards and no 'unsafe-inline'.
 */
class Csp extends Component
{
    private ?string $nonce = null;

    public function getNonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = base64_encode(random_bytes(18));
        }

        return $this->nonce;
    }

    public function applyHeaders(): void
    {
        $headers = Yii::$app->response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // Strict-Transport-Security only takes effect on a response served
        // over HTTPS - browsers ignore it entirely over plain HTTP, which
        // is all this dev environment currently serves (see CODEBASE.md's
        // Phase 9 note on TLS/PHP-FPM being deferred). Harmless to set now
        // regardless, and one less thing to remember once TLS exists.
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$this->getNonce()}'",
            "style-src 'self'",
            "img-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
    }
}
