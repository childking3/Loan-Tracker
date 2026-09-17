<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the market table: a trading market/territory, with at
 * most one staff member assigned at a time (assigned_staff_id, nullable -
 * "Unassigned" is a normal state, not an error - see the market table
 * migration's docblock).
 *
 * Unlike LoanPackage (list/update only, five fixed rows), Market supports
 * full create/update - the business opens new markets over time. is_active
 * retires one temporarily without breaking customer.market_id (RESTRICT) -
 * deleted_at (below) is the separate, further step of removing it from
 * every normal listing, same two-state distinction as Customer.
 *
 * find() excludes soft-deleted rows by default, same as Customer's - every
 * normal lookup (findOne(), listings, relations) treats a deleted market as
 * gone without each caller filtering it out.
 */
class Market extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%market}}';
    }

    public static function find(): ActiveQuery
    {
        return parent::find()->andWhere(['deleted_at' => null]);
    }

    /**
     * Inverse of find()'s default scope, for the trash/restore screens.
     * Goes through parent::find() directly, not find()->andWhere(...) -
     * andWhere() only adds to find()'s existing `deleted_at IS NULL`,
     * never replaces it.
     */
    public static function findTrashed(): ActiveQuery
    {
        return parent::find()->andWhere(['not', ['deleted_at' => null]]);
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
            [['name'], 'trim'],
            [['name'], 'required'],
            ['name', 'string', 'max' => 100],
            ['area', 'string', 'max' => 255],
            // The dropdown's blank "Unassigned" option posts '' - default
            // converts that to null before integer/exist run, rather than
            // '' hitting the INT column directly (assigned_staff_id is a
            // real optional state, not a validation failure - see the
            // market table migration's docblock).
            ['assigned_staff_id', 'default', 'value' => null],
            ['assigned_staff_id', 'integer'],
            ['assigned_staff_id', 'exist', 'targetClass' => User::class, 'targetAttribute' => 'id', 'filter' => ['status' => User::STATUS_ACTIVE]],
            ['assigned_staff_id', 'validateIsStaff'],
            ['is_active', 'boolean'],
        ];
    }

    /**
     * Same gap and fix as Loan::validateIsStaff(): the create/update form's
     * dropdown (User::activeStaffOptions()) only offers staff-role users,
     * but that alone doesn't stop a manager/admin from POSTing another
     * manager/admin's id directly - the 'exist' rule above only confirms
     * the id belongs to *some* active user, not a staff one. Skips when
     * empty (unlike Loan's, where assigned_staff_id is required) - a
     * market being unassigned is a valid, common state.
     */
    public function validateIsStaff(string $attribute): void
    {
        if ($this->hasErrors($attribute) || $this->$attribute === null) {
            return;
        }

        if (!User::isStaffId((int) $this->$attribute)) {
            $this->addError($attribute, 'Assigned staff must be an active staff-role account.');
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Market name',
            'area' => 'Area',
            'assigned_staff_id' => 'Assigned staff',
            'is_active' => 'Active',
        ];
    }

    public function getAssignedStaff(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'assigned_staff_id']);
    }

    /**
     * Inverse of Customer.market_id - lets actionIndex() eager-load a
     * per-market customer count without one query per row.
     */
    public function getCustomers(): ActiveQuery
    {
        return $this->hasMany(Customer::class, ['market_id' => 'id']);
    }

    /**
     * @return static[]
     */
    public static function activeMarkets(): array
    {
        return static::find()
            ->andWhere(['is_active' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();
    }

    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save(false, ['deleted_at']);
    }
}
