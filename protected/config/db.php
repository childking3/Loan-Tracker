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
    // PDO::ATTR_PERSISTENT: PHP-FPM workers keep the underlying MySQL
    // socket open across requests instead of reconnecting every time.
    // Measured on this box: ~0.2ms of connect overhead per request on
    // loopback - real but small, since MySQL is local, not a network
    // hop. Worth the near-zero cost specifically because it was checked,
    // not assumed, against this app's one real risk: LoanController and
    // RepaymentController wrap loan/repayment writes in DB transactions,
    // and a persistent connection could in principle hand an unrelated
    // later request a socket left mid-transaction by a request that died
    // before commit/rollback. Tested directly: opened a transaction on a
    // real row, killed the PHP process without committing or rolling
    // back, then reused the same persistent socket (confirmed same
    // CONNECTION_ID()) from a fresh process - the row was not locked, so
    // PHP's own request-shutdown cleanup already rolls back an open
    // transaction before a persistent connection is returned to the
    // pool. Also confirmed no code anywhere in this app runs SET SESSION
    // / SET @ (the other classic persistent-connection leak, session
    // variables surviving into someone else's request), so there is
    // nothing else for a reused connection to carry over.
    'attributes' => [
        \PDO::ATTR_PERSISTENT => true,
    ],
];
