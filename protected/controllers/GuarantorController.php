<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\Guarantor;
use app\models\Loan;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Adding guarantors to a loan. There is no guarantor index/view page of
 * its own - guarantors are shown inline on the loan's own view page (see
 * LoanController::actionView and views/loan/view.php), the same pattern
 * RepaymentController already uses for repayments, since a guarantor
 * only makes sense in the context of the loan they're guaranteeing.
 *
 * Gated on manageLoans, the same permission that gates loan creation
 * (manager/admin only, not staff) - see
 * m260910_210000_assign_manage_loans_to_manager's own docblock for why
 * loan issuance is treated as a credit-control decision rather than
 * routine staff work. A guarantor is captured as part of that same
 * underwriting decision, not a separate day-to-day operation the way
 * repayments are (those use their own manageRepayments permission,
 * scoped to a staff member's own assigned loans), so it shares loan
 * creation's tier rather than getting a new permission of its own.
 */
class GuarantorController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageLoans'],
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

    public function actionCreate($loanId)
    {
        $loan = Loan::findOne($loanId);
        if ($loan === null) {
            throw new NotFoundHttpException('Loan not found.');
        }

        // Confirmed live by the user's own pentest: a guarantor could be
        // added to a loan already 'completed' or 'cancelled', with no
        // server-side check of the loan's status at all. A guarantor only
        // makes sense while the underwriting decision it backs is still
        // live, so this mirrors RepaymentController::actionCreate()'s own
        // status guard - 'active' and 'overdue' are the only statuses a
        // loan is ever open for further action on.
        if (!in_array($loan->status, ['active', 'overdue'], true)) {
            Yii::$app->session->setFlash('error', 'Guarantors can only be added to active or overdue loans.');
            return $this->redirect(['loan/view', 'id' => $loan->id]);
        }

        $model = new Guarantor();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->loan_id = $loan->id;
            $model->created_by = Yii::$app->user->id;

            // Same double-submit gap as loan/repayment creation (see
            // RepaymentController::actionCreate()'s own docblock for the
            // full reasoning) - originally left unguarded here on the
            // theory that a duplicate guarantor row is a data-quality
            // annoyance, not a financial-correctness issue like a
            // duplicate charge. Confirmed live by the user's own pentest
            // that this was trivially reproducible (5/5 sequential
            // submissions succeeded, no dedup at all) - real underwriting
            // records for a lending business shouldn't accumulate junk
            // duplicates that easily just because the stakes are lower
            // than a repayment, so brought in line with the same guard:
            // a row lock on the loan to serialize concurrent attempts,
            // plus a short window rejecting another guarantor with the
            // same name/phone recorded against this loan in the last 10
            // seconds. Deliberately short, not permanent - a loan
            // genuinely getting two different guarantors named the same
            // (or the same real person re-added later for a legitimate
            // reason) must not be permanently blocked.
            $duplicate = false;
            $transaction = Yii::$app->db->beginTransaction();
            try {
                Yii::$app->db->createCommand(
                    'SELECT id FROM {{%loan}} WHERE id = :id FOR UPDATE',
                    [':id' => $loan->id]
                )->queryScalar();

                $duplicate = Guarantor::find()
                    ->where([
                        'loan_id' => $loan->id,
                        'full_name' => $model->full_name,
                        'phone' => $model->phone,
                    ])
                    ->andWhere(['>=', 'created_at', time() - 10])
                    ->exists();

                if (!$duplicate) {
                    $model->save(false);
                    AuditLogger::audit('guarantor_create', 'guarantor', $model->id, null, $model->getAttributes());
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($duplicate) {
                Yii::$app->session->setFlash('error', 'An identical guarantor was just added to this loan - if this is genuinely a different person, wait a few seconds and try again.');
                return $this->redirect(['loan/view', 'id' => $loan->id]);
            }

            Yii::$app->session->setFlash('success', 'Guarantor added.');
        } else {
            Yii::$app->session->setFlash('error', implode(' ', $model->getFirstErrors()) ?: 'Could not add guarantor.');
        }

        return $this->redirect(['loan/view', 'id' => $loan->id]);
    }
}
