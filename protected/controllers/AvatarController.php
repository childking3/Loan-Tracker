<?php

namespace app\controllers;

use app\components\AvatarStorage;
use app\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Streams a user's avatar. A separate controller rather than a plain
 * static/ file, since images live in protected/uploads/avatars/, outside
 * the public docroot (see AvatarStorage) - this is the only way to
 * reach one.
 *
 * Gated on '@' (any authenticated user), not a specific permission -
 * every logged-in user needs to see every other user's avatar (header,
 * staff list), matching the breadth User::find() already allows.
 *
 * This action's URL (avatar/view?id=X) never changes across a
 * re-upload, unlike HumHub's own profile image URL, which embeds a
 * `?m=<filemtime>` cache-busting query string
 * (protected/humhub/libs/ProfileImage.php::getUrl()). Without that, a
 * browser caching the long Cache-Control below would keep showing a
 * stale picture for up to a year after a re-upload. Every view that
 * builds this URL instead appends the current avatar_filename as a `v`
 * parameter - cheaper than filemtime() since the filename already
 * changes on upload (see AvatarStorage). This action never reads `v`;
 * it exists purely as a cache key, playing HumHub's `?m=` role.
 */
class AvatarController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function actionView($id): Response
    {
        $user = User::findOne($id);
        if ($user === null || $user->avatar_filename === null) {
            throw new NotFoundHttpException('No avatar.');
        }

        $path = AvatarStorage::path($user->avatar_filename);
        if (!is_file($path)) {
            throw new NotFoundHttpException('No avatar.');
        }

        // Safe to cache long and immutable: every caller appends the
        // current avatar_filename as a `v` parameter (see class
        // docblock), so a real change arrives at a different URL rather
        // than needing invalidation.
        Yii::$app->response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return Yii::$app->response->sendFile($path, null, ['inline' => true]);
    }
}
