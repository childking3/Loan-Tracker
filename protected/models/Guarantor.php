<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the guarantor table. Not a standalone entity reused
 * across loans - one row per loan, zero or more per loan.
 *
 * loan_id is absent from rules(): GuarantorController::actionCreate()
 * always scopes a new guarantor to the loan in its route, never to
 * posted input, so there's nothing to validate.
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
