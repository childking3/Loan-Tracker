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
 * Authorization here has two layers, matching how the RBAC was designed in
 * Phase 2: a coarse controller-level gate (AccessControl, below) that only
 * confirms the user is allowed to use this controller at all, and a
 * fine-grained per-record check inside actionView() that actually exercises
 * IsAssignedStaffRule - the rule attached to viewAssignedLoans since Phase 2
 * but never exercised until now. That rule requires a loan instance to
 * check against, so it cannot gate a plain permission name the way
 * AccessControl's 'roles' list normally works; actionIndex()'s list view is
 * scoped to the current user's assigned loans with a plain WHERE clause
 * instead, which is the list-view equivalent of the same restriction.
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
            // see CustomerController's own comment on the same pattern,
            // added the same security-recheck pass. actionCreate() renders
            // a GET form and processes a POST submission of it.
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

        // Search by loan number, added on top of the RBAC scoping above
        // (the $query built in the if/elseif block), not a fresh
        // unscoped one - a staff account's ['like', 'loan_number', ...]
        // condition still combines with AND against their own
        // assigned_staff_id restriction, so a search term can only ever
        // narrow what they already see, never widen it to another
        // staff member's loans. Same non-scalar-input guard
        // CustomerController::actionIndex() already uses for its own
        // `q` param (a ?q[]=x query string makes request->get() return
        // an array, and this app treats that as "no search term" rather
        // than erroring) - and the same reason: Yii's own `like`
        // condition array-format parameterizes the value and escapes
        // LIKE wildcards in it, so this is safe against both SQL
        // injection and a search term that's itself trying to abuse `%`/
        // `_` wildcards to match more broadly than intended.
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
                // RepaymentController::actionCreate() for the full
                // reasoning) - confirmed live: 5 concurrent identical
                // submissions here created 5 separate real loans for one
                // customer. Guarded the same way: a row lock on the
                // customer to serialize concurrent attempts, plus a short
                // window rejecting another loan with the same
                // customer/package/assigned staff recorded in the last 10
                // seconds. Deliberately short, not permanent - a customer
                // genuinely taking out two loans with the same package
                // later the same day must not be blocked.
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
                        // refresh() before snapshotting for the audit log,
                        // not just save(false): status has a DB-level
                        // DEFAULT 'active' (m260910_.._create_loan_table)
                        // that the PHP model never sets itself, so
                        // getAttributes() right after save() still shows
                        // status as null in memory even though the row
                        // MariaDB actually wrote has 'active' - confirmed
                        // live during this Phase 9 pass, the first audit
                        // log entry this action ever produced recorded
                        // exactly that wrong null. An audit trail that
                        // silently records the wrong value for a field is
                        // worse than one with a gap, since nothing about
                        // it looks wrong until compared against the row it
                        // describes.
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
     * Restricted to users holding the staff role specifically, not every
     * active account - confirmed with the user this wasn't intentional
     * before, since every other part of the app that deals with "assigned
     * staff" (IsAssignedStaffRule, the staff performance report) already
     * assumes it names an actual staff member, not a manager or admin's
     * own account.
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
