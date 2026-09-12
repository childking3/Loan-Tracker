<?php

namespace app\components;

use Yii;

/**
 * A single static method: a standard, RFC 4122 version-4 UUID.
 *
 * Compared against HumHub's own `humhub\libs\UUID::v4()`
 * (protected/humhub/libs/UUID.php, Copyright HumHub GmbH & Co. KG - see
 * CODEBASE.md's "Borrowed from HumHub" section) before writing this - the
 * output format is the same, but the randomness source deliberately is
 * not: HumHub's version pulls two of the five fields from mt_rand()
 * (Mersenne Twister, not cryptographically secure) and only the other
 * three from a CSPRNG. That's fine for HumHub's own use (a file's guid is
 * a unique lookup key, not itself an access-control boundary - real
 * permission checks happen separately in File::canRead()/canView()), but
 * this project already generates every other random identifier
 * (avatar filenames until now, auth_key, the CSP nonce) from
 * Yii::$app->security's CSPRNG throughout, so this reuses that same
 * source for all 122 usable bits rather than mixing in a weaker one just
 * to match HumHub's exact implementation.
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
