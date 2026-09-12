<?php

use yii\db\Migration;

/**
 * Creates the user table.
 *
 * This table backs both the web application's identity/auth system (Phase 2)
 * and the foreign keys referenced by customer, loan, repayment and
 * activity_log below (created_by, assigned_staff_id, recorded_by_staff_id,
 * user_id). It is created first so those foreign keys can be added.
 *
 * auth_key follows the standard Yii2 identity convention: a random per-user
 * string used to validate the "remember me" cookie, unrelated to the RBAC
 * auth_* tables created separately by the framework's own RBAC migration.
 */
class m260910_190000_create_user_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey()->unsigned(),
            'username' => $this->string(64)->notNull(),
            'email' => $this->string(255)->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(32)->notNull(),
            'full_name' => $this->string(255)->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(10),
            'created_at' => $this->integer()->unsigned()->notNull(),
            'updated_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-user-username', '{{%user}}', 'username', true);
        $this->createIndex('idx-user-email', '{{%user}}', 'email', true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%user}}');
    }
}
