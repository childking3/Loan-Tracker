<?php

namespace app\controllers;

use app\models\ActivityLog;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Read-only viewer for activity_log, split into two permissions:
 * viewAuditLog (manager+) for data changes, viewAccessLogs (admin only)
 * for login/logout - both share one physical table but the brief
 * requires them to stay distinct permissions (see AuditLogger).
 *
 * No export/delete/edit action here - an audit trail a user can prune is
 * not a defense against that same user.
 */
class LogController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['audit'],
                        'allow' => true,
                        'roles' => ['viewAuditLog'],
                    ],
                    [
                        'actions' => ['access'],
                        'allow' => true,
                        'roles' => ['viewAccessLogs'],
                    ],
                ],
            ],
        ];
    }

    public function actionAudit()
    {
        $dataProvider = $this->dataProvider('audit');

        return $this->render('index', [
            'pageTitle' => 'Audit log',
            'dataProvider' => $dataProvider,
            'entityNames' => $this->resolveEntityNames($dataProvider->getModels()),
        ]);
    }

    public function actionAccess()
    {
        $dataProvider = $this->dataProvider('access');

        return $this->render('index', [
            'pageTitle' => 'Access log',
            'dataProvider' => $dataProvider,
            'entityNames' => $this->resolveEntityNames($dataProvider->getModels()),
        ]);
    }

    private function dataProvider(string $category): ActiveDataProvider
    {
        return new ActiveDataProvider([
            'query' => ActivityLog::find()
                ->with('user')
                ->andWhere(['category' => $category])
                ->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 50],
        ]);
    }

    /**
     * One batched query per entity_type on the page, not one per row.
     * Reads names via raw SQL rather than each model's find(), which
     * would silently exclude a soft-deleted record - a log entry should
     * still show who it was about even after the record is deleted.
     *
     * @param ActivityLog[] $entries
     * @return array<string, array<int, string>> entity_type => [entity_id => display name]
     */
    private function resolveEntityNames(array $entries): array
    {
        $idsByType = [];
        foreach ($entries as $entry) {
            if ($entry->entity_type !== null && $entry->entity_id !== null) {
                $idsByType[$entry->entity_type][] = (int) $entry->entity_id;
            }

            // FK values inside this row's own changes (e.g. a loan's
            // customer_id, package_id) need the same name resolution -
            // merged into the same $idsByType map to stay covered by the
            // one batched query below.
            foreach ($entry->getForeignKeyReferences() as $type => $ids) {
                foreach ($ids as $id) {
                    $idsByType[$type][] = $id;
                }
            }
        }

        // [entity_type => [table, id column, name expression]] - name
        // expression is a raw SQL fragment so repayment (no single "name"
        // column) can express its display value via a join.
        $lookups = [
            'customer' => ['{{%customer}}', 'id', 'full_name'],
            'loan' => ['{{%loan}}', 'id', 'loan_number'],
            'loan_package' => ['{{%loan_package}}', 'id', 'name'],
            'guarantor' => ['{{%guarantor}}', 'id', 'full_name'],
            'user' => ['{{%user}}', 'id', 'username'],
            'repayment' => [
                '{{%repayment}} r LEFT JOIN {{%loan}} l ON l.id = r.loan_id',
                'r.id',
                "CONCAT('Repayment on ', COALESCE(l.loan_number, 'unknown loan'))",
            ],
        ];

        $names = [];
        $db = Yii::$app->db;
        foreach ($idsByType as $type => $ids) {
            if (!isset($lookups[$type])) {
                continue;
            }
            [$table, $idColumn, $nameExpr] = $lookups[$type];
            $ids = array_unique($ids);
            $rows = $db->createCommand(
                "SELECT {$idColumn} AS id, {$nameExpr} AS name FROM {$table} WHERE {$idColumn} IN (" . implode(',', $ids) . ')'
            )->queryAll();
            foreach ($rows as $row) {
                $names[$type][(int) $row['id']] = $row['name'];
            }
        }

        return $names;
    }
}
