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

        // One GROUP BY query for every candidate loan instead of one
        // cache/SQL round trip per loan - same N+1 fix already applied to
        // ReportController/DashboardCache, applied here too.
        $balances = Loan::remainingBalancesFor($loans);

        $markedCount = 0;
        $failedIds = [];
        foreach ($loans as $loan) {
            if (($balances[(int) $loan->id] ?? 0.0) <= 0.0) {
                continue;
            }

            // Per-loan, not one try/catch around the whole loop: a single
            // bad loan (a transient Galera hiccup, a stale row) used to
            // abort the entire run uncaught, leaving every loan AFTER it in
            // that day's batch un-marked-overdue until the next night's
            // cron - the exact "silent failure with no visible symptom"
            // class of bug already found once in this app's actual cron
            // wiring (a shell-script PATH issue). Logged either way, so a
            // human tailing the log still sees it; the run itself keeps
            // going past a single bad loan instead of stopping cold.
            try {
                $loan->status = 'overdue';
                $loan->save(false);
                // user_id will be null here - AuditLogger's has('user')
                // guard handles that for the console app (no 'user' component).
                AuditLogger::audit('loan_status_change', 'loan', $loan->id, ['status' => 'active'], ['status' => 'overdue']);
                $markedCount++;
            } catch (\Throwable $e) {
                \Yii::error("Failed to mark loan {$loan->id} overdue: " . (string) $e, __METHOD__);
                $failedIds[] = $loan->id;
            }
        }

        if ($markedCount > 0) {
            DashboardCache::bumpVersion();
        }

        $this->stdout("Checked " . count($loans) . " loan(s) past their expected completion date; marked {$markedCount} as overdue.\n");
        if ($failedIds !== []) {
            $this->stdout('Failed on loan id(s): ' . implode(', ', $failedIds) . " - see the log for details.\n");
        }

        return $failedIds === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
