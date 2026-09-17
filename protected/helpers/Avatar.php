<?php

namespace app\helpers;

use yii\helpers\Html;

/**
 * `Html::encode(mb_strtoupper(mb_substr($x->full_name, 0, 1)))` - a
 * person's first initial, HTML-encoded - was copied identically 5
 * times (3 inside the User avatar-or-initial views, 2 standalone for
 * Customer, which has no avatar upload at all) - consolidated here
 * after a duplicate-code scan.
 */
final class Avatar
{
    public static function initial(string $fullName): string
    {
        return Html::encode(mb_strtoupper(mb_substr($fullName, 0, 1)));
    }
}
