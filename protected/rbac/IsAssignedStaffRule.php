<?php

namespace app\rbac;

use yii\rbac\Rule;

/**
 * Restricts a permission check to loans assigned to the current user.
 *
 * Attached to the viewAssignedLoans permission so that a check such as
 * Yii::$app->user->can('viewAssignedLoans', ['loan' => $loan]) returns true
 * only when $loan->assigned_staff_id matches the user performing the check.
 * This enforces row-level access at the RBAC layer itself, rather than
 * relying on a controller-level if statement, closing the IDOR gap
 * identified in the client brief's pentest checklist.
 *
 * $params['loan'] is expected to be a Loan ActiveRecord (introduced in a
 * later phase); this rule has no effect until a controller actually passes
 * one in.
 */
class IsAssignedStaffRule extends Rule
{
    public $name = 'isAssignedStaff';

    public function execute($user, $item, $params)
    {
        if (!isset($params['loan'])) {
            return false;
        }

        return (int) $params['loan']->assigned_staff_id === (int) $user;
    }
}
