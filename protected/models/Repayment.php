<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the repayment table: an append-only ledger. There is no
 * update or delete action anywhere in this application for this model, by
 * design - correcting a mistaken entry means recording a new row, not
 * editing history. Accordingly TimestampBehavior only touches created_at
 * (the table has no updated_at column at all).
 */
class Repayment extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%repayment}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => ['created_at'],
                ],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['loan_id', 'amount', 'payment_date'], 'required'],
            ['loan_id', 'integer'],
            ['amount', 'number', 'min' => 0.01],
            ['payment_date', 'date', 'format' => 'php:Y-m-d'],
            // Upper bound only, to stop a future-dated payment from
            // corrupting date-range reports or making getRemainingBalance()
            // show a loan as paid off early. No lower bound - a payment
            // entered a day or two late is normal and shouldn't be blocked.
            ['payment_date', 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '<=', 'message' => 'Payment date cannot be in the future.'],
            ['loan_id', 'exist', 'targetClass' => Loan::class, 'targetAttribute' => 'id'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'amount' => 'Amount',
            'payment_date' => 'Payment date',
        ];
    }

    public function getLoan(): ActiveQuery
    {
        return $this->hasOne(Loan::class, ['id' => 'loan_id']);
    }

    public function getRecordedByStaff(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'recorded_by_staff_id']);
    }
}
