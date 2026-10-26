<?php

namespace app\rbac;

use app\models\Loan;
use yii\rbac\Rule;

/**
 * Restricts the viewAssignedCustomers permission to customers assigned to
 * the current user - the customer-side counterpart of IsAssignedStaffRule,
 * and enforced at the RBAC layer for the same reason: a controller-level
 * if statement is one forgotten call site away from an IDOR. Requires
 * $params['customer'] (a Customer AR) to be passed by the caller; has no
 * effect otherwise.
 *
 * Two deliberate decisions encoded here rather than at the call sites:
 *
 *  - A customer with no assigned staff (assigned_staff_id IS NULL) is
 *    visible to NOBODY through this rule - only to roles holding
 *    viewAllCustomers (manager/admin). customer.assigned_staff_id is
 *    SET NULL on user delete, so the opposite reading ("unowned means
 *    everyone") would turn removing a staff account into a silent
 *    disclosure of all their customers to every remaining staff member.
 *
 *  - A staff member who holds a loan assigned to them can see that loan's
 *    customer even when the customer belongs to a colleague (the fallback
 *    query below). Loan assignment and customer ownership are allowed to
 *    disagree - a loan can be handed to one staff member while the
 *    customer relationship stays with another - and without this, the
 *    assigned staff member would see a loan they are responsible for
 *    collecting but hit a 403 on the customer behind it. That exact dead
 *    end (visible record, inaccessible detail page) has already been
 *    fixed twice in this app, on customer/view.php and the dashboard
 *    staff drill-down; this avoids reintroducing it on the customer side.
 *    The admin resolves the mismatch properly by reassigning, which the
 *    client asked for - this rule only stops it being a dead end
 *    meanwhile.
 */
class IsAssignedStaffCustomerRule extends Rule
{
    public $name = 'isAssignedStaffCustomer';

    public function execute($user, $item, $params)
    {
        if (!isset($params['customer'])) {
            return false;
        }

        $customer = $params['customer'];

        if ($customer->assigned_staff_id === null) {
            return false;
        }

        if ((int) $customer->assigned_staff_id === (int) $user) {
            return true;
        }

        // Cheapest check first: the owner comparison above resolves every
        // ordinary case without touching the database, and this runs only
        // for the genuine mismatch case described above.
        return Loan::find()
            ->where(['customer_id' => $customer->id, 'assigned_staff_id' => $user])
            ->exists();
    }
}
