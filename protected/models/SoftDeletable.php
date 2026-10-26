<?php

namespace app\models;

use yii\db\ActiveQuery;

/**
 * find()/findTrashed()/softDelete() were byte-identical between Customer
 * and Market - both are "a row hidden from every normal lookup via
 * deleted_at, with a dedicated trash/restore screen" in this domain.
 * Consolidated after the 2026-09-18 duplicate-function audit, the same
 * way HasContactRules consolidated Customer/Guarantor's validation rules.
 *
 * Not used by User - its own softDelete() also has to free up
 * username/email for reuse (a real behavioral difference confirmed during
 * the round-2 duplicate-function scan), so it keeps its own copy.
 */
trait SoftDeletable
{
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

    /**
     * Live and trashed rows together. Only for relations that must keep
     * resolving a name on historical records (a loan whose customer was
     * later deleted) - never for lookups that decide access or uniqueness.
     */
    public static function findWithTrashed(): ActiveQuery
    {
        return parent::find();
    }

    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save(false, ['deleted_at']);
    }
}
