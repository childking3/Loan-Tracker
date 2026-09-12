<?php

namespace app\components;

use Yii;
use yii\web\Response;

/**
 * Streams a small dataset to the browser as a CSV download.
 *
 * HumHub's own export component
 * (protected/humhub/components/export/SpreadsheetExport.php) builds on the
 * PhpSpreadsheet library to support CSV, XLSX and XLS from one codebase.
 * That is not usable here at all: it is a Composer package, and this
 * project has no Composer. Even setting that aside, it would be
 * disproportionate for an app that only ever needs CSV - PhpSpreadsheet
 * exists to also handle spreadsheet formats' internal binary structure,
 * which CSV does not have. This class instead uses PHP's built-in
 * fputcsv() directly, which is all plain CSV output needs.
 *
 * One real technique is deliberately borrowed from HumHub's
 * SpreadsheetExport::sanitizeValue(): prefixing a value that looks like a
 * spreadsheet formula with a leading apostrophe before writing it out, so
 * opening the exported file in Excel/Sheets cannot execute a formula
 * planted in, say, a customer's name field (CSV/formula injection). See
 * CODEBASE.md's "Borrowed from HumHub" section for the file and license
 * reference this was adapted from.
 *
 * Output is fully buffered rather than streamed to the client as it is
 * generated. HumHub's exporter buffers too (it builds a complete
 * PhpSpreadsheet object in memory before writing it out), and the report
 * sizes in this application - a lending business's own customers, loans
 * and repayments - are small enough that true chunked streaming would add
 * complexity without a real benefit here.
 */
class CsvExporter
{
    private const FORMULA_PREFIX_CHARS = "=+-@\t\r";

    /**
     * Checks the first character *after* leading whitespace, not
     * necessarily $value[0] - a leading space before a formula trigger
     * (" =SUM(1+1)") is generally understood not to be exploitable in
     * mainstream spreadsheet software in the first place (a raw CSV cell
     * has to start with the trigger character itself for auto-formula
     * detection on import - the same reason the leading-apostrophe
     * mitigation below works at all), so this isn't closing a confirmed
     * live exploit. Added anyway as cheap, harmless defense-in-depth
     * raised during a pentest review - checking after ltrim() costs
     * nothing and never changes output for a value that didn't already
     * start with whitespace.
     */
    public static function sanitizeCell($value): string
    {
        $value = (string) $value;
        $trimmed = ltrim($value);
        if ($trimmed !== '' && strpbrk($trimmed[0], self::FORMULA_PREFIX_CHARS) !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Sends the CSV and terminates the request. $rows may be any iterable
     * of indexed arrays matching $header's column count.
     *
     * $filename is always a hardcoded literal at every call site today
     * (ReportController's action names, ImportController's fixed template
     * name) - never derived from request input - so header-injection via
     * this parameter isn't a live vulnerability. Sanitized anyway since
     * it's a shared, reusable component and the check is free; a future
     * caller passing a user-influenced name won't silently reopen this.
     */
    public static function send(string $filename, array $header, iterable $rows): void
    {
        $safeFilename = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($filename));
        if ($safeFilename === '' || $safeFilename === null) {
            $safeFilename = 'export.csv';
        }

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, array_map([self::class, 'sanitizeCell'], $header));
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'sanitizeCell'], $row));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // Buffered in full rather than streamed to php://output as it's
        // generated - deliberately kept this way, not overlooked. This
        // app's realistic export sizes (memory_limit is unlimited on this
        // box regardless) never approach a real memory concern, and
        // switching to raw php://output writes ahead of
        // $response->send() is actively unsafe here: PHP-FPM's own
        // output_buffering is 4096 bytes (confirmed in
        // /etc/php/8.4/fpm/php.ini), so any export whose CSV body exceeds
        // ~4KB - true of nearly any real report - would auto-flush to the
        // client before Yii ever calls sendHeaders(), which throws a hard
        // HeadersAlreadySentException (see yii\web\Response::sendHeaders()
        // - it checks headers_sent() and throws rather than silently
        // continuing) instead of the intended Content-Type/
        // Content-Disposition, and would also skip the CSP/security
        // headers this app's Csp component applies via the response's
        // beforeSend event, which send() is what triggers. Buffering
        // fully first, then sending through the normal response
        // lifecycle, is what keeps that working.
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $safeFilename . '"');
        $response->content = $csv;
        $response->send();

        Yii::$app->end();
    }
}
