<?php

use app\components\AuditLogger;
use app\components\DashboardCache;
use app\helpers\WorkingDays;
use yii\db\Migration;

/**
 * One-off backfill: loans issued before the schedule became weekdays-only
 * (Monday-Friday) carry an expected_completion_date that counted every
 * calendar day. Their repayment period was stored implicitly, as the
 * calendar-day gap between start_date and expected_completion_date
 * (start + N days), so N is recovered from those two dates - not from the
 * package, which may have been edited since - and the completion date is
 * recomputed as N WORKING days after start_date.
 *
 * Only open loans (active/overdue) are touched. Completed and cancelled
 * loans are finished history and are left exactly as recorded.
 *
 * An overdue loan whose new date is today or later and which still owes
 * money is set back to active (it is no longer late under the new
 * schedule); a still-active loan whose new date is already past is left
 * for the nightly loan/mark-overdue command to flag, as usual.
 *
 * Every change is written to the audit log (user_id NULL - no session user
 * in a migration), old and new value, so the recalculation is traceable
 * per loan. Runs once per database; not written to be re-run, since the
 * calendar-day period can only be recovered from loans still in the old form.
 */
class m260926_020000_recalculate_expected_completion_for_working_days extends Migration
{
    public function safeUp()
    {
        $today = date('Y-m-d');
        $db = $this->db;

        $loans = $db->createCommand(
            "SELECT id, status, start_date, expected_completion_date, total_repayment
               FROM {{%loan}}
              WHERE status IN ('active', 'overdue')"
        )->queryAll();

        $alreadyRecalculated = array_flip($db->createCommand(
            "SELECT DISTINCT entity_id FROM {{%activity_log}}
              WHERE action = 'loan_schedule_recalculated' AND entity_type = 'loan'"
        )->queryColumn());

        $changed = 0;
        foreach ($loans as $loan) {
            if (isset($alreadyRecalculated[$loan['id']])) {
                continue;
            }
            $calendarDays = (int) (new DateTimeImmutable($loan['start_date']))
                ->diff(new DateTimeImmutable($loan['expected_completion_date']))
                ->format('%r%a');
            if ($calendarDays < 1) {
                continue;
            }

            $newExpected = WorkingDays::add($loan['start_date'], $calendarDays);
            if ($newExpected === $loan['expected_completion_date']) {
                continue;
            }

            $db->createCommand()->update(
                '{{%loan}}',
                ['expected_completion_date' => $newExpected, 'updated_at' => time()],
                ['id' => $loan['id']]
            )->execute();
            AuditLogger::audit(
                'loan_schedule_recalculated',
                'loan',
                (int) $loan['id'],
                ['expected_completion_date' => $loan['expected_completion_date']],
                ['expected_completion_date' => $newExpected]
            );
            $changed++;

            if ($loan['status'] === 'overdue' && $newExpected >= $today) {
                $paid = (float) $db->createCommand(
                    'SELECT COALESCE(SUM(amount), 0) FROM {{%repayment}} WHERE loan_id = :id',
                    [':id' => $loan['id']]
                )->queryScalar();
                if ((float) $loan['total_repayment'] - $paid > 0.0) {
                    $db->createCommand()->update('{{%loan}}', ['status' => 'active'], ['id' => $loan['id']])->execute();
                    AuditLogger::audit('loan_status_change', 'loan', (int) $loan['id'], ['status' => 'overdue'], ['status' => 'active']);
                }
            }
        }

        if ($changed > 0) {
            DashboardCache::bumpVersion();
        }
        echo "    > recalculated {$changed} open loan(s) to a weekdays-only schedule\n";
    }

    public function safeDown()
    {
        echo "m260926_020000_recalculate_expected_completion_for_working_days cannot be reverted.\n";
        return false;
    }
}
