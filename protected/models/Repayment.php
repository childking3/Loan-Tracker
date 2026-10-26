<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the repayment table. There is still no delete action
 * anywhere in this application for this model - a repayment is never
 * removed. As of 2026-09-28 it CAN be edited (RepaymentController::
 * actionUpdate(), gated by manageLoans, not the manageRepayments staff use
 * to record one), to correct a mistaken amount or date after the fact,
 * with every edit written to the audit log (old value/new value) rather
 * than silently overwritten - see CODEBASE.md for the decision. Accordingly
 * TimestampBehavior still only touches created_at; there's deliberately no
 * updated_at column, since "was this edited" is answered by the audit log,
 * not a timestamp on the row (matches how Loan/Customer/Guarantor edits
 * work app-wide).
 */
class Repayment extends ActiveRecord
{
    use IncludesTrashedRelations;

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
            // strictDateFormat: see Loan::rules() - string comparisons downstream need zero-padded dates.
            ['payment_date', 'date', 'format' => 'php:Y-m-d', 'strictDateFormat' => true],
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
        // Includes a soft-deleted staff account so reports still print who recorded it.
        return $this->hasOneIncludingTrashed(User::findWithDeleted(), ['id' => 'recorded_by_staff_id']);
    }
}
