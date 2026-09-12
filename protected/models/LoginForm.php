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
     * Bcrypt hash of an arbitrary string, not a real credential - cost 13
     * to match real user hashes. Used so an unknown-username check spends
     * the same CPU time as a real "wrong password" check (see
     * validatePassword() below) instead of returning instantly.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$13$sE3rMZ08RRU96NLcgQaMjOAhbUjCTM7.WqtrfapOZpUmJm6rzjqXe';

    /**
     * Login throttling - after this many failed attempts, further ones
     * are rejected for LOCKOUT_SECONDS without checking the password.
     * Keyed by the submitted username string itself, real or not, so
     * the lockout behavior can't be used to enumerate valid usernames
     * the same way timing could (see validatePassword() below).
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
     * Reports one generic error rather than distinguishing "unknown
     * username" from "wrong password", so failed logins can't enumerate
     * usernames via the message.
     *
     * The message alone isn't enough: bcrypt is deliberately slow (cost
     * 13), so a naive `$user === null || !$user->validatePassword(...)`
     * short-circuit would make an unknown-username response return almost
     * instantly while a real-user check takes hundreds of ms - a timing
     * gap that enumerates usernames just as effectively. Always running a
     * bcrypt comparison, against DUMMY_PASSWORD_HASH when the user doesn't
     * exist, keeps both paths equally slow.
     */
    public function validatePassword(string $attribute): void
    {
        if ($this->hasErrors()) {
            return;
        }

        $throttleKey = $this->throttleKey();
        // getCounter(), not get(): this key is written by increment()'s
        // raw Redis INCR, not cache->set()'s serialized format - calling
        // get() against it throws (see KeydbCache::getCounter()).
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
