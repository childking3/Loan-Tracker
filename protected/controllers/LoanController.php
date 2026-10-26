<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\components\DashboardCache;
use app\helpers\ModelFinder;
use app\helpers\RequestHelper;
use app\models\ActivityLog;
use app\models\Customer;
use app\models\Loan;
use app\models\LoanPackage;
use app\models\Repayment;
use app\models\User;
use Yii;
use yii\data\ActiveDataProvider;
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
                        'actions' => ['create', 'update'],
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
                    'update' => ['get', 'post'],
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
        if (in_array($status, Loan::STATUSES, true)) {
            $query->andWhere(['status' => $status]);
        }

        // Search added on top of the RBAC-scoped $query above (AND'd
        // with assigned_staff_id for staff), so it can only narrow what
        // they already see, never widen it. Yii's `like` condition
        // array-format parameterizes and escapes wildcards, safe against
        // both injection and wildcard abuse.
        $search = trim(RequestHelper::scalarGet('q'));
        if ($search !== '') {
            $query->andWhere(['like', 'loan_number', $search]);
        }

        // with(): the index table shows customer and assigned-staff names
        // per row - without eager loading here this was a real N+1 (one
        // query per loan for each relation), found during the same
        // DB-interaction audit that added the pagination below.
        $query->with(['customer', 'assignedStaff'])->orderBy(['created_at' => SORT_DESC]);
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20]]);

        return $this->render('index', ['dataProvider' => $dataProvider, 'status' => $status, 'search' => $search]);
    }

    public function actionView($id)
    {
        $model = $this->findModel($id);

        if (!$model->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to view this loan.');
        }

        $repayments = Repayment::find()
            ->with('recordedByStaff')
            ->andWhere(['loan_id' => $model->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();

        // Covers both recording a repayment and correcting one: since
        // 2026-10-16 the client wants the same people able to do both, so
        // this is one flag rather than two identical ones. Recording
        // additionally requires an open loan; editing deliberately does
        // not, since correcting a payment that closed a loan too early is
        // exactly what editing exists for.
        $canManageRepayments = Yii::$app->user->can('manageRepayments') && $model->isVisibleToCurrentUser();

        // Which of the repayments shown have been corrected at least once,
        // for a small "(edited)" marker in the table - one batch query
        // against the audit log rather than a new column on repayment
        // (see Repayment's docblock: "was this edited" is answered by the
        // audit log app-wide, not a per-row timestamp).
        $editedRepaymentIds = $repayments === [] ? [] : array_flip(ActivityLog::find()
            ->select('entity_id')
            ->distinct()
            ->andWhere(['action' => 'repayment_update', 'entity_type' => 'repayment'])
            ->andWhere(['entity_id' => array_map(static fn ($r) => $r->id, $repayments)])
            ->column());

        return $this->render('view', [
            'model' => $model,
            'remainingBalance' => $model->getRemainingBalance(),
            'repayments' => $repayments,
            'editedRepaymentIds' => $editedRepaymentIds,
            'canManageRepayments' => $canManageRepayments,
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

        // Issuing a loan against a customer is working with that customer,
        // so the same ownership check the customer pages apply holds here.
        // A no-op for manager/admin (they hold viewAllCustomers), but it
        // stops this route being a way to reach a colleague's customer -
        // and to attach a loan to them - if manageLoans is ever granted to
        // a more scoped role.
        if (!$customer->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to create a loan for this customer.');
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
                //
                // One of 3 near-identical copies of this pattern (see
                // RepaymentController::actionCreate()'s own comment for
                // the full duplicate-function-scan note and why it's left
                // unconsolidated).
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
            'packageOptions' => LoanPackage::selectOptions(),
            'staffOptions' => User::activeStaffOptions(),
        ]);
    }

    /**
     * Corrects an open loan's assigned staff, start date and - only while
     * nothing has been repaid yet - its package. Principal, total repayment
     * and daily payment are never typed in: they only ever come from a
     * package (see Loan::applyPackageTerms()), so changing the package
     * re-copies that package's terms, and changing the start date moves the
     * expected completion date by the loan's own working-day period.
     *
     * Deliberately narrow: finished loans (completed/cancelled) are history
     * and can't be edited, the customer can't be changed, and once a
     * repayment exists the package is locked - swapping the terms under
     * money already collected would silently rewrite what is owed.
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if (!$model->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to edit this loan.');
        }

        if (!$model->isOpenForActivity()) {
            Yii::$app->session->setFlash('error', 'Only active or overdue loans can be edited.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        $oldAttributes = $model->getAttributes();
        $hasRepayments = Repayment::find()->andWhere(['loan_id' => $model->id])->exists();

        if ($model->load(Yii::$app->request->post())) {
            // load() treats customer_id (and package_id, when locked) as
            // safe attributes - never let a posted value move them.
            $model->customer_id = $oldAttributes['customer_id'];
            if ($hasRepayments) {
                $model->package_id = $oldAttributes['package_id'];
            }

            // Compared as strings: the posted values are strings, the loaded
            // ones ints, so getDirtyAttributes() would call an identical
            // resubmit "changed" and write a pointless audit row.
            if ((string) $model->package_id === (string) $oldAttributes['package_id']
                && (string) $model->start_date === (string) $oldAttributes['start_date']
                && (string) $model->assigned_staff_id === (string) $oldAttributes['assigned_staff_id']) {
                Yii::$app->session->setFlash('success', 'No changes to save.');
                return $this->redirect(['view', 'id' => $model->id]);
            }

            $packageChanged = (int) $model->package_id !== (int) $oldAttributes['package_id'];
            $startChanged = $model->start_date !== $oldAttributes['start_date'];
            $newStart = $model->start_date;

            $isValid = $model->validate(['package_id', 'start_date', 'assigned_staff_id']);
            $package = null;
            if ($isValid && $packageChanged) {
                $package = LoanPackage::findOne($model->package_id);
                if ($package === null || !$package->is_active) {
                    $model->addError('package_id', 'Select a valid, active loan package.');
                    $isValid = false;
                }
            }

            if ($isValid) {
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    // Lock the loan row, then re-check the "no repayments"
                    // condition and the earliest payment date under the lock,
                    // so a repayment recorded a moment ago can't slip past a
                    // package swap or a start date that would predate it.
                    Yii::$app->db->createCommand(
                        'SELECT id FROM {{%loan}} WHERE id = :id FOR UPDATE',
                        [':id' => $model->id]
                    )->queryScalar();
                    $earliestPayment = Yii::$app->db->createCommand(
                        'SELECT MIN(payment_date) FROM {{%repayment}} WHERE loan_id = :id',
                        [':id' => $model->id]
                    )->queryScalar();

                    if ($packageChanged && $earliestPayment !== false && $earliestPayment !== null) {
                        $model->addError('package_id', 'The package can no longer be changed - a repayment has been recorded on this loan.');
                    } elseif ($startChanged && $earliestPayment !== false && $earliestPayment !== null && $newStart > $earliestPayment) {
                        $model->addError('start_date', "The start date can't be after the first repayment ({$earliestPayment}).");
                    }

                    if ($model->hasErrors()) {
                        $transaction->rollBack();
                    } else {
                        if ($packageChanged) {
                            $model->applyPackageTerms($package);
                        } elseif ($startChanged) {
                            // rescheduleFrom() reads the loan's period from
                            // its stored start/expected dates, which load()
                            // has just overwritten - put the old start back
                            // first so it measures the period the loan was
                            // actually issued with.
                            $model->start_date = $oldAttributes['start_date'];
                            $model->rescheduleFrom($newStart);
                        }

                        $model->save(false);
                        $model->refresh();

                        // A moved schedule can flip a loan across today: keep
                        // the stored status truthful right away rather than
                        // leaving it for the next nightly run. Uses the live
                        // (uncached) balance - the cached one predates this
                        // edit if the package's terms just changed.
                        $statusBefore = $model->status;
                        $newStatus = $statusBefore;
                        if ($packageChanged || $startChanged) {
                            $owes = Loan::remainingBalancesFor([$model])[(int) $model->id] > 0.0;
                            $today = date('Y-m-d');
                            if ($owes && $statusBefore === 'overdue' && $model->expected_completion_date >= $today) {
                                $newStatus = 'active';
                            } elseif ($owes && $statusBefore === 'active' && $model->expected_completion_date < $today) {
                                $newStatus = 'overdue';
                            }
                        }
                        if ($newStatus !== $statusBefore) {
                            $model->status = $newStatus;
                            $model->save(false, ['status']);
                            AuditLogger::audit('loan_status_change', 'loan', $model->id, ['status' => $statusBefore], ['status' => $newStatus]);
                        }

                        AuditLogger::audit('loan_update', 'loan', $model->id, $oldAttributes, $model->getAttributes());
                        $transaction->commit();

                        Loan::invalidateBalanceCache($model->id);
                        DashboardCache::bumpVersion();

                        Yii::$app->session->setFlash('success', 'Loan updated.');
                        return $this->redirect(['view', 'id' => $model->id]);
                    }
                } catch (\Throwable $e) {
                    if ($transaction->isActive) {
                        $transaction->rollBack();
                    }
                    throw $e;
                }
            }
        }

        $packageOptions = LoanPackage::selectOptions();
        $currentPackageId = (int) $oldAttributes['package_id'];
        if (!isset($packageOptions[$currentPackageId])) {
            // The loan's own package may have been deactivated since - keep
            // it selectable so the form doesn't silently show "no package".
            // Looked up by the id stored on the loan, not the possibly
            // invalid one just posted.
            $currentPackage = LoanPackage::findOne($currentPackageId);
            if ($currentPackage !== null) {
                $packageOptions[$currentPackageId] = $currentPackage->name . ' (inactive)';
            }
        }

        return $this->render('update', [
            'model' => $model,
            'packageOptions' => $packageOptions,
            'staffOptions' => User::activeStaffOptions(),
            'hasRepayments' => $hasRepayments,
        ]);
    }

    private function findModel($id): Loan
    {
        return ModelFinder::findOrFail(Loan::class, $id, 'Loan');
    }
}
