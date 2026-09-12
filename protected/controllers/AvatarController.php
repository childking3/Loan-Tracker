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
 * Streams a user's avatar image. A separate controller rather than a
 * plain static/ file, since the image itself lives in
 * protected/uploads/avatars/ - outside the public docroot (see
 * AvatarStorage's own docblock for why) - and so has no directly
 * fetchable URL of its own; this is the only way to reach one.
 *
 * Gated on '@' (any authenticated user), not a specific permission -
 * every logged-in user needs to see every other user's avatar (header,
 * staff list), the same breadth User::find() itself already allows for
 * any authenticated read.
 *
 * The URL this action is reached at (avatar/view?id=X) never changes for
 * a given user, even across a re-upload - unlike HumHub's own profile
 * image URL, which embeds a `?m=<filemtime>` cache-busting query string
 * (protected/humhub/libs/ProfileImage.php::getUrl()). Found by comparing
 * against that while adopting this feature's filename scheme: with a
 * fixed URL and the long Cache-Control below, a browser that already
 * cached the old picture would keep showing it for up to a year after a
 * genuine re-upload, with nothing to force a refetch. Every view that
 * builds this URL (layouts/main.php, views/site/profile.php,
 * views/user/index.php, views/user/update.php) appends the user's
 * current avatar_filename as a `v` query parameter instead of a
 * timestamp - cheaper than an extra filemtime() call, since the
 * filename already changes on every upload (see AvatarStorage) and is
 * already loaded on every one of those pages regardless. This action
 * itself never reads `v` - it exists purely as a browser cache key, the
 * same role HumHub's `?m=` plays.
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

        // Safe to cache long and immutable: the URL is only ever reused
        // unchanged for the exact same file content, since every caller
        // includes the current avatar_filename as a `v` cache-busting
        // parameter (see this class's own docblock) - a real change
        // always arrives at a different URL instead of invalidating this
        // one.
        Yii::$app->response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return Yii::$app->response->sendFile($path, null, ['inline' => true]);
    }
}
