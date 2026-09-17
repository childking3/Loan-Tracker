<?php

namespace app\helpers;

use yii\helpers\Html;

/**
 * A `<span class="badge badge-{status}">{status}</span>` pill was
 * copied identically across 4 views (customer/_loan_table.php,
 * dashboard/staff.php, loan/index.php, loan/view.php) for rendering a
 * loan's status - consolidated here after a duplicate-code scan.
 */
final class Badge
{
    public static function status(string $status): string
    {
        return Html::tag('span', Html::encode($status), ['class' => 'badge badge-' . $status]);
    }

    /**
     * The same "Yes"/"No" `is_active` pill was copied identically in
     * loan-package/index.php and market/index.php.
     */
    public static function yesNo(bool $active): string
    {
        return $active
            ? Html::tag('span', 'Yes', ['class' => 'badge badge-active'])
            : Html::tag('span', 'No', ['class' => 'badge badge-cancelled']);
    }
}
