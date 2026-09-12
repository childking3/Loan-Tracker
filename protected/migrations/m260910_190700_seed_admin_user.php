<?php

use yii\db\Migration;

/**
 * Seeds a single initial admin user so there is a way to log in once
 * authentication is built (a later phase).
 *
 * The password hash below was generated once, offline, with
 * password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]) - the same
 * algorithm and cost yii\base\Security::generatePasswordHash() uses by
 * default - so login can verify it with Yii::$app->security->
 * validatePassword() once Phase 2 implements the login action. The
 * plaintext password is not stored in this file or anywhere in the
 * repository; it was communicated to the client once, at seed time, and
 * should be rotated after first login.
 *
 * No RBAC role is assigned to this user here: role/permission assignment is
 * wired up in Phase 2 alongside the rest of the RBAC setup, not in this
 * schema-and-seed phase.
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
