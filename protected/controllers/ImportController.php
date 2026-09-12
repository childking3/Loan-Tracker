<?php

namespace app\controllers;

use app\components\AuditLogger;
use app\components\CsvExporter;
use app\models\Customer;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\UploadedFile;

/**
 * CSV import for migrating customer records from the client's old system.
 *
 * Only customers are importable - loans/repayments have derived,
 * business-rule-governed fields (package terms, computed balance, status
 * transitions) that a spreadsheet load can't reproduce without re-running
 * LoanController/RepaymentController's own logic, so those must be
 * re-entered through the normal UI instead.
 */
class ImportController extends Controller
{
    private const EXPECTED_HEADER = ['full_name', 'phone', 'address'];

    /**
     * Hard ceiling on data rows per import - this is a small lending
     * business's own client list, not a bulk pipeline. Without a cap, a
     * single request runs one validate() + one duplicate-check query per
     * row with no upper bound, and the result view renders one <li> per
     * error.
     */
    private const MAX_ROWS = 2000;

    /** Caps how many per-row error messages are kept/rendered, independent of MAX_ROWS. */
    private const MAX_REPORTED_ERRORS = 100;

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['manageCustomers'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'customers' => ['get', 'post'],
                ],
            ],
        ];
    }

    public function actionCustomers()
    {
        $summary = null;

        $file = UploadedFile::getInstanceByName('csvFile');
        if (Yii::$app->request->isPost && $file !== null) {
            $summary = $this->processFile($file->tempName);
        }

        return $this->render('customers', ['summary' => $summary]);
    }

    public function actionCustomersTemplate()
    {
        CsvExporter::send('customer-import-template.csv', self::EXPECTED_HEADER, [
            ['Jane Doe', '08012345678', '12 Example Street, Lagos'],
        ]);
    }

    /**
     * Validates against the same rules and duplicate-phone check the web
     * create form uses, so an imported row is never held to a looser
     * standard. Unlike the form's warn-then-confirm flow, import skips
     * duplicates outright and reports them - no per-row confirmation step
     * makes sense when importing many rows at once.
     */
    private function processFile(string $path): array
    {
        $imported = 0;
        $errors = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            // Narrow window (only a genuine filesystem failure hits this,
            // not anything client-triggerable) but still guarded:
            // fgetcsv(false) is a PHP 8 TypeError, which would otherwise
            // surface as a generic 500 instead of this clean message.
            return [
                'imported' => 0,
                'errors' => ['Could not read the uploaded file. Please try again.'],
            ];
        }

        $header = fgetcsv($handle);

        if ($header === false || array_map('trim', $header) !== self::EXPECTED_HEADER) {
            fclose($handle);
            return [
                'imported' => 0,
                'errors' => ['The file\'s header row does not match the expected template: ' . implode(', ', self::EXPECTED_HEADER) . '.'],
            ];
        }

        $rowNumber = 1;
        $dataRowCount = 0;
        $truncated = false;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Checked against total lines scanned, not $dataRowCount: a
            // blank row `continue`s before $dataRowCount is touched, so
            // counting only data rows would let a file padded with blank
            // lines bypass this cap entirely. A legitimate file has no
            // reason to contain near-2000 blank lines, so counting them
            // the same costs nothing real.
            if ($rowNumber - 1 > self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $isBlank = count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0;
            if ($isBlank) {
                continue;
            }

            $dataRowCount++;

            [$fullName, $phone, $address] = array_pad($row, 3, null);

            $customer = new Customer();
            $customer->full_name = trim((string) $fullName);
            $customer->phone = trim((string) $phone);
            $customer->address = trim((string) $address);
            $customer->created_by = Yii::$app->user->id;

            if (!$customer->validate()) {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = "Row {$rowNumber}: " . implode(' ', $customer->getFirstErrors());
                }
                continue;
            }

            $duplicates = $customer->findDuplicatesByPhone();
            if ($duplicates !== []) {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = "Row {$rowNumber}: skipped, phone {$customer->phone} already belongs to {$duplicates[0]->full_name}.";
                }
                continue;
            }

            $customer->save(false);
            $imported++;
        }

        fclose($handle);

        if ($truncated) {
            $errors[] = 'File exceeds the ' . self::MAX_ROWS . '-row limit per import; rows beyond that point were not processed. Split the file and import it in batches.';
        } elseif (count($errors) >= self::MAX_REPORTED_ERRORS) {
            $errors[] = 'Only the first ' . self::MAX_REPORTED_ERRORS . ' row errors are shown.';
        }

        // One summary row per import run, not one per imported customer -
        // a 2000-row file would otherwise multiply the audit log by up to
        // 2000 rows for a single click. Individual customers are still
        // identifiable via their own customer_create rows if ever needed.
        AuditLogger::audit('customer_import', null, null, null, [
            'imported' => $imported,
            'rows_seen' => $dataRowCount,
            'error_count' => count($errors),
        ]);

        return ['imported' => $imported, 'errors' => $errors];
    }
}
