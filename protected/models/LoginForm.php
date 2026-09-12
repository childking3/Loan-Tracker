<?php

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Validates login credentials and, once valid, logs the user in.
 *
 * Kept separate from the User ActiveRecord: this is a form model, not a
 * database record, following the standard Yii2 pattern of keeping input
 * validation apart from persistence.
 */
class LoginForm extends Model
{
    public ?string $username = null;
    public ?string $password = null;

    /**
     * A bcrypt hash of an arbitrary, unused string (cost 13, matching the
     * cost real user hashes are generated with - see
     * m260910_190700_seed_admin_user.php). Not a real credential for
     * anything; its only purpose is to give
     * yii\base\Security::validatePassword() a hash to spend real CPU time
     * against when the submitted username does not exist, so that case
     * takes the same time as a real "wrong password" check instead of
     * returning almost instantly - see the docblock on validatePassword()
     * below for what this defends against.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$13$sE3rMZ08RRU96NLcgQaMjOAhbUjCTM7.WqtrfapOZpUmJm6rzjqXe';

    /**
     * Login throttling: after this many failed attempts against one
     * submitted username, further attempts are rejected for
     * LOCKOUT_SECONDS without even checking the password - confirmed
     * during a pentest pass that nothing stopped an unlimited number of
     * password guesses against a known account. Keyed by the submitted
     * username string itself (not by IP, and not only by real usernames)
     * so a nonexistent username that gets hammered locks out the same
     * way a real one does - this matters because it means the lockout
     * check itself cannot be used to tell a real username from a fake
     * one by timing, the same class of leak validatePassword() below
     * already guards against for the password check itself.
     */
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    private ?User $_user = null;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            ['password', 'validatePassword'],
        ];
    }

    /**
     * Reports one generic error on failure rather than distinguishing
     * "unknown username" from "wrong password", so a failed login attempt
     * cannot be used to enumerate valid usernames via the error message.
     *
     * The message alone is not enough, though: bcrypt verification is
     * deliberately slow (this app's hashes use cost 13), and skipping it
     * entirely for an unknown username - as a naive `$user === null ||
     * !$user->validatePassword(...)` short-circuit would - makes an
     * unknown-username response return in a few milliseconds while a
     * known-username-wrong-password response takes several hundred, a gap
     * large and consistent enough to enumerate valid usernames by timing
     * alone, confirmed live during a pentest pass (real user: ~600-800ms,
     * nonexistent user: ~8-11ms, every time). Always running a bcrypt
     * comparison - against a real hash when the user exists, against
     * DUMMY_PASSWORD_HASH when it does not - keeps both paths doing the
     * same expensive work regardless of outcome.
     */
    public function validatePassword(string $attribute): void
    {
        if ($this->hasErrors()) {
            return;
        }

        $throttleKey = $this->throttleKey();
        // getCounter(), not get(): this key is written by increment()'s
        // raw Redis INCR, not cache->set()'s serialized format - see
        // app\components\KeydbCache::getCounter()'s docblock for the
        // fatal error calling get() against it caused when this was
        // tested live.
        if (Yii::$app->cache->getCounter($throttleKey) >= self::MAX_FAILED_ATTEMPTS) {
            $this->addError($attribute, 'Too many failed login attempts. Try again in a few minutes.');
            return;
        }

        $user = $this->getUser();
        $hash = $user !== null ? $user->password_hash : self::DUMMY_PASSWORD_HASH;
        $valid = Yii::$app->security->validatePassword((string) $this->password, $hash);

        if ($user === null || !$valid) {
            // Atomic increment (see app\components\KeydbCache::increment) -
            // not get()-then-set(), which raced under concurrent attempts
            // against the same username (see that method's docblock).
            Yii::$app->cache->increment($throttleKey, self::LOCKOUT_SECONDS);
            $this->addError($attribute, 'Incorrect username or password.');
            return;
        }

        Yii::$app->cache->delete($throttleKey);
    }

    private function throttleKey(): string
    {
        return 'login-attempts:' . strtolower((string) $this->username);
    }

    public function login(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        return Yii::$app->user->login($this->getUser());
    }

    private function getUser(): ?User
    {
        if ($this->_user === null) {
            $this->_user = User::findByUsername((string) $this->username);
        }

        return $this->_user;
    }
}
