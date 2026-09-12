<?php

namespace app\models;

use app\components\AvatarStorage;
use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use yii\web\UploadedFile;

/**
 * Identity model backing authentication.
 *
 * Maps to the user table created in Phase 1. Implements IdentityInterface so
 * the web application's user component can authenticate against it.
 *
 * Access-token based authentication is not implemented: this application
 * uses session-based login only, not API tokens, so
 * findIdentityByAccessToken() deliberately throws rather than returning
 * null, to fail loudly if something attempts to use it.
 *
 * rules(), $password, $role, setPassword() and generateAuthKey() were all
 * added in Phase 9 for UserController - this model had none of them before,
 * since every account until now was created directly by a migration
 * (m260910_190700_seed_admin_user, m260910_220000_seed_dummy_data), never
 * through a web form. Both of those migrations set created_at/updated_at by
 * hand for exactly that reason - this model had no TimestampBehavior to do
 * it for them; UserController needs that automatic, so it's added here now,
 * matching Customer's and Loan's own behaviors().
 *
 * The validation rule shape (trim, required, unique, length, a format
 * check) is adapted from HumHub's own user model
 * (protected/humhub/modules/user/models/User.php, Copyright HumHub GmbH &
 * Co. KG, https://www.humhub.com/licences - see CODEBASE.md's "Borrowed
 * from HumHub" section) - not copied, since HumHub's version pulls its
 * username length/regex from a configurable module setting this app has no
 * equivalent of, and validates several fields (guid, timezone, visibility,
 * language) this app's much smaller user table doesn't have at all. The
 * two-state status model (active/inactive) is also deliberately kept
 * instead of adopting HumHub's richer multi-state one (pending approval,
 * disabled-by-admin, etc.) - disproportionate for what is, for this
 * client, a handful of staff accounts managed directly by one admin.
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 10;

    public const ROLE_STAFF = 'staff';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_ADMIN = 'admin';
    public const ROLES = [self::ROLE_STAFF, self::ROLE_MANAGER, self::ROLE_ADMIN];

    public const SCENARIO_CREATE = 'create';

    /**
     * No longer shared with SiteController::actionLogin()'s access-log
     * redaction decision - it used to be, but a shape-only check let a
     * secret/token happening to match this exact pattern (letters, digits,
     * underscores) through into the log verbatim; that method now checks
     * against real existing usernames instead. See its own docblock for
     * the full history.
     */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.]{1,64}$/';

    /**
     * Plain-text password - write-only, never persisted directly (see
     * setPassword(), which hashes it into password_hash). Required only on
     * SCENARIO_CREATE; left blank on an update means "keep the current
     * password", handled by UserController checking for a non-empty value
     * before calling setPassword() again.
     */
    public ?string $password = null;

    /**
     * RBAC role - virtual, not a column (role assignment lives in the
     * framework's own auth_assignment table via Yii::$app->authManager, not
     * on this row). Modeled as a property here anyway so it validates and
     * displays through the same load()/Html::activeDropDownList() flow as
     * every other field, rather than being handled as a raw, unvalidated
     * $_POST read in the controller.
     */
    public ?string $role = null;

    /**
     * Write-only, never persisted directly - saveAvatar() below validates
     * and stores it via AvatarStorage, writing only the resulting random
     * filename to avatar_filename. Optional on every scenario (both
     * create and update leave a picture unset by default), matching
     * $password's own "blank means no change" precedent rather than
     * requiring a picture up front.
     */
    public ?UploadedFile $avatarFile = null;

    public static function tableName(): string
    {
        return '{{%user}}';
    }

    /**
     * Excludes soft-deleted rows by default, matching Customer::find()'s
     * exact pattern (Phase 3) - every normal lookup (findIdentity(),
     * findByUsername(), UserController's staff list, the uniqueness
     * validators on username/email) automatically treats a deleted
     * account as gone, including freeing up its username/email for reuse,
     * without every caller needing to remember to filter it out.
     */
    public static function find(): ActiveQuery
    {
        return parent::find()->andWhere(['deleted_at' => null]);
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['username', 'email', 'full_name'], 'trim'],
            [['username', 'email', 'full_name'], 'required'],
            ['username', 'unique'],
            ['username', 'string', 'min' => 3, 'max' => 64],
            ['username', 'match', 'pattern' => self::USERNAME_PATTERN, 'message' => 'Username may only contain letters, numbers, dots, and underscores.'],
            ['email', 'unique'],
            ['email', 'email'],
            ['email', 'string', 'max' => 255],
            ['full_name', 'string', 'max' => 255],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE]],
            // No password policy existed anywhere in this app before this
            // form - every prior account was seeded with a pre-hashed
            // value. 8 characters is a plain, unremarkable minimum, not a
            // figure from the client brief (which never specifies one) -
            // flagged in the Phase 9 review doc as a judgment call worth
            // confirming, not silently treated as settled.
            ['password', 'required', 'on' => self::SCENARIO_CREATE],
            ['password', 'string', 'min' => 8],
            ['role', 'required'],
            ['role', 'in', 'range' => self::ROLES],
            [
                'avatarFile',
                'file',
                'extensions' => implode(',', AvatarStorage::ALLOWED_EXTENSIONS),
                'maxSize' => AvatarStorage::MAX_SIZE,
                'skipOnEmpty' => true,
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'email' => 'Email',
            'full_name' => 'Full name',
            'password' => 'Password',
            'role' => 'Role',
            'status' => 'Status',
        ];
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    public function generateAuthKey(): void
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('Access token authentication is not supported by this application.');
    }

    public static function findByUsername(string $username): ?self
    {
        return static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey)
    {
        return $this->auth_key === $authKey;
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Stores $file via AvatarStorage under a fresh random filename, points
     * this row at it, then removes whatever file it previously pointed to.
     * Deliberately in that order: if saving the new file or the DB update
     * fails, the old file is left alone rather than the account ending up
     * with no avatar file at all. The caller must still call save() itself
     * to persist avatar_filename beyond this call if it hasn't validated
     * the whole model yet - this method persists only that one attribute,
     * the same narrow-column-save shape softDelete() above already uses.
     */
    public function saveAvatar(UploadedFile $file): void
    {
        $oldFilename = $this->avatar_filename;
        $this->avatar_filename = AvatarStorage::save($file);
        $this->save(false, ['avatar_filename']);
        AvatarStorage::delete($oldFilename);
    }

    /**
     * The second, permanent step of the two-step staff-removal safety
     * mechanism: deactivation (status -> STATUS_INACTIVE, via
     * UserController::actionDeactivate) is the reversible "temporary
     * state" - the account still exists and shows in the staff list with
     * a Reactivate option. This is the other exit from that state, and it
     * is one-way, matching Customer::softDelete()'s own precedent (which
     * likewise has no restore action anywhere in the app): once
     * deleted_at is set, find()'s override hides the row everywhere in
     * the application, including login (on top of the STATUS_INACTIVE
     * check already in place) and the username/email uniqueness checks.
     * The row itself is never actually removed from the database - see
     * this column's own migration for why a real SQL DELETE isn't
     * possible here regardless (loan/repayment/customer foreign keys).
     */
    /**
     * Caught live during testing, not anticipated in advance: username and
     * email both have real, unique indexes at the database level
     * (idx-user-username, idx-user-email - m260910_190000_create_user_table),
     * which know nothing about deleted_at. find()'s override only hides a
     * soft-deleted row from *application*-level uniqueness checks (the
     * UniqueValidator in rules(), and login), so a new account reusing a
     * deleted one's exact username/email still hit a real duplicate-key
     * database error on save() - confirmed directly: creating a fresh user
     * with a just-deleted username threw
     * `SQLSTATE[23000]: ... Duplicate entry ... for key 'idx-user-username'`,
     * not a clean validation message. Renaming both to a disambiguated,
     * clearly-marked value here (rather than leaving them as-is) satisfies
     * the real database constraint while genuinely freeing the original
     * values for reuse. full_name is left untouched, so a deleted account's
     * historical activity_log entries still show a real human name, just
     * under a marked username.
     */
    private const DELETED_MARKER = '_deleted_';

    public function softDelete(): bool
    {
        $timestamp = time();
        $suffix = self::DELETED_MARKER . $timestamp;
        $this->username = substr((string) $this->username, 0, 64 - strlen($suffix)) . $suffix;
        $this->email = substr('deleted_' . $timestamp . '_' . $this->email, 0, 255);
        $this->deleted_at = $timestamp;

        return $this->save(false, ['username', 'email', 'deleted_at']);
    }
}
