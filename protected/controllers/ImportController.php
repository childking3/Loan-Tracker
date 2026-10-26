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
     * Validates against the same rules the web create form uses, so an
     * imported row is never held to a looser standard. Unlike the form's
     * warn-then-confirm flow, import skips duplicates outright and reports
     * them - no per-row confirmation step makes sense when importing many
     * rows at once.
     *
     * Duplicate-phone checking is done against $existingPhones (loaded
     * once below), not Customer::findDuplicatesByPhone() per row - that
     * method runs a query every call, which at MAX_ROWS would mean up to
     * 2000 individual SELECTs for one import. $existingPhones is extended
     * as each row imports, so a duplicate between two rows in the same
     * file is still caught exactly as before, without a second query
     * layer for it.
     */
    private function processFile(string $path): array
    {
        $imported = 0;
        $errors = [];

        // Deliberately scanned across ALL customers, not just the ones the
        // importer may see: a phone number that already exists belongs to
        // somebody regardless of whose customer they are, and scoping this
        // to visible customers would let a staff member import duplicates
        // of a colleague's customers - a data-integrity problem, not a
        // privacy win.
        //
        // What IS scoped is the name in the resulting message. Reporting
        // "phone X already belongs to <name>" to a staff member who cannot
        // see that customer would leak exactly what the per-staff scoping
        // exists to prevent - a colleague's customer name, and by
        // extension their phone number, straight out of an import report.
        // So the owner is carried alongside, and the name is only shown
        // when the importer is entitled to it.
        $canSeeEveryCustomer = Yii::$app->user->can('viewAllCustomers');
        $currentUserId = (int) Yii::$app->user->id;

        $existingPhones = [];
        foreach (Customer::find()->select(['phone', 'full_name', 'assigned_staff_id'])->asArray()->batch(500) as $batch) {
            foreach ($batch as $row) {
                $ownedByImporter = (int) $row['assigned_staff_id'] === $currentUserId;
                $existingPhones[$row['phone']] = $canSeeEveryCustomer || $ownedByImporter
                    ? $row['full_name']
                    : null;
            }
        }

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

        $header = fgetcsv($handle, 0, ',', '"', '');

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
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
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
            // Same ownership rule the registration form uses, shared via
            // the model so the two can't drift. Import has no market
            // column, so for a staff member this is always themselves,
            // and for an admin/manager it is NULL - the imported rows are
            // admin/manager-only until somebody assigns them, which is
            // reported in the import summary rather than left silent.
            $customer->assigned_staff_id = Customer::defaultOwnerForCurrentUser(null);

            if (!$customer->validate()) {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = "Row {$rowNumber}: " . implode(' ', $customer->getFirstErrors());
                }
                continue;
            }

            // array_key_exists, NOT isset(): a masked owner is stored as
            // null above, and isset() is false for null - which would let
            // every duplicate of a customer the importer cannot see pass
            // straight through and be created again.
            if (array_key_exists($customer->phone, $existingPhones)) {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $owner = $existingPhones[$customer->phone];
                    $errors[] = $owner === null
                        ? "Row {$rowNumber}: skipped, phone {$customer->phone} already belongs to another customer."
                        : "Row {$rowNumber}: skipped, phone {$customer->phone} already belongs to {$owner}.";
                }
                continue;
            }

            // Per-row, not left to propagate: an unguarded save() failing on
            // row N of a file with up to 2000 rows used to 500 the whole
            // request, leaving every already-saved row before N (each its
            // own auto-committed statement, not one shared transaction)
            // durably in the database but never reaching the summary audit
            // call below at all - real customers with zero audit trail.
            // Caught here, counted as a row error, and the loop continues.
            try {
                $customer->save(false);
                $existingPhones[$customer->phone] = $customer->full_name;
                $imported++;
            } catch (\Throwable $e) {
                Yii::error("Import row {$rowNumber} failed to save: " . (string) $e, __METHOD__);
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = "Row {$rowNumber}: could not be saved - see the server log for details.";
                }
            }
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
        // Guarded on its own: with every row now caught above, this can
        // only itself fail (a Galera hiccup on the audit insert) - if it
        // does, the import already happened for real, so log it rather
        // than 500 and hide a genuinely successful (if unaudited) import
        // from the user.
        try {
            AuditLogger::audit('customer_import', null, null, null, [
                'imported' => $imported,
                'rows_seen' => $dataRowCount,
                'error_count' => count($errors),
            ]);
        } catch (\Throwable $e) {
            Yii::error('Import summary audit row not written: ' . (string) $e, __METHOD__);
        }

        return ['imported' => $imported, 'errors' => $errors];
    }
}
