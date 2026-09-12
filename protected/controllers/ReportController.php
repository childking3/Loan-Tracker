<?php

namespace app\controllers;

use app\components\CsvExporter;
use app\models\Customer;
use app\models\Loan;
use app\models\Repayment;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Reports: customer, loan, repayment, outstanding, overdue, daily
 * collections, staff performance - the set named in the client brief.
 * Every report renders as an HTML table and can be exported as CSV via
 * ?export=csv on the same URL (same query/filter parameters apply to
 * both), rather than duplicating each report as a separate export action.
 */
class ReportController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['viewReports'],
                    ],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index');
    }

    public function actionCustomers()
    {
        // with('loans'): one query for all loans (WHERE customer_id IN
        // (...)) instead of one Loan::find() per customer in a loop.
        $customers = Customer::find()->with('loans')->orderBy(['full_name' => SORT_ASC])->all();

        // Avoids calling getRemainingBalance() per loan (a KeyDB round
        // trip, or SUM query, each) - remainingBalancesFor() answers the
        // whole report in one query instead.
        $allLoans = [];
        foreach ($customers as $customer) {
            foreach ($customer->loans as $loan) {
                $allLoans[] = $loan;
            }
        }
        $balances = Loan::remainingBalancesFor($allLoans);

        $rows = [];
        foreach ($customers as $customer) {
            $loans = $customer->loans;
            $totalBorrowed = 0.0;
            $outstanding = 0.0;
            foreach ($loans as $loan) {
                $totalBorrowed += (float) $loan->principal_amount;
                if (in_array($loan->status, ['active', 'overdue'], true)) {
                    $outstanding += $balances[(int) $loan->id] ?? 0.0;
                }
            }

            $rows[] = [
                $customer->full_name,
                $customer->phone,
                count($loans),
                round($totalBorrowed, 2),
                round($outstanding, 2),
            ];
        }

        return $this->respond('customers', ['Name', 'Phone', 'Loan Count', 'Total Borrowed', 'Outstanding'], $rows);
    }

    public function actionLoans()
    {
        $status = Yii::$app->request->get('status', '');
        $query = Loan::find();
        if (in_array($status, ['active', 'completed', 'overdue', 'cancelled'], true)) {
            $query->andWhere(['status' => $status]);
        }

        $loans = $query->with(['customer', 'assignedStaff'])->orderBy(['created_at' => SORT_DESC])->all();
        $balances = Loan::remainingBalancesFor($loans);

        $rows = [];
        foreach ($loans as $loan) {
            $rows[] = [
                $loan->loan_number,
                $loan->customer->full_name,
                $loan->status,
                $loan->principal_amount,
                $loan->total_repayment,
                $balances[(int) $loan->id] ?? 0.0,
                $loan->start_date,
                $loan->expected_completion_date,
                $loan->assignedStaff->full_name,
            ];
        }

        return $this->respond(
            'loans',
            ['Loan Number', 'Customer', 'Status', 'Principal', 'Total Repayment', 'Remaining Balance', 'Start Date', 'Expected Completion', 'Assigned Staff'],
            $rows,
            ['status' => $status]
        );
    }

    public function actionRepayments()
    {
        [$from, $to, $query] = $this->dateRangeQuery(
            Repayment::find(),
            'payment_date',
            $this->scalarGet('from'),
            $this->scalarGet('to')
        );

        $rows = [];
        foreach ($query->with(['loan.customer', 'recordedByStaff'])->orderBy(['payment_date' => SORT_DESC, 'created_at' => SORT_DESC])->all() as $repayment) {
            $rows[] = [
                $repayment->payment_date,
                $repayment->loan->loan_number,
                $repayment->loan->customer->full_name,
                $repayment->amount,
                $repayment->recordedByStaff->full_name,
            ];
        }

        return $this->respond(
            'repayments',
            ['Payment Date', 'Loan Number', 'Customer', 'Amount', 'Recorded By'],
            $rows,
            ['from' => $from, 'to' => $to]
        );
    }

    public function actionOutstanding()
    {
        $loans = Loan::find()->with(['customer', 'assignedStaff'])->andWhere(['in', 'status', ['active', 'overdue']])->all();
        $balances = Loan::remainingBalancesFor($loans);

        $rows = [];
        foreach ($loans as $loan) {
            $balance = $balances[(int) $loan->id] ?? 0.0;
            if ($balance <= 0.0) {
                continue;
            }

            $rows[] = [
                $loan->loan_number,
                $loan->customer->full_name,
                $loan->status,
                $balance,
                $loan->expected_completion_date,
                $loan->assignedStaff->full_name,
            ];
        }

        return $this->respond(
            'outstanding',
            ['Loan Number', 'Customer', 'Status', 'Remaining Balance', 'Expected Completion', 'Assigned Staff'],
            $rows
        );
    }

    public function actionOverdue()
    {
        $today = date('Y-m-d');
        $loans = Loan::find()->with(['customer', 'assignedStaff'])->andWhere(['status' => 'overdue'])->orderBy(['expected_completion_date' => SORT_ASC])->all();
        $balances = Loan::remainingBalancesFor($loans);

        $rows = [];
        foreach ($loans as $loan) {
            $daysOverdue = (int) ((strtotime($today) - strtotime($loan->expected_completion_date)) / 86400);

            $rows[] = [
                $loan->loan_number,
                $loan->customer->full_name,
                $balances[(int) $loan->id] ?? 0.0,
                $loan->expected_completion_date,
                $daysOverdue,
                $loan->assignedStaff->full_name,
            ];
        }

        return $this->respond(
            'overdue',
            ['Loan Number', 'Customer', 'Remaining Balance', 'Expected Completion', 'Days Overdue', 'Assigned Staff'],
            $rows
        );
    }

    public function actionDailyCollections()
    {
        [$from, $to, $query] = $this->dateRangeQuery(
            Repayment::find(),
            'payment_date',
            $this->scalarGet('from'),
            $this->scalarGet('to')
        );

        $data = $query
            ->select(['payment_date', 'total' => 'SUM(amount)', 'count' => 'COUNT(*)'])
            ->groupBy(['payment_date'])
            ->orderBy(['payment_date' => SORT_DESC])
            ->asArray()
            ->all();

        $rows = [];
        foreach ($data as $row) {
            $rows[] = [$row['payment_date'], round((float) $row['total'], 2), $row['count']];
        }

        return $this->respond(
            'daily-collections',
            ['Date', 'Total Collected', 'Repayment Count'],
            $rows,
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Admins are excluded here too, matching DashboardCache's own staff
     * performance table - it tracks loan officers doing collections, not
     * the accounts managing them. See DashboardCache::computeTotals() for
     * why this is done by RBAC role rather than a user-table column.
     */
    public function actionStaff()
    {
        $adminIds = array_map('intval', Yii::$app->authManager->getUserIdsByRole(User::ROLE_ADMIN));

        // Replaces 3 queries per staff row (in a PHP loop, scaling
        // linearly with headcount) with one pass using the same
        // correlated-subquery shape as DashboardCache::computeTotals(),
        // extended with an overdue_loans subquery since this report shows
        // both.
        $excludeAdmins = '';
        $params = [':active' => 'active', ':overdue' => 'overdue', ':statusActive' => User::STATUS_ACTIVE];
        if ($adminIds !== []) {
            $placeholders = [];
            foreach ($adminIds as $index => $adminId) {
                $placeholder = ':admin' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $adminId;
            }
            $excludeAdmins = ' AND u.id NOT IN (' . implode(',', $placeholders) . ')';
        }

        $data = Yii::$app->db->createCommand(
            'SELECT u.full_name,
                    (SELECT COUNT(*) FROM {{%loan}} l WHERE l.assigned_staff_id = u.id AND l.status = :active) AS active_loans,
                    (SELECT COUNT(*) FROM {{%loan}} l WHERE l.assigned_staff_id = u.id AND l.status = :overdue) AS overdue_loans,
                    (SELECT COALESCE(SUM(r.amount), 0) FROM {{%repayment}} r WHERE r.recorded_by_staff_id = u.id) AS total_collected
             FROM {{%user}} u
             WHERE u.status = :statusActive' . $excludeAdmins . '
             ORDER BY u.full_name'
        )->bindValues($params)->queryAll();

        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row['full_name'],
                (int) $row['active_loans'],
                (int) $row['overdue_loans'],
                round((float) $row['total_collected'], 2),
            ];
        }

        return $this->respond('staff', ['Staff', 'Active Loans', 'Overdue Loans', 'Total Collected'], $rows);
    }

    /**
     * Renders the HTML report view, or exports the same rows as CSV when
     * the request carries ?export=csv - both read the same $rows so the
     * two outputs can never drift apart.
     */
    private function respond(string $view, array $header, array $rows, array $filters = [])
    {
        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send($view . '.csv', $header, $rows);
            return null;
        }

        return $this->render($view, [
            'header' => $header,
            'rows' => $rows,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: \yii\db\ActiveQuery}
     */
    private function dateRangeQuery($query, string $column, string $from, string $to): array
    {
        if ($from !== '') {
            $query->andWhere(['>=', $column, $from]);
        }
        if ($to !== '') {
            $query->andWhere(['<=', $column, $to]);
        }

        return [$from, $to, $query];
    }

    /**
     * A query string like ?from[]=x makes request->get() return an array,
     * which dateRangeQuery()'s typed string $from can't accept - an
     * uncaught TypeError otherwise. Same non-scalar guard
     * CustomerController::actionIndex() uses for its own ?q[]=x case:
     * treat it as no filter rather than erroring.
     */
    private function scalarGet(string $param): string
    {
        $value = Yii::$app->request->get($param, '');
        return is_string($value) ? $value : '';
    }
}
