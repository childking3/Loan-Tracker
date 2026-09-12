<?php

use yii\db\Migration;

/**
 * Seeds a single initial admin user so login works once auth is built.
 *
 * password_hash was generated offline with the same algorithm/cost (bcrypt,
 * cost 13) yii\base\Security::generatePasswordHash() uses by default, so
 * validatePassword() can verify it once login is implemented. The plaintext
 * password isn't stored anywhere in the repo and should be rotated after
 * first login.
 *
 * No RBAC role is assigned here - role assignment happens in the RBAC
 * migration, not this schema/seed step.
 */
class m260910_190700_seed_admin_user extends Migration
{
    public function safeUp()
    {
        $now = time();

        $this->insert('{{%user}}', [
            'username' => 'admin',
            'email' => 'nelsonsmith681@gmail.com',
            'password_hash' => '$2y$13$trnCNNtwZ0ue8q/T4Q4pWuDy45w.S6HF2GUjWz7M.S4OGCloRc8H.',
            'auth_key' => '1eb4cddf98fac08debe839640347354d',
            'full_name' => 'System Administrator',
            'status' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%user}}', ['username' => 'admin']);
    }
}
