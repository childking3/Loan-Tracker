<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\models\LoanPackage;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Admin-only editing of the client's five loan packages, gated on
 * manageSettings.
 *
 * List and edit only, deliberately - no create/delete. The brief
 * describes exactly five fixed packages, not an open-ended set; see
 * LoanPackage's own docblock for why deactivation (is_active) exists
 * instead of delete.
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
                    'update' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $packages = LoanPackage::find()->orderBy(['loan_amount' => SORT_ASC])->all();
        return $this->render('index', ['packages' => $packages]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $oldAttributes = $model->getAttributes();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            AuditLogger::audit('loan_package_update', 'loan_package', $model->id, $oldAttributes, $model->getAttributes());
            Yii::$app->session->setFlash('success', 'Loan package updated.');
            return $this->redirect(['index']);
        }

        return $this->render('update', ['model' => $model]);
    }

    private function findModel($id): LoanPackage
    {
        $model = LoanPackage::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException('Loan package not found.');
        }

        return $model;
    }
}
