<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\Customer;
use app\models\Loan;
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
                    // create/update each render a plain GET form and
                    // process a POST submission of it - not POST-only
                    // like delete, but still explicitly restricted
                    // rather than left open to every HTTP verb by
                    // default. Added during a security recheck that
                    // looked specifically for actions relying only on
                    // load()'s own post()-only read as their real
                    // protection against a non-GET/POST verb, rather
                    // than an explicit filter saying so - same
                    // principle already applied to every POST-only
                    // action in this app (see SiteController's own
                    // "GET-able logout is a CSRF vector" comment),
                    // just not yet extended to the GET+POST actions.
                    'create' => ['get', 'post'],
                    'update' => ['get', 'post'],
                    'restore' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $query = Customer::find()->orderBy(['full_name' => SORT_ASC]);

        // request->get() returns whatever the client sent - a query string
        // like ?q[]=x makes this an array, and casting an array to string
        // throws under Yii's error handler (which converts the resulting
        // PHP warning into an exception) rather than silently coercing.
        // Treat anything non-scalar as no search term.
        $rawSearch = Yii::$app->request->get('q', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');
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

        // Was a raw SQL query against the loan table, from before the Loan
        // ActiveRecord model existed (Phase 4 added it) - switched to the AR
        // relation so this view could show which staff member is handling
        // each loan (getAssignedStaff()) and its remaining balance
        // (getRemainingBalance(), which needs a real Loan instance, not an
        // array row) without hand-rolling either lookup a second time here.
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
        $duplicates = [];

        if ($model->load(Yii::$app->request->post())) {
            $confirmDuplicate = (bool) Yii::$app->request->post('confirmDuplicate');

            if ($model->validate()) {
                $duplicates = $model->findDuplicatesByPhone();

                if ($duplicates === [] || $confirmDuplicate) {
                    $model->created_by = Yii::$app->user->id;
                    $model->save(false);
                    AuditLogger::audit('customer_create', 'customer', $model->id, null, $model->getAttributes());
                    Yii::$app->session->setFlash('success', 'Customer created.');
                    return $this->redirect(['view', 'id' => $model->id]);
                }
            }
        }

        return $this->render('create', [
            'model' => $model,
            'duplicates' => $duplicates,
        ]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        // Snapshotted before load()/save() mutate the model - AR's own
        // getOldAttributes() can't be used here since afterSave() resets it
        // to match the just-saved values, which would make old and new
        // identical by the time this method could read it back.
        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('customer_update', 'customer', $model->id, $oldAttributes, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Customer updated.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', ['model' => $model]);
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
     * Soft-deleted customers had no way back into the app at all before
     * this - the only path was a direct database edit, no different from
     * User's own deliberately-permanent delete (see that model's
     * docblock). Raised as a real gap once a customer actually needed
     * recovering, unlike User's case where a second, permanent step is
     * the intended safety mechanism (deactivate first, delete second) -
     * a customer has no such two-step design, delete is just delete, so
     * there was never a reason for it to be one-way in the first place.
     */
    public function actionTrash()
    {
        $customers = Customer::findTrashed()->orderBy(['deleted_at' => SORT_DESC])->all();

        return $this->render('trash', ['customers' => $customers]);
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

    private function findModel($id): Customer
    {
        $model = Customer::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('Customer not found.');
        }

        return $model;
    }
}
