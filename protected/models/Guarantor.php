<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the guarantor table.
 *
 * One row per loan (see m260911_154500_create_guarantor_table's own
 * docblock for why this isn't a customer-like standalone entity reused
 * across loans) - a loan may have zero or more.
 *
 * loan_id is deliberately absent from rules() the same way Loan's own
 * customer_id is set server-side by the controller rather than trusted
 * from posted input - GuarantorController::actionCreate() always scopes
 * a new guarantor to the loan named in its route, never to a posted
 * value, so there's nothing to validate here.
 */
class Guarantor extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%guarantor}}';
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
            ['relationship', 'string', 'max' => 100],
            ['occupation', 'string', 'max' => 150],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'full_name' => 'Full name',
            'phone' => 'Phone',
            'address' => 'Address',
            'relationship' => 'Relationship to borrower',
            'occupation' => 'Occupation',
        ];
    }

    public function getLoan(): ActiveQuery
    {
        return $this->hasOne(Loan::class, ['id' => 'loan_id']);
    }
}
