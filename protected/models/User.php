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
     *
     * $password and $role are untyped (really ?string): both are safe
     * attributes, and a typed property made a posted array a TypeError/500
     * instead of a validation error. The string / in-range rules reject it.
     *
     * @var string|null
     */
    public $password;

    /**
     * RBAC role - virtual, not a column (assignment lives in
     * auth_assignment via Yii::$app->authManager). Modeled as a property
     * so it validates and renders through the normal load()/dropdown flow
     * instead of a raw, unvalidated $_POST read.
     */
    public $role;

    /**
     * Write-only - saveAvatar() stores it via AvatarStorage and writes
     * only the resulting filename to avatar_filename. Optional on every
     * scenario, matching $password's "blank means no change" precedent.
     *
     * Untyped (really ?UploadedFile) on purpose: it is a safe attribute, so
     * load() assigns any posted `User[avatarFile]` string to it, and a typed
     * property turned a crafted POST into a TypeError/500. Controllers
     * always overwrite it with UploadedFile::getInstanceByName() AFTER
     * load(), so a posted string never reaches validation or storage.
     *
     * @var UploadedFile|null
     */
    public $avatarFile;

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

    /**
     * Live and soft-deleted accounts together, for relations that must keep
     * showing a name on historical records (a loan whose assigned staff
     * member was later deleted). Never use for login or uniqueness checks.
     */
    public static function findWithDeleted(): ActiveQuery
    {
        return parent::find();
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
        // Stamped here, the single choke point for every password change
        // (UserController create + update both route through this), so that
        // app\components\WebUser can log out sessions established under the
        // old credential. Without it, resetting a compromised account's
        // password left the intruder logged in - see AUTH_SESSION_REVIEW.md F2.
        $this->password_changed_at = time();
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

    /**
     * Active users holding the RBAC 'staff' role specifically, keyed by id
     * for use as a select-input option list. Deliberately narrower than
     * "any active user": every "assigned staff" concept in this app (loan
     * assignment, market assignment, the staff performance report) assumes
     * a true staff-role account, not a manager/admin one who happens to be
     * active. Was LoanController::staffOptions() (private, loan-only);
     * promoted here once MarketController needed the exact same query.
     *
     * @return array<int, string> user id => full name
     */
    public static function activeStaffOptions(): array
    {
        $staffIds = Yii::$app->authManager->getUserIdsByRole(self::ROLE_STAFF);
        if ($staffIds === []) {
            return [];
        }

        $options = [];
        foreach (static::find()->andWhere(['status' => self::STATUS_ACTIVE, 'id' => $staffIds])->orderBy(['full_name' => SORT_ASC])->all() as $user) {
            $options[$user->id] = $user->full_name;
        }

        return $options;
    }

    /**
     * @return array<int, int>
     */
    public static function adminIds(): array
    {
        return array_map('intval', Yii::$app->authManager->getUserIdsByRole(self::ROLE_ADMIN));
    }

    /**
     * @return array<int, int>
     */
    public static function managerIds(): array
    {
        return array_map('intval', Yii::$app->authManager->getUserIdsByRole(self::ROLE_MANAGER));
    }

    /**
     * A ' AND <alias>.id NOT IN (...)' SQL fragment (empty string if there
     * are no admins/managers) plus its bound parameters, for excluding
     * management accounts from a raw staff-performance query. Renamed and
     * widened from the admin-only excludeAdminsSql() during the
     * 2026-09-18 security audit + client decision: a manager who
     * personally records a repayment or gets a loan assigned was showing
     * up in staff-performance data/drill-downs the same as a plain staff
     * member - the docblock's own original reasoning ("tracks loan
     * officers doing collections, not the accounts managing them") always
     * implied this, it just hadn't been applied to managers, only admins.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function excludeManagementSql(string $alias = 'u'): array
    {
        $excludedIds = array_merge(self::adminIds(), self::managerIds());
        if ($excludedIds === []) {
            return ['', []];
        }

        $params = [];
        $placeholders = [];
        foreach ($excludedIds as $index => $excludedId) {
            $placeholder = ':mgmt' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $excludedId;
        }

        return [' AND ' . $alias . '.id NOT IN (' . implode(',', $placeholders) . ')', $params];
    }

    /**
     * Shared staff-performance query, consolidated from two identical
     * copies (DashboardCache::computeTotals(), ReportController::
     * actionStaff()) found by the 2026-09-18 duplicate-function audit.
     * Returns the superset of columns - each caller reads whichever it
     * needs and ignores the rest (Dashboard ignores overdue_loans, Report
     * ignores id). Correlated subqueries rather than a JOIN + GROUP BY: a
     * staff member with multiple loans and multiple repayments would
     * inflate both counts through the JOIN's cross-product before
     * aggregation.
     *
     * @return array<int, array{id: string, full_name: string, active_loans: string, overdue_loans: string, total_collected: string}>
     */
    public static function staffPerformanceRows(): array
    {
        [$excludeManagement, $managementParams] = self::excludeManagementSql('u');
        $params = array_merge([
            ':active' => 'active',
            ':overdue' => 'overdue',
            ':statusActive' => self::STATUS_ACTIVE,
        ], $managementParams);

        return Yii::$app->db->createCommand(
            'SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM {{%loan}} l WHERE l.assigned_staff_id = u.id AND l.status = :active) AS active_loans,
                    (SELECT COUNT(*) FROM {{%loan}} l WHERE l.assigned_staff_id = u.id AND l.status = :overdue) AS overdue_loans,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM {{%repayment}} r WHERE r.recorded_by_staff_id = u.id) AS total_collected
             FROM {{%user}} u
             WHERE u.status = :statusActive' . $excludeManagement . '
             ORDER BY u.full_name'
        )->bindValues($params)->queryAll();
    }

    /**
     * Whether $id belongs to a user holding the staff role - deliberately
     * *not* filtered by active status, matching the two validators this
     * consolidates (Loan::validateIsStaff(), Market::validateIsStaff()),
     * which never applied that filter either. Not the same check as
     * activeStaffOptions() for that reason - this only confirms role
     * membership, the same thing `getUserIdsByRole()` alone has always
     * checked here, so consolidating the two duplicates keeps their
     * existing behavior rather than quietly tightening it.
     */
    public static function isStaffId(?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        $staffIds = Yii::$app->authManager->getUserIdsByRole(self::ROLE_STAFF);
        return in_array((string) $id, array_map('strval', $staffIds), true);
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
