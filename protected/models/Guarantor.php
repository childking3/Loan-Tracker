<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord for the guarantor table. Two independent uses share one
 * table (see m260916_100000_add_customer_id_to_guarantor's docblock for
 * why): the original per-loan guarantor (loan_id set, zero or more per
 * loan, added from loan/view.php) and a baseline per-customer guarantor
 * captured at registration (customer_id set, at most one per customer:
 * CustomerController::actionCreate() writes it once, and
 * GuarantorController::actionCreateForCustomer() only adds one when the
 * customer has none). Both kinds are editable in place afterward through
 * GuarantorController::actionUpdate(), which never changes which loan or
 * customer a row belongs to. A row always has exactly one of
 * loan_id/customer_id set, enforced by a DB CHECK constraint, not just
 * application code.
 *
 * loan_id/customer_id are both absent from rules(): both controllers
 * that create a guarantor always scope it to the loan/customer in their
 * own route or freshly-created record, never to posted input, so
 * there's nothing to validate.
 */
class Guarantor extends ActiveRecord
{
    use HasContactRules;

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
        return array_merge($this->contactRules(), [
            ['address', 'string'],
            ['relationship', 'string', 'max' => 100],
            ['occupation', 'string', 'max' => 150],
        ]);
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

    public function getCustomer(): ActiveQuery
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }
}
