<?php

namespace app\helpers;

use Yii;

/**
 * A query string like ?q[]=x or ?from[]=x makes Yii's request->get()
 * return an array where a plain string is expected - casting that
 * straight to string, or handing it to a typed string parameter,
 * throws under Yii's error handler. Treat anything non-scalar as "no
 * value" rather than erroring.
 *
 * Consolidated from three near-identical copies (CustomerController,
 * LoanController and ReportController's own private scalarGet() all
 * had this same guard) found during a duplicate-code scan.
 */
final class RequestHelper
{
    public static function scalarGet(string $param, string $default = ''): string
    {
        $value = Yii::$app->request->get($param, $default);
        return is_string($value) ? $value : $default;
    }
}
