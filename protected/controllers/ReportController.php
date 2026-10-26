<?php

namespace app\controllers;

use app\components\CsvExporter;
use app\helpers\RequestHelper;
use app\helpers\WorkingDays;
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
        $header = ['Name', 'Phone', 'Gender', 'Registered', 'Loan Count', 'Total Borrowed', 'Outstanding'];

        // with('loans'): one query for all loans on the page (WHERE
        // customer_id IN (...)) instead of one Loan::find() per customer.
        // Newest registration first, matching the customer list - the
        // client wants customers arranged by date, not alphabetically.
        $query = Customer::find()->with('loans')->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);

        // Registration-date range, same from/to shape as the other
        // reports. created_at is an integer timestamp, hence the parsed
        // day boundaries rather than dateRangeQuery()'s date-column compare.
        $from = RequestHelper::dateGet('from');
        $to = RequestHelper::dateGet('to');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from !== '') {
            $query->andWhere(['>=', 'created_at', strtotime($from . ' 00:00:00')]);
        }
        if ($to !== '') {
            $query->andWhere(['<=', 'created_at', strtotime($to . ' 23:59:59')]);
        }
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

        return $this->respond('customers', $header, $this->customerCsvRows($query), $rows, [
            'dataProvider' => $dataProvider,
            'filters' => ['from' => $from, 'to' => $to],
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
            if ($loan->isOpenForActivity()) {
                $outstanding += $balances[(int) $loan->id] ?? 0.0;
            }
        }

        return [
            $customer->full_name,
            $customer->phone,
            $customer->gender !== null ? (Customer::GENDERS[$customer->gender] ?? $customer->gender) : '',
            date('Y-m-d', $customer->created_at),
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
        if (in_array($status, Loan::STATUSES, true)) {
            $query->andWhere(['status' => $status]);
        }
        $query->with(['customer', 'assignedStaff'])->orderBy(['created_at' => SORT_DESC]);

        $header = ['Loan Number', 'Customer', 'Status', 'Principal', 'Total Repayment', 'Remaining Balance', 'Start Date', 'Expected Completion', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->loanRow($l, $balances), $pageLoans);

        return $this->respond('loans', $header, $this->batchedLoanCsvRows($query, [$this, 'loanRow']), $rows, [
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

    public function actionRepayments()
    {
        [$from, $to, $query] = $this->dateRangeQuery(
            Repayment::find(),
            'payment_date',
            RequestHelper::dateGet('from'),
            RequestHelper::dateGet('to')
        );
        $query->with(['loan.customer', 'recordedByStaff'])->orderBy(['payment_date' => SORT_DESC, 'created_at' => SORT_DESC]);

        $header = ['Payment Date', 'Loan Number', 'Customer', 'Amount', 'Recorded By'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);
        $rows = array_map([$this, 'repaymentRow'], $dataProvider->getModels());

        return $this->respond('repayments', $header, $this->repaymentCsvRows($query), $rows, [
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
            ->andWhere(['in', 'status', Loan::OPEN_STATUSES])
            ->andWhere('total_repayment - COALESCE((SELECT SUM(amount) FROM {{%repayment}} WHERE loan_id = {{%loan}}.id), 0) > 0');

        $header = ['Loan Number', 'Customer', 'Status', 'Remaining Balance', 'Expected Completion', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->outstandingRow($l, $balances), $pageLoans);

        return $this->respond('outstanding', $header, $this->batchedLoanCsvRows($query, [$this, 'outstandingRow']), $rows, ['dataProvider' => $dataProvider]);
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

    public function actionOverdue()
    {
        $query = Loan::find()->with(['customer', 'assignedStaff'])->andWhere(['status' => 'overdue'])->orderBy(['expected_completion_date' => SORT_ASC]);

        $header = ['Loan Number', 'Customer', 'Remaining Balance', 'Expected Completion', 'Working Days Overdue', 'Assigned Staff'];
        $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50]]);

        $pageLoans = $dataProvider->getModels();
        $balances = Loan::remainingBalancesFor($pageLoans);
        $rows = array_map(fn (Loan $l) => $this->overdueRow($l, $balances), $pageLoans);

        return $this->respond('overdue', $header, $this->batchedLoanCsvRows($query, [$this, 'overdueRow']), $rows, ['dataProvider' => $dataProvider]);
    }

    /**
     * @param array<int, float> $balances
     */
    private function overdueRow(Loan $loan, array $balances): array
    {
        // Working days (Mon-Fri) past the expected completion date, like the
        // schedule itself - weekends never count.
        $daysOverdue = WorkingDays::between($loan->expected_completion_date, date('Y-m-d'));

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
     * @param callable(Loan, array<int, float>): array $rowMapper
     * @return iterable<array>
     */
    private function batchedLoanCsvRows(ActiveQuery $query, callable $rowMapper): iterable
    {
        foreach ($query->batch(500) as $loanBatch) {
            $balances = Loan::remainingBalancesFor($loanBatch);
            foreach ($loanBatch as $loan) {
                yield $rowMapper($loan, $balances);
            }
        }
    }

    public function actionDailyCollections()
    {
        [$from, $to, $query] = $this->dateRangeQuery(
            Repayment::find(),
            'payment_date',
            RequestHelper::dateGet('from'),
            RequestHelper::dateGet('to')
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
            $rows,
            ['filters' => ['from' => $from, 'to' => $to]]
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
        // Shared with DashboardCache::computeTotals() via
        // User::staffPerformanceRows() (consolidated 2026-09-18 - see its
        // own docblock) - this report just reads overdue_loans too, which
        // the dashboard ignores.
        $data = User::staffPerformanceRows();

        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row['full_name'],
                (int) $row['active_loans'],
                (int) $row['overdue_loans'],
                round((float) $row['total_collected'], 2),
            ];
        }

        return $this->respond('staff', ['Staff', 'Active Loans', 'Overdue Loans', 'Total Collected'], $rows, $rows);
    }

    /**
     * Renders the HTML report view, or exports as CSV when the request
     * carries ?export=csv - both read from the same underlying query so
     * the two outputs can never drift apart.
     *
     * The "export=csv vs. render the HTML table" branch was copied
     * identically in 4 actions (customers/loans/outstanding/overdue)
     * even though this helper already existed to do exactly that -
     * they weren't using it only because it couldn't yet pass through
     * a `dataProvider` for their pagination. Widened to take the CSV
     * rows (the full, unpaginated result, usually a batched generator)
     * and the HTML rows (one page's worth) separately, plus
     * `$extraViewParams` for whatever else a view needs (`dataProvider`,
     * `filters`) instead of a single hard-coded `filters` param.
     *
     * @param iterable<array> $csvRows
     * @param array<array> $htmlRows
     */
    private function respond(string $view, array $header, iterable $csvRows, array $htmlRows, array $extraViewParams = [])
    {
        if (Yii::$app->request->get('export') === 'csv') {
            CsvExporter::send($view . '.csv', $header, $csvRows);
            return null;
        }

        return $this->render($view, array_merge([
            'header' => $header,
            'rows' => $htmlRows,
        ], $extraViewParams));
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
}
