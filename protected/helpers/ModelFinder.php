<?php

namespace app\helpers;

use yii\db\ActiveRecordInterface;
use yii\web\NotFoundHttpException;

/**
 * `findOne($id) ?? throw NotFoundHttpException` was copied identically
 * in 5 controllers (Customer/Loan/LoanPackage/Market/User), differing
 * only in the AR class and the "X not found." label - consolidated
 * here after a duplicate-code scan. Each controller keeps its own thin
 * `findModel($id): ConcreteType` wrapper so the concrete return type
 * (and IDE/static-analysis support) isn't lost.
 */
final class ModelFinder
{
    /**
     * @template T of ActiveRecordInterface
     * @param class-string<T> $class
     * @return T
     */
    public static function findOrFail(string $class, $id, string $label): ActiveRecordInterface
    {
        $model = $class::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("{$label} not found.");
        }

        return $model;
    }
}
