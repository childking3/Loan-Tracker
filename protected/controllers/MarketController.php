<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\helpers\RequestHelper;
use app\models\Customer;
use app\models\Market;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
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
        // Active / Deleted / All tab, mirroring the customers list. Markets
        // are manager/admin-only (manageMarkets), so there is no per-user
        // scoping to layer on - every tab simply picks a soft-delete state.
        // Unknown values fall back to 'active'.
        $filter = RequestHelper::scalarGet('filter');
        $filter = in_array($filter, ['deleted', 'all'], true) ? $filter : 'active';
        $base = $filter === 'deleted'
            ? Market::findTrashed()
            : ($filter === 'all' ? Market::findWithTrashed() : Market::find());

        $markets = $base->with('assignedStaff')->orderBy(['name' => SORT_ASC])->all();

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
            'filter' => $filter,
        ]);
    }

    /**
     * Drill-down for one market (client request 2026-10-20): the money in,
     * the money out and what is still owed, plus the customers behind those
     * figures. Covered by the blanket manageMarkets rule above, same as
     * every other action on this controller.
     */
    public function actionView($id)
    {
        $model = $this->findModel($id);

        // Same query and formula as the dashboard tile, scoped to this one
        // market, so the two can never disagree.
        $summary = Market::balanceRows((int) $model->id)[0] ?? null;

        return $this->render('view', [
            'model' => $model,
            'summary' => $summary,
            'customers' => Market::customerBalanceRows((int) $model->id),
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

        if ($model->load(Yii::$app->request->post())) {
            // save() + audit share one transaction: an unguarded audit
            // insert failure after an uncommitted-elsewhere save used to be
            // able to leave a real change persisted with no audit trail and
            // a raw 500 for the user (same class of gap already fixed for
            // Customer/User this pass).
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($model->save()) {
                    AuditLogger::audit('market_create', 'market', $model->id, null, $model->getAttributes());
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                }
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if (!$model->hasErrors()) {
                Yii::$app->session->setFlash('success', 'Market created.');
                return $this->redirect(['index']);
            }
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

        if ($model->load(Yii::$app->request->post())) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($model->save()) {
                    AuditLogger::audit('market_update', 'market', $model->id, $oldAttributes, $model->getAttributes());
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                }
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if (!$model->hasErrors()) {
                Yii::$app->session->setFlash('success', 'Market updated.');
                return $this->redirect(['index']);
            }
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

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $model->softDelete();
            AuditLogger::audit('market_delete', 'market', $model->id, $oldAttributes, null);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Yii::$app->session->setFlash('success', 'Market deleted.');
        return $this->redirect(['index']);
    }

    public function actionRestore($id)
    {
        $model = Market::findTrashed()->andWhere(['id' => $id])->one();
        if ($model === null) {
            throw new NotFoundHttpException('Deleted market not found.');
        }

        $oldAttributes = $model->getAttributes();
        $model->deleted_at = null;

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $model->save(false, ['deleted_at']);
            AuditLogger::audit('market_restore', 'market', $model->id, $oldAttributes, $model->getAttributes());
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Yii::$app->session->setFlash('success', 'Market restored.');
        return $this->redirect(['index', 'filter' => 'deleted']);
    }

    private function findModel($id): Market
    {
        return ModelFinder::findOrFail(Market::class, $id, 'Market');
    }
}
