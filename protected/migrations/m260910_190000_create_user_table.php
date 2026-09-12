<?php

use yii\db\Migration;

/**
 * User table: backs auth/identity and is the FK target for customer, loan,
 * repayment and activity_log (created_by, assigned_staff_id,
 * recorded_by_staff_id, user_id) - created first so those FKs can be added.
 *
 * auth_key is the standard Yii2 "remember me" cookie validation key, unrelated
 * to the RBAC auth_* tables created by the framework's own RBAC migration.
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
