<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\models\Customer;
use app\models\Market;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Market administration: list, create, update (including the is_active
 * deactivate toggle) and soft-delete + trash/restore - same deactivate-
 * vs-delete distinction as Customer, not LoanPackage's is_active-only
 * pattern (see MARKETS_REDESIGN_REVIEW.md's now-resolved item on this).
 *
 * Gated on manageMarkets throughout (manager and above, per
 * m260916_110000_add_manage_markets_rbac) - market administration affects
 * which staff member customers get attributed to, an operational decision
 * alongside loan issuance rather than routine staff work.
 */
class MarketController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageMarkets'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['get', 'post'],
                    'update' => ['get', 'post'],
                    'delete' => ['post'],
                    'restore' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $markets = Market::find()->with('assignedStaff')->orderBy(['name' => SORT_ASC])->all();

        // One grouped query for every market's customer count, rather than
        // $market->getCustomers()->count() per row (N+1 as the market list
        // grows) - merged into the models in PHP below.
        $counts = Customer::find()
            ->select(['market_id', 'cnt' => 'COUNT(*)'])
            ->andWhere(['not', ['market_id' => null]])
            ->groupBy('market_id')
            ->asArray()
            ->all();
        $customerCounts = array_column($counts, 'cnt', 'market_id');

        return $this->render('index', [
            'markets' => $markets,
            'customerCounts' => $customerCounts,
        ]);
    }

    public function actionCreate()
    {
        $model = new Market();
        // This app's models never call loadDefaultValues(), so a fresh AR
        // instance doesn't pick up the DB column's own defaultValue(true) -
        // set explicitly so the create form's checkbox starts checked
        // rather than silently defaulting to unchecked/null.
        $model->is_active = true;

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('market_create', 'market', $model->id, null, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Market created.');
            return $this->redirect(['index']);
        }

        return $this->render('create', [
            'model' => $model,
            'staffOptions' => User::activeStaffOptions(),
        ]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('market_update', 'market', $model->id, $oldAttributes, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Market updated.');
            return $this->redirect(['index']);
        }

        return $this->render('update', [
            'model' => $model,
            'staffOptions' => User::activeStaffOptions(),
        ]);
    }

    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $oldAttributes = $model->getAttributes();
        $model->softDelete();
        AuditLogger::audit('market_delete', 'market', $model->id, $oldAttributes, null);
        Yii::$app->session->setFlash('success', 'Market deleted.');
        return $this->redirect(['index']);
    }

    public function actionTrash()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Market::findTrashed()->orderBy(['deleted_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('trash', ['dataProvider' => $dataProvider]);
    }

    public function actionRestore($id)
    {
        $model = Market::findTrashed()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException('Deleted market not found.');
        }

        $oldAttributes = $model->getAttributes();
        $model->deleted_at = null;
        $model->save(false, ['deleted_at']);

        AuditLogger::audit('market_restore', 'market', $model->id, $oldAttributes, $model->getAttributes());
        Yii::$app->session->setFlash('success', 'Market restored.');
        return $this->redirect(['trash']);
    }

    private function findModel($id): Market
    {
        return ModelFinder::findOrFail(Market::class, $id, 'Market');
    }
}
