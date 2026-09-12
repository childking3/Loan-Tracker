<?php

use yii\db\Migration;

/**
 * Creates the activity_log table.
 *
 * A single physical table serves two distinct RBAC permissions: viewAuditLog
 * (category = audit, for data change history) and viewAccessLogs (category =
 * access, for login/logout and access events). The client brief requires
 * these stay separate permissions even though they share one table, so
 * category is filtered at the query layer, not split into two tables.
 *
 * user_id is nullable with ON DELETE SET NULL, unlike the RESTRICT used on
 * the other user foreign keys in this schema: log rows must survive even if
 * the acting user is later removed, and some entries (a scheduled console
 * command such as the overdue-marking job) have no acting user at all.
 *
 * old_value/new_value use the native MySQL JSON type to store a snapshot of
 * changed fields without needing a separate table per entity type.
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
