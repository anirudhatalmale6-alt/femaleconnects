<?php
/**
 * Single PDO connection, shared by every page.
 * Every query in this project is a prepared statement - no string building.
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_SOCKET !== '') {
        $dsn = 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('Girls Connect DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The site cannot reach its database right now. Please try again in a moment.');
    }

    // Store and compare everything in UTC so timestamps stay consistent
    // whatever timezone the hosting account is set to.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/**
 * Same connection, but hands back null instead of stopping the page when it
 * cannot connect. install.php uses this: on a brand new site there are no
 * database details yet, and the setup page still has to render.
 */
function db_probe(): ?PDO
{
    if (DB_SOCKET !== '') {
        $dsn = 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }
    try {
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT  => 5,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

/** Run a query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row, or null. */
function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** First column of the first row, or null. */
function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}
