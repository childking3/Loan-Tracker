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
 * Identity model backing authentication (IdentityInterface).
 *
 * Session-based login only, no API tokens - findIdentityByAccessToken()
 * throws rather than returning null, to fail loudly if ever called.
 *
 * Validation rule shape (trim, required, unique, length, format check) is
 * adapted from HumHub's own user model
 * (protected/humhub/modules/user/models/User.php, Copyright HumHub GmbH &
 * Co. KG, https://www.humhub.com/licences - see CODEBASE.md's "Borrowed
 * from HumHub" section), not copied: HumHub pulls its username rules from
 * a configurable module setting this app doesn't have, and validates
 * fields (guid, timezone, visibility, language) this table lacks. The
 * two-state status model (active/inactive) is kept instead of HumHub's
 * richer multi-state one - disproportionate for a handful of staff
 * accounts managed by one admin.
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
     * No longer shared with SiteController::actionLogin()'s log-redaction
     * check - a shape-only match let a secret/token happen to pass this
     * same pattern through into the log; that method now checks against
     * real usernames instead.
     */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.]{1,64}$/';

    /**
     * Write-only, never persisted directly - setPassword() hashes it into
     * password_hash. Required only on SCENARIO_CREATE; blank on update
     * means "keep current password" (UserController checks for a
     * non-empty value before calling setPassword() again).
     */
    public ?string $password = null;

    /**
     * RBAC role - virtual, not a column (assignment lives in
     * auth_assignment via Yii::$app->authManager). Modeled as a property
     * so it validates and renders through the normal load()/dropdown flow
     * instead of a raw, unvalidated $_POST read.
     */
    public ?string $role = null;

    /**
     * Write-only - saveAvatar() stores it via AvatarStorage and writes
     * only the resulting filename to avatar_filename. Optional on every
     * scenario, matching $password's "blank means no change" precedent.
     */
    public ?UploadedFile $avatarFile = null;

    public static function tableName(): string
    {
        return '{{%user}}';
    }

    /**
     * Excludes soft-deleted rows by default, matching Customer::find() -
     * every lookup (findIdentity, findByUsername, uniqueness validators)
     * treats a deleted account as gone and frees its username/email.
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
            // 8 characters is an arbitrary minimum, not from the client
            // brief (which specifies none) - flagged as a judgment call
            // worth confirming, not settled policy.
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
     * Stores $file via AvatarStorage, points this row at it, then removes
     * the old file - in that order, so a failure leaves the old avatar
     * intact rather than the account ending up with none. Persists only
     * avatar_filename; same narrow-column-save shape as softDelete().
     */
    public function saveAvatar(UploadedFile $file): void
    {
        $oldFilename = $this->avatar_filename;
        $this->avatar_filename = AvatarStorage::save($file);
        $this->save(false, ['avatar_filename']);
        AvatarStorage::delete($oldFilename);
    }

    /**
     * Permanent second step after deactivation (status -> STATUS_INACTIVE),
     * which is reversible via UserController::actionDeactivate's Reactivate
     * option - this step is one-way, matching Customer::softDelete().
     *
     * username/email carry real unique DB indexes that don't know about
     * deleted_at, so find()'s override alone isn't enough: reusing a
     * just-deleted username/email would hit a raw duplicate-key SQL error
     * instead of a validation message. Rewriting both to a marked value
     * here satisfies the DB constraint and frees the originals for reuse;
     * full_name stays untouched so old activity_log entries keep a real
     * name.
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
