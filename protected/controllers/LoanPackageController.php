<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\helpers\ModelFinder;
use app\models\Loan;
use app\models\LoanPackage;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Admin-only management of the client's loan packages, gated on
 * manageSettings: list, create, edit and delete.
 *
 * Delete is guarded, not soft - a package still referenced by any loan
 * can't be removed (loan.package_id's RESTRICT foreign key would refuse
 * it anyway; checked here first so the user gets a plain-English reason
 * instead of a DB exception). Retiring a package without deleting it is
 * still is_active's job, same as before.
 */
class LoanPackageController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageSettings'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['get', 'post'],
                    'update' => ['get', 'post'],
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $packages = LoanPackage::find()->orderBy(['loan_amount' => SORT_ASC])->all();
        return $this->render('index', ['packages' => $packages]);
    }

    public function actionCreate()
    {
        $model = new LoanPackage();
        // Same reasoning as MarketController::actionCreate(): this app's
        // models never call loadDefaultValues(), so set it explicitly
        // rather than rely on the DB column's own default.
        $model->is_active = true;

        if ($model->load(Yii::$app->request->post())) {
            // save() + audit share one transaction - see MarketController's
            // equivalent actions for why (an unguarded audit insert failure
            // could otherwise leave a real change persisted with no audit
            // trail and a raw 500 for the user).
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($model->save()) {
                    AuditLogger::audit('loan_package_create', 'loan_package', $model->id, null, $model->getAttributes());
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                }
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if (!$model->hasErrors()) {
                Yii::$app->session->setFlash('success', 'Loan package created.');
                return $this->redirect(['index']);
            }
        }

        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post())) {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($model->save()) {
                    AuditLogger::audit('loan_package_update', 'loan_package', $model->id, $oldAttributes, $model->getAttributes());
                    $transaction->commit();
                } else {
                    $transaction->rollBack();
                }
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if (!$model->hasErrors()) {
                Yii::$app->session->setFlash('success', 'Loan package updated.');
                return $this->redirect(['index']);
            }
        }

        return $this->render('update', ['model' => $model]);
    }

    public function actionDelete($id)
    {
        $model = $this->findModel($id);

        $loanCount = Loan::find()->andWhere(['package_id' => $model->id])->count();
        if ($loanCount > 0) {
            Yii::$app->session->setFlash('error', "Can't delete \"{$model->name}\" - {$loanCount} loan(s) still reference it. Deactivate it instead to stop it being offered.");
            return $this->redirect(['index']);
        }

        $oldAttributes = $model->getAttributes();

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $model->delete();
            AuditLogger::audit('loan_package_delete', 'loan_package', $model->id, $oldAttributes, null);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Yii::$app->session->setFlash('success', 'Loan package deleted.');
        return $this->redirect(['index']);
    }

    private function findModel($id): LoanPackage
    {
        return ModelFinder::findOrFail(LoanPackage::class, $id, 'Loan package');
    }
}
