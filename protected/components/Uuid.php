<?php

namespace app\components;

use Yii;

/**
 * A single static method: a standard, RFC 4122 version-4 UUID.
 *
 * Compared against HumHub's `humhub\libs\UUID::v4()`
 * (protected/humhub/libs/UUID.php, Copyright HumHub GmbH & Co. KG - see
 * CODEBASE.md's "Borrowed from HumHub" section): same output format, but
 * randomness source differs deliberately. HumHub pulls 2 of 5 fields from
 * mt_rand() (not a CSPRNG) and the rest from a CSPRNG - fine there since a
 * file guid is just a lookup key, not an access-control boundary. This
 * app already sources every other identifier (avatar filenames, auth_key,
 * CSP nonce) from Yii::$app->security's CSPRNG, so this reuses that for
 * all 122 usable bits instead of mixing in a weaker source.
 */
class Uuid
{
    public static function v4(): string
    {
        $data = Yii::$app->security->generateRandomKey(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10xx

        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
