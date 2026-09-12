<?php

namespace app\helpers;

/**
 * Formats a raw amount as Nigerian Naira for display only - models and
 * report/CSV rows keep plain floats. Applying this at the data layer
 * instead would leak the currency symbol into ReportController's CSV
 * exports (same $rows array feeds both HTML and CSV), breaking re-import
 * into spreadsheets. Only call from views rendering directly to the page.
 */
final class Currency
{
    public static function format($amount): string
    {
        return '₦' . number_format((float) $amount, 2);
    }
}
