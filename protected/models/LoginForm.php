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
    /**
     * Untyped (really ?string): load() assigns whatever was posted, and a
     * typed property made `LoginForm[username][]=x` a TypeError/500 for an
     * unauthenticated request. The string rule below rejects non-strings
     * before validatePassword() ever looks the user up.
     *
     * @var string|null
     */
    public $username;

    /** @var string|null see $username */
    public $password;

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
            [['username', 'password'], 'string'],
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

        if ($this->failedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            $this->addError($attribute, 'Too many failed login attempts. Try again in a few minutes.');
            return;
        }

        $user = $this->getUser();
        $hash = $user !== null ? $user->password_hash : self::DUMMY_PASSWORD_HASH;
        $valid = Yii::$app->security->validatePassword((string) $this->password, $hash);

        if ($user === null || !$valid) {
            $this->recordFailedAttempt();
            $this->addError($attribute, 'Incorrect username or password.');
            return;
        }

        $this->clearFailedAttempts();
    }

    /**
     * The counter lives in the database, not the cache (changed 2026-10-19).
     *
     * The cache-backed version failed OPEN: KeydbCache::run() returns its
     * fallback when the store is unreachable, so the gate read 0 and the
     * increment recorded nothing - measured as 12 unthrottled wrong passwords
     * with redis stopped, while valid logins still worked so nothing looked
     * broken. App node .3 ran with its KeyDB instance dead until 2026-10-19,
     * which means roughly half of all login traffic had no brute-force
     * protection at all. The counter was also per node, making the real limit
     * MAX_FAILED_ATTEMPTS *per node* rather than overall.
     *
     * The database cannot fail open here: a login attempt cannot be evaluated
     * without it, and being Galera-replicated the counter is shared by both
     * nodes. See AUTH_SESSION_REVIEW.md F1.
     */
    private function throttleName(): string
    {
        return strtolower((string) $this->username);
    }

    private function failedAttempts(): int
    {
        return (int) Yii::$app->db->createCommand(
            'SELECT attempts FROM {{%login_attempt}} WHERE username = :name AND expires_at > :now',
            [':name' => $this->throttleName(), ':now' => time()]
        )->queryScalar();
    }

    /**
     * One atomic upsert, so concurrent attempts against the same username
     * cannot lose increments the way a read-then-write would. A window that
     * has already expired restarts at 1 rather than resuming an old count.
     *
     * Distinct :now1/:now2 placeholders because PDO only allows a named
     * parameter to be reused when prepare emulation happens to be on.
     */
    private function recordFailedAttempt(): void
    {
        $now = time();
        $sql = 'INSERT INTO {{%login_attempt}} (username, attempts, expires_at)
                VALUES (:name, 1, :expires)
                ON DUPLICATE KEY UPDATE
                    attempts = IF(expires_at > :now1, attempts + 1, 1),
                    expires_at = IF(expires_at > :now2, expires_at, :expires2)';
        $params = [
            ':name' => $this->throttleName(),
            ':expires' => $now + self::LOCKOUT_SECONDS,
            ':expires2' => $now + self::LOCKOUT_SECONDS,
            ':now1' => $now,
            ':now2' => $now,
        ];

        try {
            Yii::$app->db->createCommand($sql, $params)->execute();
        } catch (\Throwable $e) {
            // Galera certifies writes cluster-wide, so two nodes incrementing
            // the SAME username in the same instant can have one transaction
            // aborted. Retry once; only give up (logged, not fatal) if it
            // fails again, since a 500 on the login form would be worse than a
            // single uncounted attempt.
            try {
                Yii::$app->db->createCommand($sql, $params)->execute();
            } catch (\Throwable $retry) {
                Yii::warning('Could not record failed login attempt: ' . $retry->getMessage(), __METHOD__);
            }
        }

        $this->purgeExpiredAttempts();
    }

    private function clearFailedAttempts(): void
    {
        Yii::$app->db->createCommand(
            'DELETE FROM {{%login_attempt}} WHERE username = :name',
            [':name' => $this->throttleName()]
        )->execute();
    }

    /**
     * Opportunistic, so an attacker cycling through usernames cannot grow the
     * table without bound. Probabilistic rather than scheduled: this app has no
     * reliable cron on both nodes, and the index on expires_at makes the sweep
     * cheap when it does run.
     */
    private function purgeExpiredAttempts(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        try {
            Yii::$app->db->createCommand(
                'DELETE FROM {{%login_attempt}} WHERE expires_at < :now',
                [':now' => time()]
            )->execute();
        } catch (\Throwable $e) {
            Yii::warning('Expired login-attempt purge failed: ' . $e->getMessage(), __METHOD__);
        }
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
