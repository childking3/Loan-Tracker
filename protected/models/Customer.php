<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * ActiveRecord for the customer table.
 *
 * find() is overridden to exclude soft-deleted rows (deleted_at IS NOT
 * NULL) by default, so every normal lookup - findOne(), the index listing,
 * relations from Loan in a later phase - automatically behaves as if
 * deleted customers do not exist, without every caller having to remember
 * to filter them out.
 *
 * phone is intentionally not validated as unique here: the client brief
 * treats a duplicate phone number as a warning to surface at create time
 * (see CustomerController::actionCreate), not a hard constraint, since two
 * customers legitimately sharing a phone number is plausible (family
 * members, shared business lines).
 */
class Customer extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%customer}}';
    }

    public static function find(): ActiveQuery
    {
        return parent::find()->andWhere(['deleted_at' => null]);
    }

    /**
     * The inverse of find()'s own default scope - every soft-deleted row,
     * for CustomerController's trash/restore screens. Has to go through
     * parent::find() directly rather than find()->andWhere(...): find()
     * already bakes in `deleted_at IS NULL`, and andWhere() only ever adds
     * another condition on top of what's there, never replaces one.
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
            [['full_name', 'phone'], 'required'],
            ['full_name', 'string', 'max' => 255],
            ['phone', 'string', 'max' => 20],
            ['phone', 'match', 'pattern' => '/^[0-9+\-\s()]{7,20}$/', 'message' => 'Enter a valid phone number.'],
            ['address', 'string'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'full_name' => 'Full name',
            'phone' => 'Phone',
            'address' => 'Address',
        ];
    }

    /**
     * Inverse of Loan::getCustomer(). Added for
     * ReportController::actionCustomers() to eager-load with
     * ->with('loans') instead of running one Loan::find() per customer in
     * a loop - confirmed live during a pentest review that the loop
     * version took ~380ms against ~2,000 customers (test data left over
     * from an unrelated import test), a real, measurable N+1 cost that
     * scales with the customer list.
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
