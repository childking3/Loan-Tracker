<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\Customer;
use app\models\Loan;
use app\models\LoanPackage;
use app\models\Repayment;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Loan creation and viewing.
 *
 * Authorization has two layers: a coarse controller-level gate
 * (AccessControl) and a fine-grained per-record check in actionView()
 * exercising IsAssignedStaffRule, which needs a loan instance so it
 * can't gate a plain permission name via AccessControl's 'roles' list.
 * actionIndex()'s list view achieves the same restriction with a plain
 * WHERE clause instead.
 */
class LoanController extends Controller
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
                        'actions' => ['index', 'view'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            // Explicit GET+POST, not left open to every verb by default -
            // see CustomerController's comment on the same pattern.
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        if (Yii::$app->user->can('viewAllLoans')) {
            $query = Loan::find();
        } elseif (Yii::$app->user->can('staff')) {
            $query = Loan::find()->andWhere(['assigned_staff_id' => Yii::$app->user->id]);
        } else {
            throw new ForbiddenHttpException('You are not allowed to view loans.');
        }

        $status = Yii::$app->request->get('status', '');
        if (in_array($status, ['active', 'completed', 'overdue', 'cancelled'], true)) {
            $query->andWhere(['status' => $status]);
        }

        // Search added on top of the RBAC-scoped $query above (AND'd
        // with assigned_staff_id for staff), so it can only narrow what
        // they already see, never widen it. Same non-scalar-input guard
        // as CustomerController::actionIndex()'s `q` param. Yii's `like`
        // condition array-format parameterizes and escapes wildcards,
        // safe against both injection and wildcard abuse.
        $rawSearch = Yii::$app->request->get('q', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');
        if ($search !== '') {
            $query->andWhere(['like', 'loan_number', $search]);
        }

        $loans = $query->orderBy(['created_at' => SORT_DESC])->all();

        return $this->render('index', ['loans' => $loans, 'status' => $status, 'search' => $search]);
    }

    public function actionView($id)
    {
        $model = $this->findModel($id);

        if (!Yii::$app->user->can('viewAllLoans') && !Yii::$app->user->can('viewAssignedLoans', ['loan' => $model])) {
            throw new ForbiddenHttpException('You are not allowed to view this loan.');
        }

        $repayments = Repayment::find()
            ->andWhere(['loan_id' => $model->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();

        $canRecordRepayment = Yii::$app->user->can('manageRepayments')
            && (Yii::$app->user->can('viewAllLoans') || Yii::$app->user->can('viewAssignedLoans', ['loan' => $model]));

        return $this->render('view', [
            'model' => $model,
            'remainingBalance' => $model->getRemainingBalance(),
            'repayments' => $repayments,
            'canRecordRepayment' => $canRecordRepayment,
            'guarantors' => $model->guarantors,
            'canManageLoan' => Yii::$app->user->can('manageLoans'),
        ]);
    }

    public function actionCreate($customerId)
    {
        $customer = Customer::findOne($customerId);
        if ($customer === null) {
            throw new NotFoundHttpException('Customer not found.');
        }

        $model = new Loan();
        $model->customer_id = $customer->id;
        $model->start_date = date('Y-m-d');

        if ($model->load(Yii::$app->request->post())) {
            // Never trust a posted customer_id: this action is always
            // scoped to the customer named in the route.
            $model->customer_id = $customer->id;

            $package = LoanPackage::findOne($model->package_id);
            $packageIsUsable = $package !== null && $package->is_active;
            $isValid = $model->validate();

            if (!$packageIsUsable) {
                $model->addError('package_id', 'Select a valid, active loan package.');
            } elseif ($isValid) {
                // Same double-submit gap as repayments (see
                // RepaymentController::actionCreate()): guarded with a
                // row lock on the customer plus a short window rejecting
                // a duplicate customer/package/staff loan created in the
                // last 10 seconds - short, not permanent, since a genuine
                // second same-day loan must not be blocked.
                $duplicate = false;
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    Yii::$app->db->createCommand(
                        'SELECT id FROM {{%customer}} WHERE id = :id FOR UPDATE',
                        [':id' => $customer->id]
                    )->queryScalar();

                    $duplicate = Loan::find()
                        ->where([
                            'customer_id' => $customer->id,
                            'package_id' => $model->package_id,
                            'assigned_staff_id' => $model->assigned_staff_id,
                        ])
                        ->andWhere(['>=', 'created_at', time() - 10])
                        ->exists();

                    if (!$duplicate) {
                        $model->applyPackageTerms($package);
                        $model->created_by = Yii::$app->user->id;
                        $model->save(false);
                        // refresh() before snapshotting for the audit log:
                        // status has a DB-level DEFAULT 'active' the PHP
                        // model never sets, so getAttributes() right after
                        // save() would still show null in memory even
                        // though the row itself has 'active'.
                        $model->refresh();
                        // Written inside the same transaction as the loan
                        // itself, not after commit - a real audit trail
                        // must not be able to record a loan that a crash
                        // between the two statements then rolled back.
                        AuditLogger::audit('loan_create', 'loan', $model->id, null, $model->getAttributes());
                    }

                    $transaction->commit();
                } catch (\Throwable $e) {
                    $transaction->rollBack();
                    throw $e;
                }

                if ($duplicate) {
                    Yii::$app->session->setFlash('error', 'An identical loan was just created for this customer - if this is genuinely a second loan, wait a few seconds and try again.');
                    return $this->redirect(['customer/view', 'id' => $customer->id]);
                }

                Yii::$app->session->setFlash('success', 'Loan created.');
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('create', [
            'model' => $model,
            'customer' => $customer,
            'packageOptions' => $this->packageOptions(),
            'staffOptions' => $this->staffOptions(),
        ]);
    }

    private function packageOptions(): array
    {
        $options = [];
        foreach (LoanPackage::activePackages() as $package) {
            $options[$package->id] = sprintf(
                '%s (Amount: %s, Repay: %s over %d days)',
                $package->name,
                $package->loan_amount,
                $package->total_repayment,
                $package->repayment_period_days
            );
        }

        return $options;
    }

    /**
     * Restricted to the staff role specifically, not every active
     * account - every other part of the app dealing with "assigned
     * staff" (IsAssignedStaffRule, staff performance report) assumes it
     * names an actual staff member, not a manager/admin.
     */
    private function staffOptions(): array
    {
        $staffIds = Yii::$app->authManager->getUserIdsByRole('staff');
        if ($staffIds === []) {
            return [];
        }

        $options = [];
        foreach (User::find()->andWhere(['status' => User::STATUS_ACTIVE, 'id' => $staffIds])->orderBy(['full_name' => SORT_ASC])->all() as $user) {
            $options[$user->id] = $user->full_name;
        }

        return $options;
    }

    private function findModel($id): Loan
    {
        $model = Loan::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('Loan not found.');
        }

        return $model;
    }
}
