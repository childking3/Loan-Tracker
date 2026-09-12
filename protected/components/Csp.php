<?php

namespace app\components;

use Yii;
use yii\base\Component;

/**
 * Generates a per-request CSP nonce and applies the
 * Content-Security-Policy plus a handful of other standard security
 * headers to the response.
 *
 * Nonce technique adapted from HumHub
 * (protected/humhub/modules/web/security/helpers/Security.php, Copyright
 * HumHub GmbH & Co. KG, https://www.humhub.com/licences - see
 * CODEBASE.md's "Borrowed from HumHub" section), but lifetime differs
 * deliberately: HumHub stores its nonce in session and only regenerates it
 * on login, reusing it across a whole session. This generates a fresh
 * nonce every request instead - a nonce only defends against XSS if it
 * can't be predicted or reused across responses.
 *
 * Policy is also deliberately stricter than HumHub's (which wildcards
 * sources and allows 'unsafe-inline' to accommodate arbitrary third-party
 * modules): this app only loads same-origin CSS and one inline script, so
 * no wildcards, no 'unsafe-inline'.
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

        // HSTS is ignored by browsers over plain HTTP (all this dev env
        // serves currently - TLS/PHP-FPM setup is deferred, see
        // CODEBASE.md). Harmless to set now regardless, one less thing to
        // remember once TLS exists.
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
