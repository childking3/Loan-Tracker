<?php

use yii\db\Migration;

/**
 * Creates the activity_log table.
 *
 * One physical table serves two RBAC permissions - viewAuditLog (category =
 * audit) and viewAccessLogs (category = access) - filtered at the query
 * layer rather than split into two tables.
 *
 * user_id is nullable with ON DELETE SET NULL, unlike RESTRICT elsewhere in
 * this schema: log rows must survive a removed user, and some entries (e.g.
 * a scheduled job) have no acting user at all.
 *
 * old_value/new_value use native MySQL JSON to store changed-field snapshots
 * without a separate table per entity type.
 */
class m260910_190500_create_activity_log_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%activity_log}}', [
            'id' => $this->primaryKey()->unsigned(),
            'user_id' => $this->integer()->unsigned()->null(),
            'category' => "ENUM('audit','access') NOT NULL",
            'action' => $this->string(100)->notNull(),
            'entity_type' => $this->string(100)->null(),
            'entity_id' => $this->integer()->unsigned()->null(),
            'old_value' => 'JSON NULL',
            'new_value' => 'JSON NULL',
            'ip_address' => $this->string(45)->null(),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->createIndex('idx-activity_log-user_id', '{{%activity_log}}', 'user_id');
        $this->createIndex('idx-activity_log-category', '{{%activity_log}}', 'category');
        $this->createIndex('idx-activity_log-entity', '{{%activity_log}}', ['entity_type', 'entity_id']);

        $this->addForeignKey(
            'fk-activity_log-user_id',
            '{{%activity_log}}',
            'user_id',
            '{{%user}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-activity_log-user_id', '{{%activity_log}}');
        $this->dropTable('{{%activity_log}}');
    }
}
