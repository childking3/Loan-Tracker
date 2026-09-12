<?php

namespace app\controllers;

use app\components\CsvExporter;
use app\models\Customer;
use app\models\Loan;
use app\models\Repayment;
use app\models\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
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

    /**
     * Paginated for HTML (row count scales with customer count, unlike
     * e.g. the staff report); CSV export still covers every customer, read
     * in batches via customerCsvRows() rather than one ->all() pull, so a
     * large customer base doesn't have to fit in memory at once for an
     * export.
     */
    public function actionCustomers()
    {
        $header = ['Name', 'Phone', 'Loan Count', 'Total Borrowed', 'Outstanding'];

        // with('loans'): one query for all loans on the page (WHERE
        // customer_id IN (...)) instead of one Loan::find() per customer.
        $query = Customer::find()->with('loans')->orderBy(['full_name' => SORT_ASC]);
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageCustomers = $dataProvider->getModels();
        $pageLoans = [];
        foreach ($pageCustomers as $customer) {
            foreach ($customer->loans as $loan) {
                $pageLoans[] = $loan;
            }
        }
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Customer $c) => $this->customerRow($c, $balances), $pageCustomers);

        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send('customers.csv', $header, $this->customerCsvRows($query));
            return null;
        }

        return $this->render('customers', [
            'header' => $header,
            'rows' => $rows,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @param array<int, float> $balances
     */
    private function customerRow(Customer $customer, array $balances): array
    {
        $loans = $customer->loans;
        $totalBorrowed = 0.0;
        $outstanding = 0.0;
        foreach ($loans as $loan) {
            $totalBorrowed += (float) $loan->principal_amount;
            if (in_array($loan->status, ['active', 'overdue'], true)) {
                $outstanding += $balances[(int) $loan->id] ?? 0.0;
            }
        }

        return [
            $customer->full_name,
            $customer->phone,
            count($loans),
            round($totalBorrowed, 2),
            round($outstanding, 2),
        ];
    }

    /**
     * 500 customers (and their eager-loaded loans) at a time, not the
     * whole table - keeps a CSV export's memory use flat regardless of
     * customer count, using ActiveQuery::batch() rather than a hand-rolled
     * LIMIT/OFFSET loop.
     *
     * @return iterable<array>
     */
    private function customerCsvRows(ActiveQuery $query): iterable
    {
        foreach ($query->batch(500) as $customerBatch) {
            $batchLoans = [];
            foreach ($customerBatch as $customer) {
                foreach ($customer->loans as $loan) {
                    $batchLoans[] = $loan;
                }
            }
            $balances = Loan::remainingBalancesFor($batchLoans);
            foreach ($customerBatch as $customer) {
                yield $this->customerRow($customer, $balances);
            }
        }
    }

    public function actionLoans()
    {
        $status = Yii::$app->request->get('status', '');
        $query = Loan::find();
        if (in_array($status, ['active', 'completed', 'overdue', 'cancelled'], true)) {
            $query->andWhere(['status' => $status]);
        }
        $query->with(['customer', 'assignedStaff'])->orderBy(['created_at' => SORT_DESC]);

        $header = ['Loan Number', 'Customer', 'Status', 'Principal', 'Total Repayment', 'Remaining Balance', 'Start Date', 'Expected Completion', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->loanRow($l, $balances), $pageLoans);

        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send('loans.csv', $header, $this->loanCsvRows($query));
            return null;
        }

        return $this->render('loans', [
            'header' => $header,
            'rows' => $rows,
            'dataProvider' => $dataProvider,
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * @param array<int, float> $balances
     */
    private function loanRow(Loan $loan, array $balances): array
    {
        return [
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

    /**
     * @return iterable<array>
     */
    private function loanCsvRows(ActiveQuery $query): iterable
    {
        foreach ($query->batch(500) as $loanBatch) {
            $balances = Loan::remainingBalancesFor($loanBatch);
            foreach ($loanBatch as $loan) {
                yield $this->loanRow($loan, $balances);
            }
        }
    }

    public function actionRepayments()
    {
        [$from, $to, $query] = $this->dateRangeQuery(
            Repayment::find(),
            'payment_date',
            $this->scalarGet('from'),
            $this->scalarGet('to')
        );
        $query->with(['loan.customer', 'recordedByStaff'])->orderBy(['payment_date' => SORT_DESC, 'created_at' => SORT_DESC]);

        $header = ['Payment Date', 'Loan Number', 'Customer', 'Amount', 'Recorded By'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);
        $rows = array_map([$this, 'repaymentRow'], $dataProvider->getModels());

        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send('repayments.csv', $header, $this->repaymentCsvRows($query));
            return null;
        }

        return $this->render('repayments', [
            'header' => $header,
            'rows' => $rows,
            'dataProvider' => $dataProvider,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    private function repaymentRow(Repayment $repayment): array
    {
        return [
            $repayment->payment_date,
            $repayment->loan->loan_number,
            $repayment->loan->customer->full_name,
            $repayment->amount,
            $repayment->recordedByStaff->full_name,
        ];
    }

    /**
     * @return iterable<array>
     */
    private function repaymentCsvRows(ActiveQuery $query): iterable
    {
        foreach ($query->batch(500) as $repaymentBatch) {
            foreach ($repaymentBatch as $repayment) {
                yield $this->repaymentRow($repayment);
            }
        }
    }

    /**
     * The "balance > 0" filter has to run in SQL, not just in the PHP loop
     * below, so it's applied before pagination's LIMIT/OFFSET rather than
     * after - filtering post-page would produce short or empty pages
     * whenever a page happened to contain a mix of paid-off and
     * still-owing loans. Same formula as Loan::remainingBalancesFor(),
     * expressed as a correlated subquery.
     */
    public function actionOutstanding()
    {
        $query = Loan::find()
            ->with(['customer', 'assignedStaff'])
            ->andWhere(['in', 'status', ['active', 'overdue']])
            ->andWhere('total_repayment - COALESCE((SELECT SUM(amount) FROM {{%repayment}} WHERE loan_id = {{%loan}}.id), 0) > 0');

        $header = ['Loan Number', 'Customer', 'Status', 'Remaining Balance', 'Expected Completion', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->outstandingRow($l, $balances), $pageLoans);

        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send('outstanding.csv', $header, $this->outstandingCsvRows($query));
            return null;
        }

        return $this->render('outstanding', [
            'header' => $header,
            'rows' => $rows,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @param array<int, float> $balances
     */
    private function outstandingRow(Loan $loan, array $balances): array
    {
        return [
            $loan->loan_number,
            $loan->customer->full_name,
            $loan->status,
            $balances[(int) $loan->id] ?? 0.0,
            $loan->expected_completion_date,
            $loan->assignedStaff->full_name,
        ];
    }

    /**
     * @return iterable<array>
     */
    private function outstandingCsvRows(ActiveQuery $query): iterable
    {
        foreach ($query->batch(500) as $loanBatch) {
            $balances = Loan::remainingBalancesFor($loanBatch);
            foreach ($loanBatch as $loan) {
                yield $this->outstandingRow($loan, $balances);
            }
        }
    }

    public function actionOverdue()
    {
        $query = Loan::find()->with(['customer', 'assignedStaff'])->andWhere(['status' => 'overdue'])->orderBy(['expected_completion_date' => SORT_ASC]);

        $header = ['Loan Number', 'Customer', 'Remaining Balance', 'Expected Completion', 'Days Overdue', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->overdueRow($l, $balances), $pageLoans);

        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send('overdue.csv', $header, $this->overdueCsvRows($query));
            return null;
        }

        return $this->render('overdue', [
            'header' => $header,
            'rows' => $rows,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * @param array<int, float> $balances
     */
    private function overdueRow(Loan $loan, array $balances): array
    {
        $daysOverdue = (int) ((strtotime(date('Y-m-d')) - strtotime($loan->expected_completion_date)) / 86400);

        return [
            $loan->loan_number,
            $loan->customer->full_name,
            $balances[(int) $loan->id] ?? 0.0,
            $loan->expected_completion_date,
            $daysOverdue,
            $loan->assignedStaff->full_name,
        ];
    }

    /**
     * @return iterable<array>
     */
    private function overdueCsvRows(ActiveQuery $query): iterable
    {
        foreach ($query->batch(500) as $loanBatch) {
            $balances = Loan::remainingBalancesFor($loanBatch);
            foreach ($loanBatch as $loan) {
                yield $this->overdueRow($loan, $balances);
            }
        }
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
