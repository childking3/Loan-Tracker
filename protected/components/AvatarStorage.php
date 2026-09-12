<?php

namespace app\components;

use RuntimeException;
use Yii;
use yii\helpers\FileHelper;
use yii\web\UploadedFile;

/**
 * Stores uploaded avatars under protected/uploads/avatars/, outside the
 * public docroot (denied by the vhost like the rest of protected/ - see
 * m260912_010000_add_avatar_to_user for why this docroot needs an
 * explicit deny rule). AvatarController is the only way to fetch one back.
 *
 * Stored filenames are always a random token generated here, never
 * derived from the upload, so nothing user-supplied reaches disk as a
 * path component.
 *
 * Validation shape (extension whitelist + max size) adapted from HumHub's
 * UploadProfileImage form
 * (protected/humhub/models/forms/UploadProfileImage.php, Copyright HumHub
 * GmbH & Co. KG, https://www.humhub.com/licences - see CODEBASE.md's
 * "Borrowed from HumHub" section). Deliberately not its ProfileImage
 * class, which resizes/crops via Imagine and stores per content-container
 * by guid - no Composer/Imagine here and no content-container concept, so
 * an image is stored/served exactly as uploaded, capped by MAX_SIZE.
 *
 * Filenames are Uuid::v4() plus extension, sharded 2 levels deep by the
 * filename's own first two hex characters - same scheme as HumHub's file
 * module (@see \humhub\modules\file\components\StorageManager::getPath(),
 * `<base>/<guid[0]>/<guid[1]>/<guid>/file`). Adopted despite today's small
 * scale because the user wants this scalable to an unknown eventual staff
 * count, and the shard costs nothing either way. Not fully copied though:
 * no directory-per-file with an extensionless name inside - that exists
 * to hold multiple size variants, which a single as-uploaded avatar (no
 * resizing) has no equivalent of, and dropping the extension would need a
 * mime_type column to replace what AvatarController's sendFile() call
 * currently detects automatically.
 */
class AvatarStorage
{
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Set to 10MB per the user's explicit request (a modern phone photo
     * often exceeds a few MB). This is only the application-level cap
     * (Yii's FileValidator) - PHP-FPM's own upload_max_filesize/
     * post_max_size (php.ini, currently 2M/8M) sit below this and would
     * reject a file this size before this constant is ever consulted.
     * Raising those needs root; flagged to the user, not fixed here.
     */
    public const MAX_SIZE = 10 * 1024 * 1024;

    public static function directory(): string
    {
        return Yii::getAlias('@app/uploads/avatars');
    }

    /**
     * Resolves a stored filename to its full path, under the 2-level
     * shard its own first two characters place it in - a UUIDv4 always
     * starts with two hex digits, so this never depends on the extension
     * that follows.
     */
    public static function path(string $filename): string
    {
        return self::directory() . '/' . substr($filename, 0, 1) . '/' . substr($filename, 1, 1) . '/' . $filename;
    }

    /**
     * Saves a validated upload under a fresh random filename and returns
     * it. Does not touch the database or delete any previous file - the
     * caller (User::saveAvatar()) owns both of those.
     */
    public static function save(UploadedFile $file): string
    {
        // Belt-and-suspenders beyond the model's FileValidator rule
        // (extensions + maxSize + checkExtensionByMimeType): confirms the
        // bytes actually decode as an image, so a renamed non-image file
        // can't get through even with a spoofed mime type.
        if (@getimagesize($file->tempName) === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $filename = Uuid::v4() . '.' . strtolower((string) $file->extension);
        $path = self::path($filename);
        FileHelper::createDirectory(dirname($path));
        if (!$file->saveAs($path)) {
            throw new RuntimeException('Could not save uploaded avatar.');
        }

        return $filename;
    }

    public static function delete(?string $filename): void
    {
        if ($filename === null) {
            return;
        }

        $path = self::path($filename);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
