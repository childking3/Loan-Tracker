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
     * and admin (viewAllLoans) may record against any loan - exercises
     * the same IsAssignedStaffRule the loan module uses, since the brief
     * scopes staff's whole permission set to their own assigned loans.
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

        // 'overdue' included alongside 'active': without it, an overdue
        // loan could never receive another repayment (nothing else ever
        // moves a loan back out of 'overdue'), a permanent dead end. The
        // pay-off logic below doesn't care what the prior status was, so
        // widening this check is sufficient on its own.
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

            // A payment can't predate the loan itself - Repayment::rules()'s
            // future-date rule only bounds the upper side, so this was
            // previously unrestricted, letting a phantom collection skew
            // date-range reports and getRemainingBalance(). Checked
            // against the controller's own route-resolved $loan rather
            // than a model rule re-deriving it from $this->loan_id, since
            // that attribute is still mass-assignable at validate() time
            // and could be tricked into checking a different,
            // attacker-chosen loan.
            if ($model->payment_date < $loan->start_date) {
                Yii::$app->session->setFlash('error', "Payment date cannot be before this loan's start date ({$loan->start_date}).");
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            // Repayments are append-only with no idempotency key, so a
            // double-click or retry is indistinguishable from two real
            // payments. Guarded with a row lock on the loan (SELECT ...
            // FOR UPDATE) so concurrent requests serialize, plus a check
            // for a matching loan/amount/date/staff repayment in the last
            // few seconds - a short window, not permanent, since two
            // genuine same-day payments (e.g. installments) must not be
            // blocked.
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
