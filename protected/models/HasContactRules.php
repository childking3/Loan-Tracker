<?php

namespace app\models;

/**
 * The full_name/phone validation rules (required, length caps, and the
 * Nigerian phone-format regex) were identical between Customer and
 * Guarantor - both are "a person with a name and phone number" in this
 * domain. Consolidated after a duplicate-code scan; each model still
 * keeps its own remaining rules (Customer's address/market_id,
 * Guarantor's address/relationship/occupation).
 */
trait HasContactRules
{
    protected function contactRules(): array
    {
        return [
            [['full_name', 'phone'], 'required'],
            ['full_name', 'string', 'max' => 255],
            ['phone', 'string', 'max' => 20],
            ['phone', 'match', 'pattern' => '/^[0-9+\-\s()]{7,20}$/', 'message' => 'Enter a valid phone number.'],
        ];
    }
}
