<?php
declare(strict_types=1);

/**
 * PDO factory. One connection per request.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    } catch (PDOException $e) {
        if (APP_ENV === 'development') {
            http_response_code(500);
            exit('DB connection failed: ' . $e->getMessage());
        }
        error_log('[DB] ' . $e->getMessage());
        http_response_code(500);
        exit('Database temporarily unavailable.');
    }

    return $pdo;
}