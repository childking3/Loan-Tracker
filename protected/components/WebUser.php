<?php

namespace app\components;

use Yii;
use yii\web\Request as WebRequest;
use yii\web\Response;
use yii\web\User as BaseUser;

/**
 * yii\web\User that knows the difference between a person navigating the site
 * and a background request made on their behalf.
 *
 * "Background" means a poll/XHR that the user did not initiate by clicking
 * something - currently just the dashboard's live poller. Treating those as
 * ordinary navigation caused three separate production bugs (2026-10-19, see
 * AUTH_SESSION_REVIEW.md), all fixed here:
 *
 *  1. The idle timeout could never fire. yii\web\User::renewAuthStatus()
 *     re-arms `authTimeout` on EVERY authenticated request, and the dashboard
 *     polls once a second, so the deadline was renewed perpetually and a
 *     walked-away-but-open dashboard stayed logged in until the 12h absolute
 *     cap. renewAuthStatus() below suppresses only the extension.
 *  2. Logging in dumped the user on raw JSON. loginRequired() stores the
 *     current URL as the post-login destination, so a poll that happened to
 *     be the request which discovered the dead session made
 *     '/dashboard/poll?last=N' the place login sent you.
 *  3. The poller retried forever. It got a 302 to an HTML login page it could
 *     not parse, so it re-fetched once a second indefinitely and flooded the
 *     edge proxy's login rate limiter.
 *
 * Placing (2) and (3) here rather than in a per-controller AccessControl rule
 * is borrowed from HumHub, whose own
 * humhub\modules\user\components\User::loginRequired() curates the return URL
 * centrally per request type. Centralising means any future JSON endpoint is
 * correct by default instead of having to remember an opt-in. HumHub still
 * answers background requests with a redirect to HTML, which is the half that
 * caused (3), so the JSON 401 below is deliberately ours and not theirs.
 * See the cross-reference note in CODEBASE.md.
 */
class WebUser extends BaseUser
{
    /**
     * Routes that are background requests, not user navigation. They keep a
     * session usable but must neither extend its idle life nor become the
     * post-login destination.
     *
     * Matched against the request path, so this must track the route the
     * poller in views/dashboard/index.php actually calls.
     *
     * @var string[]
     */
    public array $backgroundRoutes = ['dashboard/poll'];

    /**
     * Session key holding the `password_changed_at` value the session was
     * established under.
     */
    private const CREDENTIAL_PARAM = '__credentialStamp';

    /**
     * Answers background requests with a JSON 401 and - crucially - never
     * reaches the parent, so the return URL is left alone.
     *
     * Returning a Response rather than throwing matches the parent's own
     * contract (it returns a redirect Response, or throws
     * ForbiddenHttpException only when it cannot redirect).
     */
    public function loginRequired($checkAjax = true, $checkAcceptHeader = true)
    {
        if ($this->isBackgroundRequest()) {
            $response = Yii::$app->getResponse();
            $response->format = Response::FORMAT_JSON;
            $response->statusCode = 401;
            $response->data = ['error' => 'unauthenticated'];

            return $response;
        }

        return parent::loginRequired($checkAjax, $checkAcceptHeader);
    }

    /**
     * Records which credential this session was established under, so a later
     * password change can invalidate it.
     */
    protected function afterLogin($identity, $cookieBased, $duration)
    {
        Yii::$app->getSession()->set(self::CREDENTIAL_PARAM, $this->stampOf($identity));

        parent::afterLogin($identity, $cookieBased, $duration);
    }

    protected function renewAuthStatus()
    {
        $this->renewTimeoutStatus();
        $this->logoutIfCredentialChanged();
    }

    private function renewTimeoutStatus(): void
    {
        if ($this->authTimeout === null || !$this->isBackgroundRequest()) {
            parent::renewAuthStatus();

            return;
        }

        $session = Yii::$app->getSession();
        $deadlineBefore = $session->get($this->authTimeoutParam);

        parent::renewAuthStatus();

        // getIdentity(false), never getIsGuest(): getIsGuest() resolves the
        // identity, which re-enters renewAuthStatus() while it is still unset -
        // infinite recursion. Passing false skips that renewal entirely.
        //
        // The null check also keeps an expired session expired: if the parent
        // just logged the user out there is no identity, and the old deadline
        // is deliberately not written back.
        if ($deadlineBefore !== null && $this->getIdentity(false) !== null) {
            $session->set($this->authTimeoutParam, $deadlineBefore);
        }
    }

    /**
     * Ends the session if the account's password changed after this session was
     * established. yii\web\User's own per-request check only looks at id +
     * status + deleted_at (nothing credential-derived), so before this a
     * password reset did not evict an existing session - the standard response
     * to a compromised account left the intruder with access until the idle or
     * absolute timeout. Verified 2026-10-19; see AUTH_SESSION_REVIEW.md F2.
     *
     * A session carrying no stamp (established before this shipped) adopts the
     * current value rather than being logged out, so deploying this does not
     * sign everyone out; from that point on changes are detected normally.
     */
    private function logoutIfCredentialChanged(): void
    {
        $identity = $this->getIdentity(false);
        if ($identity === null) {
            return;
        }

        $session = Yii::$app->getSession();
        $current = $this->stampOf($identity);

        if (!$session->has(self::CREDENTIAL_PARAM)) {
            $session->set(self::CREDENTIAL_PARAM, $current);

            return;
        }

        if ($session->get(self::CREDENTIAL_PARAM) !== $current) {
            $this->logout(false);
        }
    }

    /**
     * Normalised to a plain int so that (a) a string from the DB driver never
     * compares unequal to the same number already in the session, and (b) the
     * value is never null.
     *
     * (b) is not cosmetic: Session::has() uses isset() semantics and so returns
     * FALSE for a stored null. Storing null for "password never changed" made
     * the check below take its adopt branch on every request and silently
     * re-stamp, so the NULL -> timestamp transition - every user's *first*
     * password change, i.e. exactly the case this feature exists for - was
     * never detected. Caught in local testing 2026-10-19; 0 means "never
     * changed" instead.
     */
    private function stampOf($identity): int
    {
        return (int) ($identity->password_changed_at ?? 0);
    }

    /**
     * A request is "background" when its route is listed above, or when the
     * client declared it an XHR. The explicit list is what makes this
     * predictable - the X-Requested-With check only widens it to anything that
     * self-identifies as AJAX, for which a JSON 401 is the right answer anyway.
     */
    private function isBackgroundRequest(): bool
    {
        $request = Yii::$app->getRequest();
        if (!$request instanceof WebRequest) {
            return false;
        }

        if (in_array(trim($request->getPathInfo(), '/'), $this->backgroundRoutes, true)) {
            return true;
        }

        return $request->getIsAjax();
    }
}
