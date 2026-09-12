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
 * CSV import, for migrating existing customer records out of the client's
 * old Excel-based system.
 *
 * Only customers are importable. Loans and repayments have derived,
 * business-rule-governed fields (package terms copied at creation time,
 * balance computed from repayments, status transitions) that would be
 * meaningless to bulk-load from a spreadsheet without running the exact
 * same logic LoanController/RepaymentController already enforce - so
 * migrating historical loans, if ever needed, means re-entering them
 * through the normal UI (or a separate, deliberately-scoped tool), not
 * this importer.
 */
class ImportController extends Controller
{
    private const EXPECTED_HEADER = ['full_name', 'phone', 'address'];

    /**
     * Hard ceiling on data rows per import. This app's customer base is a
     * small lending business's own client list, not a bulk data pipeline -
     * a legitimate file will never need to be this large. Without a cap, a
     * single request can tie up a worker for many seconds running one
     * validate() + one duplicate-check query per row with no upper bound
     * (confirmed live: a 100,000-row file took ~19.5s and produced 100,000
     * per-row error strings, one per <li> the result view would render).
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
     * Validates against the exact rules the web create form uses
     * (Customer::rules()) plus the same duplicate-phone check
     * (Customer::findDuplicatesByPhone()), so an imported row can never
     * end up held to a looser standard than a manually entered one. Unlike
     * the web form's warn-then-confirm duplicate flow, a bulk import skips
     * duplicates outright and reports them - there is no per-row
     * interactive confirmation step to hang a "confirm anyway" prompt on
     * when importing many rows at once.
     */
    private function processFile(string $path): array
    {
        $imported = 0;
        $errors = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            // Narrow window in practice - $path is the temp file PHP just
            // finished writing this same request, so this only fires on a
            // genuine filesystem-level failure (disk full, permissions),
            // not anything a client can trigger directly. Still worth
            // guarding: fgetcsv(false) is a TypeError in PHP 8, which
            // would otherwise reach the user as a generic 500 (gated by
            // SiteController::actionError() either way - no message or
            // stack trace leak either way) instead of this clean message.
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

            // Checked against total lines scanned, not $dataRowCount -
            // found during a security recheck that a blank row `continue`s
            // before $dataRowCount is ever touched, so a file padded with
            // blank lines never tripped this cap at all. Within the 2MB
            // upload_max_filesize ceiling that isn't a true unbounded loop,
            // but it did mean the MAX_ROWS limit's whole stated purpose -
            // capping how long one request spends validating rows one at a
            // time - was bypassable by anyone who could reach this action
            // (manageCustomers, so any staff account). A legitimate file
            // has no reason to contain anywhere near 2000 blank lines
            // either, so counting them the same as data rows costs nothing
            // real.
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
        // a 2000-row file (the app's own per-import cap) would otherwise
        // multiply the audit log by up to 2000 rows for a single click,
        // dwarfing every other action's footprint in the table for no
        // proportionate benefit; the individual customers created are
        // already each identifiable via customer_create actions, if a
        // future need to distinguish import-created from form-created rows
        // ever arises, that would need a dedicated column, not more log
        // rows.
        AuditLogger::audit('customer_import', null, null, null, [
            'imported' => $imported,
            'rows_seen' => $dataRowCount,
            'error_count' => count($errors),
        ]);

        return ['imported' => $imported, 'errors' => $errors];
    }
}
