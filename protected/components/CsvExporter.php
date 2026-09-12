<?php

namespace app\components;

use Yii;
use yii\web\Response;

/**
 * Sends a small dataset to the browser as a CSV download.
 *
 * HumHub's export component
 * (protected/humhub/components/export/SpreadsheetExport.php) builds on
 * the PhpSpreadsheet library for CSV/XLSX/XLS - unusable here
 * (Composer-only) and disproportionate for an app that only ever needs
 * CSV. This uses PHP's built-in fputcsv() directly instead.
 *
 * One technique is deliberately borrowed from HumHub's
 * SpreadsheetExport::sanitizeValue(): prefixing a formula-looking value
 * with a leading apostrophe so Excel/Sheets can't execute a formula
 * planted in, e.g., a customer's name (CSV/formula injection). See
 * CODEBASE.md's "Borrowed from HumHub" section for the license reference.
 *
 * Output is fully buffered rather than streamed - HumHub's exporter
 * buffers too (builds a full PhpSpreadsheet object in memory first), and
 * this app's report sizes are small enough that chunked streaming would
 * add complexity with no real benefit.
 */
class CsvExporter
{
    private const FORMULA_PREFIX_CHARS = "=+-@\t\r";

    /**
     * Checks the first character after leading whitespace, not
     * necessarily $value[0] - a leading space before a formula trigger
     * isn't generally exploitable (import auto-formula-detection needs
     * the trigger character itself at the start), so this is cheap
     * defense-in-depth rather than closing a confirmed exploit; ltrim()
     * first costs nothing either way.
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
     * $filename is always a hardcoded literal at call sites today, not
     * derived from request input, so header injection isn't currently
     * live - sanitized anyway since the check is free and a future caller
     * passing a user-influenced name won't silently reopen it.
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

        // Buffered fully, not streamed to php://output as generated -
        // streaming would flush before Yii calls sendHeaders() once the
        // body exceeds PHP-FPM's 4096-byte output_buffering (php.ini),
        // throwing HeadersAlreadySentException and skipping the CSP
        // headers Csp applies via the response's beforeSend event.
        // Buffering first, then sending through the normal response
        // lifecycle, avoids that.
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $safeFilename . '"');
        $response->content = $csv;
        $response->send();

        Yii::$app->end();
    }
}
