<?php

namespace app\models;

use app\components\AvatarStorage;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\web\UploadedFile;

/**
 * ActiveRecord for the customer table.
 *
 * find() excludes soft-deleted rows by default, so every normal lookup
 * (findOne(), listings, relations) treats a deleted customer as gone
 * without each caller filtering it out.
 *
 * phone isn't validated as unique: the brief treats a duplicate as a
 * warning at create time (CustomerController::actionCreate), not a hard
 * constraint - two customers sharing a phone (family, shared business
 * line) is plausible.
 */
class Customer extends ActiveRecord
{
    use HasContactRules;
    use SoftDeletable;
    use HasAssignedStaff;
    use IncludesTrashedRelations;

    /**
     * Offered values for the optional gender field, value => label. Stored
     * as the lowercase key; NULL means "not recorded" (every customer that
     * predates the field).
     */
    public const GENDERS = ['male' => 'Male', 'female' => 'Female'];

    /**
     * Write-only, like User::$avatarFile - savePhoto() stores it and writes
     * only the resulting filename to photo_filename. Optional everywhere.
     *
     * Deliberately untyped (really ?UploadedFile): it is a safe attribute
     * (the file rule below needs that to run), so load() will assign any
     * posted `Customer[photoFile]` string to it, and a typed property would
     * turn that into a TypeError/500. The controller always overwrites it
     * with UploadedFile::getInstance() right after load(), so a posted
     * string never survives to be validated or stored.
     *
     * @var UploadedFile|null
     */
    public $photoFile;

    /**
     * Form checkbox: drop the current photo without uploading a new one.
     * Untyped for the same reason as $photoFile - a typed bool would turn a
     * posted array into a TypeError; the boolean rule rejects it cleanly.
     *
     * @var bool|string|null
     */
    public $removePhoto = false;

