<?php

namespace app\controllers;

use app\models\ActivityLog;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Read-only viewer for the activity_log table, split into the two
 * permissions the RBAC design has kept apart since Phase 2
 * (m260910_200000_init_rbac): viewAuditLog (manager and up) for data
 * changes, viewAccessLogs (admin only) for login/logout activity - see
 * app\components\AuditLogger for what gets written and why the split
 * matches the client brief's requirement that these stay distinct
 * permissions even though both categories share one physical table.
 *
 * There is deliberately no export/delete/edit action here - an audit
 * trail a user can prune is not a defense against that same user.
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
     * One batched query per entity_type present on the current page,
     * not one query per row - the same N+1-avoidance discipline used
     * everywhere else in this app. Reads the display name straight from
     * SQL rather than through each model's own find() (which would
     * silently exclude a soft-deleted Customer, for example) - a log
     * entry about an action taken against a record is still meaningful
     * after that record is later deleted, and should still show who it
     * was about.
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

            // Every foreign-key value inside this row's own changes
            // (e.g. a loan's customer_id, package_id, assigned_staff_id)
            // needs the same name resolution the Entity column gets -
            // merged into the same $idsByType map so it's covered by
            // the one batched query per type below instead of a second
            // wave of per-row lookups.
            foreach ($entry->getForeignKeyReferences() as $type => $ids) {
                foreach ($ids as $id) {
                    $idsByType[$type][] = $id;
                }
            }
        }

        // [entity_type => [table, id column, name expression]] - name
        // expression is a raw SQL fragment so repayment (which has no
        // single "name" column of its own) can express its display
        // value as a small join instead of needing special-cased code
        // below.
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
