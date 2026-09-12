<?php

use yii\db\Migration;

/**
 * avatar_filename stores only the stored filename (a random token plus
 * extension, generated server-side - see AvatarUploader), never the
 * original uploaded name, so this column can never be used to reconstruct
 * a path or reintroduce path-traversal/enumeration concerns. The actual
 * file lives outside the public docroot, in protected/uploads/avatars/
 * (denied by the vhost like the rest of protected/, matching
 * runtime/cache's own www-data ACL pattern rather than static/'s
 * directly-public one - see AvatarController for how it's served instead).
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
