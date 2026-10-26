<?php

namespace app\models;

use yii\db\ActiveQuery;

/**
 * hasOne() always starts from the related class's scoped find(), which hides
 * soft-deleted rows. A record that must keep showing a name after its
 * customer/staff/market was deleted (a loan, a repayment) needs the same
 * relation wiring - link, primary model, single row - on a query that
 * includes them. Building it here keeps eager loading via with() working.
 */
trait IncludesTrashedRelations
{
    protected function hasOneIncludingTrashed(ActiveQuery $unscopedQuery, array $link): ActiveQuery
    {
        $unscopedQuery->primaryModel = $this;
        $unscopedQuery->link = $link;
        $unscopedQuery->multiple = false;
        return $unscopedQuery;
    }
}
