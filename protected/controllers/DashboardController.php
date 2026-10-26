<?php

namespace app\controllers;

use app\components\DashboardCache;
use app\models\Loan;
use app\models\Repayment;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DashboardController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    // Unauthenticated polls are answered with a JSON 401 by
                    // app\components\WebUser::loginRequired(), centrally, so
                    // there is deliberately no per-action rule here - see that
                    // class and the HumHub cross-reference in CODEBASE.md.
                    [
                        'allow' => true,
                        'roles' => ['viewDashboard'],
                    ],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index', [
            'totals' => DashboardCache::getTotals(),
            'version' => DashboardCache::getVersion(),
        ]);
    }

    /**
     * Drill-down from the dashboard's staff performance table - same
     * viewDashboard permission, since the table itself already shows
     * every staff member's counts to every other. Admin and manager
     * accounts are excluded here too, matching User::staffPerformanceRows()
     * (widened from admin-only to admin+manager by the 2026-09-18
     * security audit + client decision - a manager who personally records
     * a repayment or gets a loan assigned was otherwise reachable here the
     * same as a plain staff member). Directly linked
     * from every staff name in dashboard/index.php's table, so any
     * staff member can open any colleague's page this way - not just
     * reachable by guessing an id. Individual loan numbers in this
     * view only link through to loan/view for loans the current user
     * can actually open (see staff.php) - fixed 2026-09-15, previously
     * linked every loan regardless of the viewer's own access, so
     * clicking a colleague's loan from here could dead-end in a
     * ForbiddenHttpException even though the link itself rendered fine.
     */
    public function actionStaff($id)
    {
        $staff = User::findOne($id);
        if ($staff === null) {
            throw new NotFoundHttpException('Staff member not found.');
        }

        if (in_array((int) $staff->id, array_merge(User::adminIds(), User::managerIds()), true)) {
            throw new ForbiddenHttpException('Admin and manager accounts are not tracked in staff performance.');
        }

        // Per-staff customer separation (2026-10-16): this page lists a
        // staff member's loans together with their customers' names, so
        // leaving it open to every colleague would hand back exactly what
        // the customer scoping takes away. A staff member keeps their own
        // page - seeing your own figures isn't the thing being
        // restricted - and manager/admin keep everyone's, which is what
        // the drill-down exists for. This replaces the earlier deliberate
        // decision that any staff member could open any colleague's page
        // (documented above); the client's brief overrides it.
        if (!Yii::$app->user->can('viewAllLoans') && (int) $staff->id !== (int) Yii::$app->user->id) {
            throw new ForbiddenHttpException('You are not allowed to view another staff member\'s page.');
        }

        $loans = Loan::find()
            ->with('customer')
            ->andWhere(['assigned_staff_id' => $staff->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();

        $countsByStatus = ['active' => 0, 'overdue' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($loans as $loan) {
            if (isset($countsByStatus[$loan->status])) {
                $countsByStatus[$loan->status]++;
            }
        }

        $totalCollected = (float) Repayment::find()->andWhere(['recorded_by_staff_id' => $staff->id])->sum('amount');

        // One GROUP BY query for every loan on this page instead of one
        // cache/SQL round trip per row in the view - same N+1 fix already
        // applied to ReportController/DashboardCache.
        $balances = Loan::remainingBalancesFor($loans);

        return $this->render('staff', [
            'staff' => $staff,
            'loans' => $loans,
            'balances' => $balances,
            'countsByStatus' => $countsByStatus,
            'totalCollected' => round($totalCollected, 2),
        ]);
    }

    /**
     * Polling endpoint. Client sends ?last=<version>, the last version it
     * already rendered. If the server's current version matches, the
     * response says nothing changed and carries no totals; if it differs,
     * the response carries the fresh totals plus the new version, so the
     * client never needs a second round trip to fetch them separately.
     */
    public function actionPoll()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $lastSeenVersion = (int) Yii::$app->request->get('last', 0);
        $currentVersion = DashboardCache::getVersion();

        if ($currentVersion === $lastSeenVersion) {
            return ['version' => $currentVersion, 'changed' => false];
        }

        // Whitelisted, NOT the whole getTotals() array. That array is one
        // shared cache entry computed for everybody, so role filtering is
        // done by the view (see dashboard/index.php). Returning it raw here
        // bypassed that filter entirely: a staff member polling received
        // every other staff member's name, loan count and total collected,
        // and would also have received every market's money once the market
        // breakdown was added. These four are the only keys applyTotals()
        // in the poller actually reads.
        $totals = DashboardCache::getTotals();

        return [
            'version' => $currentVersion,
            'changed' => true,
            'totals' => [
                'activeLoanCount' => $totals['activeLoanCount'],
                'overdueLoanCount' => $totals['overdueLoanCount'],
                'outstandingBalance' => $totals['outstandingBalance'],
                'todaysCollections' => $totals['todaysCollections'],
            ],
        ];
    }
}
