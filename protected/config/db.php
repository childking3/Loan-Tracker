<?php
/**
 * Database connection configuration, split out of common.php so the
 * connection settings live in their own file, matching the planned
 * config/ layout (common.php, web.php, console.php, db.php, params.php).
 *
 * Depends on env() from env.php and .env already being loaded, which
 * common.php does before requiring this file.
 */

return [
    'class' => 'yii\db\Connection',
    'dsn' => env('DB_DSN'),
    'username' => env('DB_USER'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    // PDO::ATTR_PERSISTENT: reuses the MySQL socket across requests
    // (~0.2ms saved per request on loopback). Checked against the one real
    // risk - Loan/Repayment writes use transactions - by killing a PHP
    // process mid-transaction and reusing its persistent socket: PHP's
    // shutdown cleanup already rolls back the open transaction first, so
    // nothing leaks. No code here uses SET SESSION/SET @ either, so there's
    // nothing else a reused connection could carry over.
    'attributes' => [
        \PDO::ATTR_PERSISTENT => true,
    ],
];
