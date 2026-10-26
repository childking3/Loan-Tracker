<?php

namespace app\models;

use yii\db\ActiveQuery;

/**
 * The `assigned_staff_id` -> User relation, shared by the three models that
 * actually carry that column: Customer, Loan and Market (verified against the
 * schema, not assumed).
 *
 * Deliberately NOT folded into IncludesTrashedRelations even though this calls
 * its helper: that trait is also used by ActivityLog and Repayment, neither of
 * which has an assigned_staff_id, so defining the relation there would hand two
 * models a relation to a column they do not have - a latent SQL error waiting
 * for someone to touch ->assignedStaff on the wrong class.
 *
 * Resolves through User::findWithDeleted() on purpose: a staff account that was
 * later soft-deleted must still yield a name, so historical records and the
 * markets list never dereference null.
 *
 * Requires IncludesTrashedRelations on the using class, for
 * hasOneIncludingTrashed().
 */
trait HasAssignedStaff
{
    public function getAssignedStaff(): ActiveQuery
    {
        return $this->hasOneIncludingTrashed(User::findWithDeleted(), ['id' => 'assigned_staff_id']);
    }
}