    public static function tableName(): string
    {
        return '{{%customer}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return array_merge($this->contactRules(), [
            ['address', 'string'],
            // Blank option posts '' - optional, so default to NULL rather
            // than fail the 'in' range check below.
            ['gender', 'default', 'value' => null],
            ['gender', 'in', 'range' => array_keys(self::GENDERS)],
            [
                'photoFile',
                'file',
                'extensions' => implode(',', AvatarStorage::ALLOWED_EXTENSIONS),
                'maxSize' => AvatarStorage::MAX_SIZE,
                'skipOnEmpty' => true,
            ],
            ['removePhoto', 'boolean'],
            // The dropdown's blank option posts '' - same default-to-null
            // treatment as Market.assigned_staff_id, for the same reason
            // (an optional FK, not a validation failure).
            ['market_id', 'default', 'value' => null],
            ['market_id', 'integer'],
            ['market_id', 'exist', 'targetClass' => Market::class, 'targetAttribute' => 'id'],
        ]);
    }

    public function attributeLabels(): array
    {
        return [
            'full_name' => 'Full name',
            'phone' => 'Phone',
            'address' => 'Address',
            'gender' => 'Gender',
            'photoFile' => 'Photo',
            'removePhoto' => 'Remove current photo',
            'market_id' => 'Market',
        ];
    }

    /**
     * Stores $file under uploads/customers/, points this row at it, then
     * removes the old file - same order and narrow-column save as
     * User::saveAvatar(), so a failure leaves the previous photo intact.
     */
    public function savePhoto(UploadedFile $file): void
    {
        $oldFilename = $this->photo_filename;
        $this->photo_filename = AvatarStorage::save($file, AvatarStorage::FOLDER_CUSTOMERS);
        $this->save(false, ['photo_filename']);
        AvatarStorage::delete($oldFilename, AvatarStorage::FOLDER_CUSTOMERS);
    }

    public function clearPhoto(): void
    {
        $oldFilename = $this->photo_filename;
        $this->photo_filename = null;
        $this->save(false, ['photo_filename']);
        AvatarStorage::delete($oldFilename, AvatarStorage::FOLDER_CUSTOMERS);
    }

    public function getMarket(): ActiveQuery
    {
        return $this->hasOne(Market::class, ['id' => 'market_id']);
    }

    /**
     * The staff member this customer belongs to. Soft-deleted users are
     * still resolved (same reason as Loan::getAssignedStaff()) so a
     * customer whose staff account was deactivated still shows a name
     * rather than a blank owner.
     */

    /**
     * Whether the current logged-in user may view this specific customer -
     * true for anyone holding viewAllCustomers (manager/admin), or for the
     * customer's own assigned staff member (IsAssignedStaffCustomerRule,
     * via viewAssignedCustomers).
     *
     * Deliberately the same shape as Loan::isVisibleToCurrentUser(), and
     * centralized here for the same reason: that check was hand-copied at
     * five call sites before it was consolidated, and two of the copies
     * had already drifted into real bugs. Every customer-visibility
     * decision routes through this one method, so single-record access
     * and the list scoping in Customer::scopeVisible() cannot disagree.
     */
    public function isVisibleToCurrentUser(): bool
    {
        return Yii::$app->user->can('viewAllCustomers')
            || Yii::$app->user->can('viewAssignedCustomers', ['customer' => $this]);
    }

    /**
     * Narrows $query to the customers the current user may see, and is the
     * list-level counterpart of isVisibleToCurrentUser(): one WHERE
     * clause instead of one rule evaluation per row.
     *
     * The two must stay in agreement, so the conditions mirror the rule
     * exactly - owner match, OR a loan assigned to this user (the
     * mismatch case the rule allows), and NULL owner matching neither.
     * A user holding viewAllCustomers is not narrowed at all.
     */
    public static function scopeVisible(ActiveQuery $query): ActiveQuery
    {
        if (Yii::$app->user->can('viewAllCustomers')) {
            return $query;
        }

        $userId = (int) Yii::$app->user->id;

        return $query->andWhere([
            'or',
            ['{{%customer}}.assigned_staff_id' => $userId],
            // new Expression, not the string '1': a plain string here is
            // quoted as a column name and the query fails with "Unknown
            // column '1' in SELECT" (caught by the Phase 2 matrix test).
            ['exists', Loan::find()
                ->select(new Expression('1'))
                ->andWhere('{{%loan}}.customer_id = {{%customer}}.id')
                ->andWhere(['{{%loan}}.assigned_staff_id' => $userId]),
            ],
        ]);
    }

    /**
     * The baseline guarantor captured at registration (see Guarantor's
     * own docblock) - at most one per customer by construction, so hasOne
     * rather than hasMany even though the FK itself doesn't enforce
     * uniqueness beyond "one row per customer_id happens to be the only
     * one ever written".
     */
    public function getGuarantor(): ActiveQuery
    {
        return $this->hasOne(Guarantor::class, ['customer_id' => 'id']);
    }

    /**
     * Inverse of Loan::getCustomer(). Lets ReportController::
     * actionCustomers() eager-load with ->with('loans') instead of
     * running one Loan::find() per customer in a loop - a real N+1 cost
     * that scales with the customer list.
     */
    public function getLoans(): ActiveQuery
    {
        return $this->hasMany(Loan::class, ['customer_id' => 'id']);
    }

    /**
     * Who a customer registered by the current user should belong to -
     * the value every later visibility decision is made against
     * (scopeVisible()/isVisibleToCurrentUser()).
     *
     * Lives on the model, not in a controller, because two entry points
     * create customers (the registration form and CSV import) and they
     * must not drift apart on something this consequential.
     *
     * A staff member always keeps what they just registered: anything else
     * would let them create a customer and immediately lose sight of it,
     * including when registering into a colleague's market. An admin or
     * manager isn't a collector, so their registrations follow the chosen
     * market's assigned staff - the same rule the Phase 1 backfill used.
     * If neither applies, the customer is left unassigned, which means
     * admin/manager-only until somebody assigns it, never
     * visible-to-everyone.
     */
    public static function defaultOwnerForCurrentUser(?int $marketId): ?int
    {
        $userId = (int) Yii::$app->user->id;

        if (isset(User::activeStaffOptions()[$userId])) {
            return $userId;
        }

        if ($marketId === null) {
            return null;
        }

        $market = Market::findOne($marketId);

        return $market === null || $market->assigned_staff_id === null
            ? null
            : (int) $market->assigned_staff_id;
    }

    /**
     * Finds other, non-deleted customers sharing this phone number.
     * Used by CustomerController::actionCreate to warn (not block) on a
     * likely duplicate.
     */
    public function findDuplicatesByPhone(): array
    {
        $query = static::find()->andWhere(['phone' => $this->phone]);
        if ($this->id !== null) {
            $query->andWhere(['<>', 'id', $this->id]);
        }

        return $query->all();
    }
}
