<?php

namespace app\helpers;

/**
 * Formats a raw numeric amount for display as Nigerian Naira - the client's
 * operating currency. Deliberately only touches presentation: every model
 * attribute and every report/CSV export row still stores and passes around
 * plain floats/decimals, exactly as before. Applying this at the database or
 * report-row layer instead would have printed the currency symbol straight
 * into ReportController's exported CSVs (the same $rows array feeds both the
 * HTML table and the CSV download - see ReportController::respond()), which
 * would break re-importing those figures into spreadsheets/accounting tools
 * expecting a plain number. So this is called only from views that render
 * amounts directly to the page, never from anything that also produces CSV.
 */
final class Currency
{
    public static function format($amount): string
    {
        return '₦' . number_format((float) $amount, 2);
    }
}
