<?php

use yii\db\Migration;

/**
 * avatar_filename stores only a server-generated random token + extension
 * (see AvatarUploader), never the original filename, so it can't be used
 * for path-traversal or enumeration. The file itself lives outside the
 * public docroot in protected/uploads/avatars/ (denied by the vhost, like
 * runtime/cache's ACL) and is served via AvatarController.
 */
class m260912_010000_add_avatar_to_user extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'avatar_filename', $this->string(255)->null()->after('full_name'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'avatar_filename');
    }
}
