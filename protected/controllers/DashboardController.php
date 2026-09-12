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
     * every staff member's counts to every other. Admins are excluded
     * here too (see DashboardCache::computeTotals()); reachable only by
     * guessing an id, since no link to it is rendered.
     */
    public function actionStaff($id)
    {
        $staff = User::findOne($id);
        if ($staff === null) {
            throw new NotFoundHttpException('Staff member not found.');
        }

        $adminIds = array_map('intval', Yii::$app->authManager->getUserIdsByRole(User::ROLE_ADMIN));
        if (in_array((int) $staff->id, $adminIds, true)) {
            throw new ForbiddenHttpException('Admin accounts are not tracked in staff performance.');
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

        return $this->render('staff', [
            'staff' => $staff,
            'loans' => $loans,
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

        return [
            'version' => $currentVersion,
            'changed' => true,
            'totals' => DashboardCache::getTotals(),
        ];
    }
}
