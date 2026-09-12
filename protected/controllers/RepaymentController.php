<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\components\DashboardCache;
use app\models\Loan;
use app\models\Repayment;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Recording repayments against a loan. There is no repayment index/view
 * page of its own - repayment history is shown inline on the loan's own
 * view page (see LoanController::actionView and views/loan/view.php),
 * since a repayment only makes sense in the context of its loan.
 */
class RepaymentController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageRepayments'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Staff may only record repayments on loans assigned to them; manager
     * and admin (viewAllLoans) may record against any loan. This exercises
     * the same IsAssignedStaffRule the loan module uses, applied here to
     * the manageRepayments permission's context rather than a bare role
     * check, since the brief describes staff's whole permission set -
     * viewAssignedLoans, manageRepayments, manageCustomers - as scoped to
     * their own assigned loans.
     */
    public function actionCreate($loanId)
    {
        $loan = Loan::findOne($loanId);
        if ($loan === null) {
            throw new NotFoundHttpException('Loan not found.');
        }

        if (!Yii::$app->user->can('viewAllLoans') && !Yii::$app->user->can('viewAssignedLoans', ['loan' => $loan])) {
            throw new ForbiddenHttpException('You are not allowed to record repayments on this loan.');
        }

        // 'overdue' included alongside 'active' - found live during a
        // business-logic recheck that this used to be 'active' only,
        // which meant an overdue loan could never receive another
        // repayment through this app at all (the view also hid the
        // whole form for one - see loan/view.php's own matching
        // condition), and nothing anywhere else ever moves a loan back
        // out of 'overdue' - it was a permanent dead end with no way to
        // ever pay one off or reach 'completed' again. The pay-off
        // logic below (`$remaining <= 0.0` -> 'completed') already
        // doesn't care what the loan's status was beforehand, so
        // widening this one check is sufficient on its own - a fully
        // repaid overdue loan already correctly becomes 'completed'
        // with no further changes needed.
        if (!in_array($loan->status, ['active', 'overdue'], true)) {
            Yii::$app->session->setFlash('error', 'Repayments can only be recorded on active or overdue loans.');
            return $this->redirect(['loan/view', 'id' => $loan->id]);
        }

        $model = new Repayment();
        $model->loan_id = $loan->id;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            // Never trust a posted loan_id: this action is always scoped
            // to the loan named in the route.
            $model->loan_id = $loan->id;
            $model->recorded_by_staff_id = Yii::$app->user->id;

            // A payment cannot have been collected before the loan itself
            // existed. Confirmed live during a pentest pass: a payment
            // dated 1900-01-01 against a loan that started in 2026 was
            // accepted with no restriction - the existing future-date
            // rule on Repayment::rules() only bounds the upper side. An
            // impossible historical date would show up as a phantom
            // collection in any date-range report and could make
            // getRemainingBalance() look partially paid before the loan
            // was ever issued. Checked here against the controller's own
            // route-resolved $loan rather than as a new Repayment model
            // rule that re-derives the loan from $this->loan_id - that
            // attribute is still mass-assignable at the moment validate()
            // runs (reset to the correct value only on the line above,
            // after load()+validate() already executed), so a rule
            // depending on $this->loan could be tricked into validating
            // against a different, attacker-chosen loan than the one this
            // request is actually scoped to and ultimately saves against.
            if ($model->payment_date < $loan->start_date) {
                Yii::$app->session->setFlash('error', "Payment date cannot be before this loan's start date ({$loan->start_date}).");
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            // Repayments are append-only with no client-side idempotency
            // key, so a double-click or a network retry resubmitting the
            // same form is indistinguishable from two real payments at the
            // database layer - confirmed live during a pentest pass: 10
            // concurrent identical submissions recorded 10 separate rows.
            // Guarded here with a row lock on the loan (SELECT ... FOR
            // UPDATE inside a transaction) so concurrent requests against
            // the same loan serialize rather than all reading "no duplicate
            // yet" before any of them commits, plus a check for another
            // repayment with the same loan/amount/date/staff recorded in
            // the last few seconds. This is deliberately a short window,
            // not a permanent one: a staff member legitimately recording
            // two separate same-amount payments on the same day (e.g. two
            // installments) is expected and must not be permanently
            // blocked - only a near-simultaneous accidental resubmission
            // is.
            $duplicate = false;
            $transaction = Yii::$app->db->beginTransaction();
            try {
                Yii::$app->db->createCommand(
                    'SELECT id FROM {{%loan}} WHERE id = :id FOR UPDATE',
                    [':id' => $loan->id]
                )->queryScalar();

                $duplicate = Repayment::find()
                    ->where([
                        'loan_id' => $loan->id,
                        'amount' => $model->amount,
                        'payment_date' => $model->payment_date,
                        'recorded_by_staff_id' => $model->recorded_by_staff_id,
                    ])
                    ->andWhere(['>=', 'created_at', time() - 10])
                    ->exists();

                if (!$duplicate) {
                    $model->save(false);
                    AuditLogger::audit('repayment_create', 'repayment', $model->id, null, $model->getAttributes());
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($duplicate) {
                Yii::$app->session->setFlash('error', 'An identical repayment was just recorded for this loan - if this is a genuine second payment, wait a few seconds and try again.');
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            Loan::invalidateBalanceCache($loan->id);
            DashboardCache::bumpVersion();
            $remaining = $loan->getRemainingBalance();

            if ($remaining <= 0.0) {
                $loan->status = 'completed';
                $loan->save(false);
                AuditLogger::audit('loan_status_change', 'loan', $loan->id, ['status' => 'active'], ['status' => 'completed']);
            }

            $message = 'Repayment recorded.';
            if ($remaining < 0) {
                $message .= sprintf(' Note: this overpays the loan by %s.', number_format(abs($remaining), 2));
            }
            Yii::$app->session->setFlash('success', $message);
        } else {
            Yii::$app->session->setFlash('error', implode(' ', $model->getFirstErrors()) ?: 'Could not record repayment.');
        }

        return $this->redirect(['loan/view', 'id' => $loan->id]);
    }
}
