<?php

use yii\db\Migration;

/**
 * Moves the failed-login counter out of the cache and into the database.
 *
 * Two problems with the cache-backed counter it replaces (both verified
 * 2026-10-19, AUTH_SESSION_REVIEW.md F1):
 *
 *  1. It failed OPEN. KeydbCache::run() returns its fallback when the store is
 *     unreachable, so getCounter() read 0 and increment() recorded nothing -
 *     12 consecutive wrong passwords went unthrottled with redis stopped, and
 *     valid logins still worked, so nothing looked broken. App node .3 ran with
 *     its KeyDB instance dead until 2026-10-19, meaning roughly half of all
 *     login traffic had no brute-force protection at all, silently.
 *  2. The counter was per node. Each node has its own local cache, so the limit
 *     was effectively MAX_FAILED_ATTEMPTS *per node* rather than overall.
 *
 * The database is already a hard dependency (a login cannot proceed without
 * it, so there is no fail-open path) and it is Galera-replicated, so one
 * counter is now shared by both nodes.
 *
 * username is the primary key so the upsert in LoginForm is atomic; it stores
 * the lowercased submitted string, real account or not, exactly as the cache
 * key did - so lockout behaviour cannot be used to tell valid usernames from
 * invalid ones.
 */
class m261019_210000_create_login_attempt_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%login_attempt}}', [
            'username' => $this->string(64)->notNull(),
            'attempts' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'expires_at' => $this->integer()->unsigned()->notNull(),
        ], 'ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->addPrimaryKey('pk-login_attempt-username', '{{%login_attempt}}', 'username');
        // Supports the opportunistic purge of expired rows, so an attacker
        // cycling through usernames cannot grow this table without bound.
        $this->createIndex('idx-login_attempt-expires_at', '{{%login_attempt}}', 'expires_at');
    }

    public function safeDown()
    {
        $this->dropTable('{{%login_attempt}}');
    }
}
