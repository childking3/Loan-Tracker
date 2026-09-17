<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

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

    public static function tableName(): string
    {
        return '{{%customer}}';
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
        return array_merge($this->contactRules(), [
            ['address', 'string'],
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
            'market_id' => 'Market',
        ];
    }

    public function getMarket(): ActiveQuery
    {
        return $this->hasOne(Market::class, ['id' => 'market_id']);
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

    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save(false, ['deleted_at']);
    }
}
