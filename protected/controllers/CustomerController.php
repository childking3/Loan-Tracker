<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\components\AvatarStorage;
use app\helpers\ModelFinder;
use app\helpers\RequestHelper;
use app\models\Customer;
use app\models\Guarantor;
use app\models\Loan;
use app\models\LoanPackage;
use app\models\Market;
use app\models\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Customer management: list/search, view (including loan history), create,
 * and update. All actions require the manageCustomers permission, which
 * staff and every role above it hold (see m260910_200000_init_rbac).
 */
class CustomerController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageCustomers'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['post'],
                    // create/update render a GET form and process a POST
                    // submission - not POST-only like delete, but still
                    // explicitly restricted rather than relying on
                    // load()'s post()-only read as the only guard against
                    // other verbs.
                    'create' => ['get', 'post'],
                    'update' => ['get', 'post'],
                    'restore' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Newest registration first by default (the client wants the list
     * arranged by date, not alphabetically), with an "oldest first" toggle
     * and a registration-date range filter. The search box also accepts a
     * date on its own (e.g. 2026-09-17 or 17/09/2026) and matches every
     * customer registered that day, alongside the usual name/phone match.
     */
    public function actionIndex()
    {
        $sort = RequestHelper::scalarGet('sort') === 'oldest' ? 'oldest' : 'newest';
        $direction = $sort === 'oldest' ? SORT_ASC : SORT_DESC;
        // scopeVisible() first, so every narrowing below (search, date
        // range) can only ever subtract from what this user may see -
        // same ordering rule LoanController::actionIndex() follows, where
        // the RBAC-scoped query is built before the search is AND'd onto
        // it.
        // "Click Sandra, see Sandra's customers" - the staff filter the
        // client asked for. Only offered to viewAllCustomers holders: for
        // anyone else the list is already their own customers, so the
        // parameter has nothing to offer them but a way to probe whether a
        // given staff id has customers. Applied on top of scopeVisible()
        // (never instead of it), so it can only ever narrow.
        $staffOptions = Yii::$app->user->can('viewAllCustomers') ? User::activeStaffOptions() : [];

        // Active / Deleted / All tab. scopeVisible() still wraps the chosen
        // base, so a staff member sees only their own in every tab and
        // restore stays gated by isVisibleToCurrentUser(). Unknown values
        // fall back to 'active' - the tab is never trusted to widen scope,
        // only to pick which soft-delete state is listed.
        $filter = RequestHelper::scalarGet('filter');
        $filter = in_array($filter, ['deleted', 'all'], true) ? $filter : 'active';
        $base = $filter === 'deleted'
            ? Customer::findTrashed()
            : ($filter === 'all' ? Customer::findWithTrashed() : Customer::find());

        $query = Customer::scopeVisible($base)
            ->with('market')
            ->orderBy(['created_at' => $direction, 'id' => $direction]);

        // Eager-loaded only for the people whose list actually shows an
        // owner column: a staff member sees only their own customers, so
        // loading the owner for them would be one query per page for a
        // name that is never rendered.
        if ($staffOptions !== []) {
            $query->with('assignedStaff');
        }

        $staffFilter = '';
        if ($staffOptions !== []) {
            $requested = RequestHelper::scalarGet('staff');
            if ($requested !== '' && isset($staffOptions[(int) $requested])) {
                $staffFilter = (string) (int) $requested;
                $query->andWhere(['assigned_staff_id' => (int) $requested]);
            }
        }

        $search = trim(RequestHelper::scalarGet('q'));
        if ($search !== '') {
            $conditions = [
                'or',
                ['like', 'full_name', $search],
                ['like', 'phone', $search],
            ];
            $searchedDay = self::parseSearchDate($search);
            if ($searchedDay !== null) {
                $conditions[] = ['between', 'created_at', strtotime($searchedDay . ' 00:00:00'), strtotime($searchedDay . ' 23:59:59')];
            }
            $query->andWhere($conditions);
        }

        $from = RequestHelper::dateGet('from');
        $to = RequestHelper::dateGet('to');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from !== '') {
            $query->andWhere(['>=', 'created_at', strtotime($from . ' 00:00:00')]);
        }
        if ($to !== '') {
            $query->andWhere(['<=', 'created_at', strtotime($to . ' 23:59:59')]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'search' => $search,
            'sort' => $sort,
            'from' => $from,
            'to' => $to,
            'staffOptions' => $staffOptions,
            'staffFilter' => $staffFilter,
            'filter' => $filter,
        ]);
    }

    /**
     * Accepts Y-m-d, d/m/Y or d-m-Y (the way Nigerian users write dates)
     * and returns Y-m-d, or null if the text isn't a real date.
     */
    private static function parseSearchDate(string $text): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public function actionView($id)
    {
        $model = $this->findModel($id);

        // Uses the AR relation (not raw SQL) so this view can show
        // assigned staff and remaining balance via getAssignedStaff()/
        // getRemainingBalance(), which need a real Loan instance.
        $loans = Loan::find()
            ->with('assignedStaff')
            ->andWhere(['customer_id' => $model->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();

        // One GROUP BY query for every one of this customer's loans instead
        // of one cache/SQL round trip per row in the view (the loan table is
        // rendered twice per page - active and history) - same N+1 fix
        // already applied to ReportController/DashboardCache.
        $balances = Loan::remainingBalancesFor($loans);

        return $this->render('view', [
            'model' => $model,
            'loans' => $loans,
            'balances' => $balances,
        ]);
    }

    public function actionCreate()
    {
        $model = new Customer();
        // Defaulted before load(), not after - so an unmodified GET
        // already shows the right selection, and a POST that omits the
        // field entirely (shouldn't happen with a real <select>, but
        // load() only overwrites keys actually present) doesn't silently
        // null it. Picks whichever market this staff member is assigned
        // to - if more than one (see MARKETS_REDESIGN_REVIEW.md's open
        // item), whichever the query returns first, same tie-break used
        // everywhere else this ambiguity already exists.
        $assignedMarket = Market::find()->andWhere(['assigned_staff_id' => Yii::$app->user->id])->one();
        $model->market_id = $assignedMarket->id ?? null;

        $guarantor = new Guarantor();

        $loan = new Loan();
        $loan->start_date = date('Y-m-d');
        // Only offered as a default if the current user is actually a
        // valid assignee (i.e. shows up in staffOptions below) - an
        // admin/manager registering a customer isn't necessarily staff
        // themselves, so leave it to the prompt in that case rather than
        // defaulting to an id validateIsStaff() would reject.
        $staffOptions = User::activeStaffOptions();
        if (isset($staffOptions[Yii::$app->user->id])) {
            $loan->assigned_staff_id = Yii::$app->user->id;
        }

        $duplicates = [];

        if ($model->load(Yii::$app->request->post())) {
            $model->photoFile = UploadedFile::getInstance($model, 'photoFile');
            $guarantor->load(Yii::$app->request->post());
            $loan->load(Yii::$app->request->post());
            $confirmDuplicate = (bool) Yii::$app->request->post('confirmDuplicate');

            // Optional, per the approved design: an empty guarantor
            // sub-form is a normal "skip this," not a validation
            // failure - only validated at all once the user has actually
            // started filling it in, avoiding a half-saved row either way.
            $guarantorFilled = trim((string) $guarantor->full_name) !== ''
                || trim((string) $guarantor->phone) !== ''
                || trim((string) $guarantor->address) !== '';

            // Same "optional, only validated once started" shape as the
            // guarantor sub-form above - a package left on the prompt
            // means "just register the customer, no loan yet."
            $loanRequested = (string) $loan->package_id !== '';

            $customerValid = $model->validate();
            $guarantorValid = !$guarantorFilled || $guarantor->validate();

            $package = $loanRequested ? LoanPackage::findOne($loan->package_id) : null;
            $packageIsUsable = $package !== null && $package->is_active;
            // customer_id isn't known yet at this point (the customer
            // hasn't been saved), so validate everything else about the
            // loan now and fill in customer_id right before save() below
            // - same reason LoanController::actionCreate() never trusts a
            // posted customer_id either.
            $loanValid = !$loanRequested || ($loan->validate(['package_id', 'start_date', 'assigned_staff_id']) && $packageIsUsable);
            if ($loanRequested && !$packageIsUsable) {
                $loan->addError('package_id', 'Select a valid, active loan package.');
            }

            if ($customerValid && $guarantorValid && $loanValid) {
                $duplicates = $model->findDuplicatesByPhone();

                if ($duplicates === [] || $confirmDuplicate) {
                    // One form, one submit, per the mockup - all rows
                    // saved together or not at all, mirroring
                    // GuarantorController::actionCreate()'s own
                    // transaction use for the same "related writes" shape.
                    $transaction = Yii::$app->db->beginTransaction();
                    try {
                        $model->created_by = Yii::$app->user->id;
                        $model->assigned_staff_id = Customer::defaultOwnerForCurrentUser($model->market_id);
                        $model->save(false);

                        if ($guarantorFilled) {
                            $guarantor->customer_id = $model->id;
                            $guarantor->created_by = Yii::$app->user->id;
                            $guarantor->save(false);
                        }

                        if ($loanRequested) {
                            $loan->customer_id = $model->id;
                            $loan->applyPackageTerms($package);
                            $loan->created_by = Yii::$app->user->id;
                            $loan->save(false);
                            // See LoanController::actionCreate()'s own
                            // comment: status has a DB-level DEFAULT the
                            // PHP model never sets, so refresh() before
                            // snapshotting for the audit log below.
                            $loan->refresh();
                        }

                        // Inside the transaction, before commit - moved here
                        // from after it: these are plain DB inserts via the
                        // same 'db' connection, so if the audit insert itself
                        // failed (a Galera hiccup, say), the customer/
                        // guarantor/loan rows must not commit as a financial
                        // event with no audit trail at all.
                        AuditLogger::audit('customer_create', 'customer', $model->id, null, $model->getAttributes());
                        if ($guarantorFilled) {
                            AuditLogger::audit('guarantor_create', 'guarantor', $guarantor->id, null, $guarantor->getAttributes());
                        }
                        if ($loanRequested) {
                            AuditLogger::audit('loan_create', 'loan', $loan->id, null, $loan->getAttributes());
                        }

                        $transaction->commit();
                    } catch (\Throwable $e) {
                        $transaction->rollBack();
                        throw $e;
                    }

                    // After the commit, not inside it: a file write can't be
                    // rolled back with the transaction, and a photo that
                    // fails to store must not undo an otherwise valid
                    // registration (the photo is optional anyway).
                    $photoFailed = false;
                    if ($model->photoFile !== null) {
                        try {
                            $model->savePhoto($model->photoFile);
                        } catch (\Throwable $e) {
                            // Throwable, not RuntimeException: an unwritable uploads
                            // directory surfaces as yii\base\Exception, and letting it
                            // escape here would 500 AFTER the commit and skip the audit rows.
                            Yii::error('Customer photo not stored: ' . (string) $e, __METHOD__);
                            $photoFailed = true;
                        }
                    }

                    Yii::$app->session->setFlash('success', $loanRequested ? 'Customer and loan created.' : 'Customer created.');
                    if ($photoFailed) {
                        Yii::$app->session->setFlash('error', 'The photo could not be saved - you can add it from Edit.');
                    }
                    return $this->redirect(['view', 'id' => $model->id]);
                }
            }
        }

        return $this->render('create', [
            'model' => $model,
            'guarantor' => $guarantor,
            'loan' => $loan,
            'packageOptions' => LoanPackage::selectOptions(),
            'staffOptions' => $staffOptions,
            'duplicates' => $duplicates,
            'marketOptions' => $this->marketOptions(),
        ]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        // Snapshotted before load()/save() mutate the model - AR's own
        // getOldAttributes() can't be used here, since afterSave() resets
        // it to the just-saved values, making old and new identical
        // afterward.
        $oldAttributes = $model->getAttributes();

        $canReassign = Yii::$app->user->can('viewAllCustomers');

        if ($model->load(Yii::$app->request->post())) {
            $model->photoFile = UploadedFile::getInstance($model, 'photoFile');

            // Reassignment is applied here by hand, never through load():
            // assigned_staff_id is deliberately not a safe attribute, so
            // the posted value is ignored entirely unless the current user
            // holds viewAllCustomers, and even then it is accepted only if
            // it names an active staff member (or is blank, meaning
            // unassigned). A staff member posting the field gets no effect
            // at all - not an error, just no effect, since they never see
            // the control.
            if ($canReassign) {
                $posted = Yii::$app->request->post('Customer')['assigned_staff_id'] ?? null;
                $posted = is_scalar($posted) ? trim((string) $posted) : '';
                $staffOptions = User::activeStaffOptions();

                if ($posted === '') {
                    $model->assigned_staff_id = null;
                } elseif (isset($staffOptions[(int) $posted])) {
                    $model->assigned_staff_id = (int) $posted;
                }
                // Anything else (a deleted user, an admin's id, junk) is
                // ignored and the existing owner kept - a bad value must
                // never silently orphan a customer.
            }

            if ($model->save()) {
                $photoFailed = false;
                try {
                    if ($model->photoFile !== null) {
                        $model->savePhoto($model->photoFile);
                    } elseif ($model->removePhoto && $model->photo_filename !== null) {
                        $model->clearPhoto();
                    }
                } catch (\Throwable $e) {
                    // Throwable for the same reason as in actionCreate().
                    Yii::error('Customer photo not stored: ' . (string) $e, __METHOD__);
                    $photoFailed = true;
                }

                AuditLogger::audit('customer_update', 'customer', $model->id, $oldAttributes, $model->getAttributes());
                Yii::$app->session->setFlash('success', 'Customer updated.');
                if ($photoFailed) {
                    Yii::$app->session->setFlash('error', 'The other changes were saved, but the photo could not be stored.');
                }
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('update', [
            'model' => $model,
            'marketOptions' => $this->marketOptions(),
            // Empty for anyone who may not reassign, which is what hides
            // the control - the server-side guard above is what enforces
            // it.
            'ownerOptions' => $canReassign ? User::activeStaffOptions() : [],
        ]);
    }

    /**
     * Streams a customer's photo. Photos live in protected/uploads/
     * customers/, outside the public docroot, so this action is the only
     * way to fetch one - gated by this controller's manageCustomers rule
     * like every other customer page. Same long immutable cache as
     * AvatarController: every URL carries the current photo_filename as
     * `v`, so a replaced photo is a new URL.
     */
    public function actionPhoto($id): Response
    {
        $model = $this->findModel($id);
        if ($model->photo_filename === null) {
            throw new NotFoundHttpException('No photo.');
        }

        $path = AvatarStorage::path($model->photo_filename, AvatarStorage::FOLDER_CUSTOMERS);
        if (!is_file($path)) {
            throw new NotFoundHttpException('No photo.');
        }

        Yii::$app->response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return Yii::$app->response->sendFile($path, null, ['inline' => true]);
    }

    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $oldAttributes = $model->getAttributes();
        $model->softDelete();
        AuditLogger::audit('customer_delete', 'customer', $model->id, $oldAttributes, null);
        Yii::$app->session->setFlash('success', 'Customer deleted.');
        return $this->redirect(['index']);
    }

    public function actionRestore($id)
    {
        // Not findModel(): that one looks at live customers only. Same
        // ownership guard applied by hand, so restore can't be used to
        // reach a colleague's deleted customer.
        $model = Customer::findTrashed()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException('Deleted customer not found.');
        }
        if (!$model->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to restore this customer.');
        }

        $oldAttributes = $model->getAttributes();
        $model->deleted_at = null;
        $model->save(false, ['deleted_at']);

        AuditLogger::audit('customer_restore', 'customer', $model->id, $oldAttributes, $model->getAttributes());
        Yii::$app->session->setFlash('success', 'Customer restored.');
        return $this->redirect(['index', 'filter' => 'deleted']);
    }

    private function marketOptions(): array
    {
        $options = [];
        foreach (Market::activeMarkets() as $market) {
            $options[$market->id] = $market->name;
        }

        return $options;
    }

    /**
     * Every single-customer action in this controller (view, update,
     * photo, delete) routes through here, so the ownership check lives
     * here rather than being repeated - and can't be forgotten when a new
     * action is added later.
     *
     * ForbiddenHttpException, not NotFoundHttpException, to match
     * LoanController::actionView()'s existing treatment of the same
     * situation. That does confirm the id exists to someone who may not
     * see the record; it is the app's established behaviour rather than a
     * new decision, and is noted as a judgment call in the phase report.
     */
    private function findModel($id): Customer
    {
        $model = ModelFinder::findOrFail(Customer::class, $id, 'Customer');

        if (!$model->isVisibleToCurrentUser()) {
            throw new ForbiddenHttpException('You are not allowed to view this customer.');
        }

        return $model;
    }
}
