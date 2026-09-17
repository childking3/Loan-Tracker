<?php

namespace app\components;

use Yii;

/**
 * The RBAC-gated set of nav items beyond the always-visible Dashboard/
 * Customers/Loans (Import, Reports, Staff, Markets, Loan packages, Audit
 * log, Access log) - one definition shared by layouts/main.php's top nav,
 * the bottom-nav's "is there anything for a 4th tab besides Import"
 * decision, and SiteController::actionMore()'s "More" page, so the three
 * can't drift out of sync the way the loan-visibility predicate already
 * did once (see Loan::isVisibleToCurrentUser()'s own docblock) - each
 * permission check lives in exactly one place now, not three.
 *
 * icon is raw inner SVG markup (path/circle/line elements, no wrapping
 * <svg> tag), matching the redesign mockups' icon set exactly - kept as
 * plain strings here rather than a separate icon-partial system, since
 * this app has no other icon usage yet to build a bigger system around.
 *
 * @return array<int, array{route: string, label: string, icon: string, exact?: bool}>
 */
final class NavMenu
{
    public static function secondaryItems(): array
    {
        $user = Yii::$app->user;
        $items = [];

        if ($user->can('manageCustomers')) {
            $items[] = [
                'route' => 'import/customers',
                'label' => 'Import',
                'icon' => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            ];
        }
        if ($user->can('viewReports')) {
            $items[] = [
                'route' => 'report/index',
                'label' => 'Reports',
                'icon' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
            ];
        }
        // manageStaffAccounts (manager, scoped) as well as manageUsers
        // (admin, unrestricted) - see UserController::canManageAllRoles()'s
        // docblock for exactly what "scoped" means.
        if ($user->can('manageUsers') || $user->can('manageStaffAccounts')) {
            $items[] = [
                'route' => 'user/index',
                'label' => 'Staff',
                'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
            ];
        }
        if ($user->can('manageMarkets')) {
            $items[] = [
                'route' => 'market/index',
                'label' => 'Markets',
                'icon' => '<path d="M20.59 13.41L13 21l-9-9V4h8l8.59 8.59a2 2 0 010 2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
            ];
        }
        if ($user->can('manageSettings')) {
            $items[] = [
                'route' => 'loan-package/index',
                'label' => 'Loan packages',
                'icon' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            ];
        }
        if ($user->can('viewAuditLog')) {
            $items[] = [
                'route' => 'log/audit',
                'label' => 'Audit log',
                'icon' => '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>',
                'exact' => true,
            ];
        }
        if ($user->can('viewAccessLogs')) {
            $items[] = [
                'route' => 'log/access',
                'label' => 'Access log',
                'icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'exact' => true,
            ];
        }

        return $items;
    }
}
