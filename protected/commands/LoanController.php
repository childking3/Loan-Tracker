<?php

namespace app\commands;

use app\components\AuditLogger;
use app\components\DashboardCache;
use app\models\Loan;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console-only loan maintenance tasks, run via `protected/yii loan/...`
 * (for example, from cron).
 */
class LoanController extends Controller
{
    /**
     * Marks every active loan past its expected completion date with a
     * remaining balance as overdue. Run as a scheduled command rather than
     * computed per page load, so dashboard/listing reads stay cheap.
     */
    public function actionMarkOverdue(): int
    {
        $today = date('Y-m-d');

        $loans = Loan::find()
            ->andWhere(['status' => 'active'])
            ->andWhere(['<', 'expected_completion_date', $today])
            ->all();

        $markedCount = 0;
        foreach ($loans as $loan) {
            if ($loan->getRemainingBalance() > 0.0) {
                $loan->status = 'overdue';
                $loan->save(false);
                // user_id will be null here - AuditLogger's has('user')
                // guard handles that for the console app (no 'user' component).
                AuditLogger::audit('loan_status_change', 'loan', $loan->id, ['status' => 'active'], ['status' => 'overdue']);
                $markedCount++;
            }
        }

        if ($markedCount > 0) {
            DashboardCache::bumpVersion();
        }

        $this->stdout("Checked " . count($loans) . " loan(s) past their expected completion date; marked {$markedCount} as overdue.\n");

        return ExitCode::OK;
    }
}
