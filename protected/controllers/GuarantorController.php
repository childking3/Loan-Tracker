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
 * No standalone guarantor page - shown inline on loan/view, like
 * RepaymentController does for repayments.
 *
 * Gated on manageLoans (not a new permission): a guarantor is part of
 * the same underwriting decision as issuing the loan, not routine
 * staff work like repayments.
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

        // Loan::isOpenForActivity() - a guarantor only makes sense while
        // the loan is still open, same rule RepaymentController applies.
        if (!$loan->isOpenForActivity()) {
            Yii::$app->session->setFlash('error', 'Guarantors can only be added to active or overdue loans.');
            return $this->redirect(['loan/view', 'id' => $loan->id]);
        }

        $model = new Guarantor();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->loan_id = $loan->id;
            $model->created_by = Yii::$app->user->id;

            // Same double-submit guard as loan/repayment creation: a row
            // lock on the loan plus a short window rejecting an identical
            // name+phone guarantor added in the last 10 seconds. Short,
            // not permanent - a legitimate re-add later must not be blocked.
            //
            // One of 3 near-identical copies of this pattern (see
            // RepaymentController::actionCreate()'s own comment for the
            // full duplicate-function-scan note and why it's left
            // unconsolidated).
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
