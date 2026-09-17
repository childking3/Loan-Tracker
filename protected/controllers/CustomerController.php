<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\helpers\RequestHelper;
use app\models\Customer;
use app\models\Guarantor;
use app\models\Loan;
use app\models\Market;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

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

    public function actionIndex()
    {
        $query = Customer::find()->with('market')->orderBy(['full_name' => SORT_ASC]);

        $search = trim(RequestHelper::scalarGet('q'));
        if ($search !== '') {
            $query->andWhere(['or',
                ['like', 'full_name', $search],
                ['like', 'phone', $search],
            ]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'search' => $search,
        ]);
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

        return $this->render('view', [
            'model' => $model,
            'loans' => $loans,
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
        $duplicates = [];

        if ($model->load(Yii::$app->request->post())) {
            $guarantor->load(Yii::$app->request->post());
            $confirmDuplicate = (bool) Yii::$app->request->post('confirmDuplicate');

            // Optional, per the approved design: an empty guarantor
            // sub-form is a normal "skip this," not a validation
            // failure - only validated at all once the user has actually
            // started filling it in, avoiding a half-saved row either way.
            $guarantorFilled = trim((string) $guarantor->full_name) !== ''
                || trim((string) $guarantor->phone) !== ''
                || trim((string) $guarantor->address) !== '';

            $customerValid = $model->validate();
            $guarantorValid = !$guarantorFilled || $guarantor->validate();

            if ($customerValid && $guarantorValid) {
                $duplicates = $model->findDuplicatesByPhone();

                if ($duplicates === [] || $confirmDuplicate) {
                    // One form, one submit, per the mockup - both rows
                    // saved together or not at all, mirroring
                    // GuarantorController::actionCreate()'s own
                    // transaction use for the same "two related writes"
                    // shape.
                    $transaction = Yii::$app->db->beginTransaction();
                    try {
                        $model->created_by = Yii::$app->user->id;
                        $model->save(false);

                        if ($guarantorFilled) {
                            $guarantor->customer_id = $model->id;
                            $guarantor->created_by = Yii::$app->user->id;
                            $guarantor->save(false);
                        }

                        $transaction->commit();
                    } catch (\Throwable $e) {
                        $transaction->rollBack();
                        throw $e;
                    }

                    AuditLogger::audit('customer_create', 'customer', $model->id, null, $model->getAttributes());
                    if ($guarantorFilled) {
                        AuditLogger::audit('guarantor_create', 'guarantor', $guarantor->id, null, $guarantor->getAttributes());
                    }

                    Yii::$app->session->setFlash('success', 'Customer created.');
                    return $this->redirect(['view', 'id' => $model->id]);
                }
            }
        }

        return $this->render('create', [
            'model' => $model,
            'guarantor' => $guarantor,
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

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('customer_update', 'customer', $model->id, $oldAttributes, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Customer updated.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', ['model' => $model, 'marketOptions' => $this->marketOptions()]);
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

    /**
     * Unlike User's deliberately-permanent delete (a two-step safety
     * mechanism), a customer has no such design - delete is just delete,
     * so restoring a soft-deleted customer has no reason to require
     * direct database access.
     */
    public function actionTrash()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Customer::findTrashed()->orderBy(['deleted_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('trash', ['dataProvider' => $dataProvider]);
    }

    public function actionRestore($id)
    {
        $model = Customer::findTrashed()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException('Deleted customer not found.');
        }

        $oldAttributes = $model->getAttributes();
        $model->deleted_at = null;
        $model->save(false, ['deleted_at']);

        AuditLogger::audit('customer_restore', 'customer', $model->id, $oldAttributes, $model->getAttributes());
        Yii::$app->session->setFlash('success', 'Customer restored.');
        return $this->redirect(['trash']);
    }

    private function marketOptions(): array
    {
        $options = [];
        foreach (Market::activeMarkets() as $market) {
            $options[$market->id] = $market->name;
        }

        return $options;
    }

    private function findModel($id): Customer
    {
        return ModelFinder::findOrFail(Customer::class, $id, 'Customer');
    }
}
