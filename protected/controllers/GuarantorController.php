<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\models\Customer;
use app\models\Guarantor;
use app\models\Loan;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * No standalone guarantor page - shown inline on loan/view, like
 * RepaymentController does for repayments.
 *
 * Adding a guarantor to a loan is gated on manageLoans (not a new
 * permission): a guarantor is part of the same underwriting decision as
 * issuing the loan, not routine staff work like repayments.
 *
 * Editing follows the record's own kind (see Guarantor's docblock): a
 * per-loan guarantor needs manageLoans plus visibility of that loan, the
 * baseline per-customer guarantor needs manageCustomers - the same
 * permission that lets someone edit the customer it belongs to (and that
 * captured it at registration in the first place). A row is only ever
 * edited in place, never re-pointed at a different loan/customer.
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
                        'actions' => ['create'],
                        'allow' => true,
                        'roles' => ['manageLoans'],
                    ],
                    [
                        'actions' => ['create-for-customer'],
                        'allow' => true,
                        'roles' => ['manageCustomers'],
                    ],
                    [
                        // Which permission applies depends on the guarantor
                        // row itself, so the check lives in actionUpdate().
                        'actions' => ['update'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'create-for-customer' => ['get', 'post'],
                    'update' => ['get', 'post'],
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

        // Row-level visibility check, same as every other loan-scoped write
        // (RepaymentController::actionCreate, LoanController::actionUpdate).
        // A no-op today - this action is gated on manageLoans, held only by
        // manager/admin, who also hold viewAllLoans so this always passes -
        // but added for consistency so the check isn't silently missing if
        // manageLoans is ever granted to a more scoped role.
        if (!$loan->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to add a guarantor to this loan.');
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
    /**
     * Adds the baseline guarantor to a customer that doesn't have one yet
     * (registration only captures it if it was filled in at the time).
     * At most one per customer, so this refuses if one already exists -
     * editing that one is actionUpdate().
     */
    public function actionCreateForCustomer($customerId)
    {
        $customer = ModelFinder::findOrFail(Customer::class, $customerId, 'Customer');

        // A guarantor is part of the customer's own record, so reaching
        // one is reaching the customer - same ownership check the customer
        // pages apply.
        if (!$customer->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to add a guarantor for this customer.');
        }

        if ($customer->guarantor !== null) {
            Yii::$app->session->setFlash('error', 'This customer already has a guarantor - edit it instead.');
            return $this->redirect(['customer/view', 'id' => $customer->id]);
        }

        $model = new Guarantor();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->customer_id = $customer->id;
            $model->created_by = Yii::$app->user->id;
            $model->save(false);
            AuditLogger::audit('guarantor_create', 'guarantor', $model->id, null, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Guarantor added.');
            return $this->redirect(['customer/view', 'id' => $customer->id]);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => 'Add guarantor for ' . $customer->full_name,
            'backUrl' => ['customer/view', 'id' => $customer->id],
        ]);
    }

    public function actionUpdate($id)
    {
        $model = ModelFinder::findOrFail(Guarantor::class, $id, 'Guarantor');

        if ($model->loan_id !== null) {
            $loan = Loan::findOne($model->loan_id);
            if ($loan === null || !Yii::$app->user->can('manageLoans') || !$loan->isVisibleToCurrentUser()) {
                throw new ForbiddenHttpException('You are not allowed to edit this guarantor.');
            }
            $backUrl = ['loan/view', 'id' => $loan->id];
            $subject = 'loan ' . $loan->loan_number;
        } else {
            // isVisibleToCurrentUser() alongside manageCustomers: the
            // permission alone only says "may work with customers at
            // all", which every staff member holds - without the
            // ownership check this edited a colleague's customer's
            // guarantor, the customer-side equivalent of the loan branch
            // above.
            $customer = Customer::findOne($model->customer_id);
            if ($customer === null || !Yii::$app->user->can('manageCustomers') || !$customer->isVisibleToCurrentUser()) {
                throw new ForbiddenHttpException('You are not allowed to edit this guarantor.');
            }
            $backUrl = ['customer/view', 'id' => $customer->id];
            $subject = $customer->full_name;
        }

        // Snapshotted before load() - see CustomerController::actionUpdate().
        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('guarantor_update', 'guarantor', $model->id, $oldAttributes, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Guarantor updated.');
            return $this->redirect($backUrl);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => 'Edit guarantor (' . $subject . ')',
            'backUrl' => $backUrl,
        ]);
    }
}
