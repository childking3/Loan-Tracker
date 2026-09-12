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
        $query = Customer::find()->orderBy(['full_name' => SORT_ASC]);

        // A query string like ?q[]=x makes request->get() return an
        // array; casting that to string throws under Yii's error handler.
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
        // getOldAttributes() can't be used here, since afterSave() resets
        // it to the just-saved values, making old and new identical
        // afterward.
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
     * Unlike User's deliberately-permanent delete (a two-step safety
     * mechanism), a customer has no such design - delete is just delete,
     * so restoring a soft-deleted customer has no reason to require
     * direct database access.
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
