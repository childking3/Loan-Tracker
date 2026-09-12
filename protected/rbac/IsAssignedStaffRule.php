<?php

namespace app\rbac;

use yii\rbac\Rule;

/**
 * Restricts the viewAssignedLoans permission to loans assigned to the
 * current user - enforced at the RBAC layer instead of a controller-level
 * if statement, closing an IDOR gap. Requires $params['loan'] (a Loan AR)
 * to be passed by the caller; has no effect otherwise.
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
