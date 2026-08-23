<?php
/**
 * ============================================================
 * EchoTech POS - Database Connection
 * ============================================================
 *
 * Production:
 *   Render -> Clever Cloud MySQL
 *
 * Local development:
 *   XAMPP -> MySQL
 *
 * IMPORTANT:
 *   No database password or production credentials should
 *   ever be stored in this file.
 * ============================================================
 */

declare(strict_types=1);

// Show mysqli exceptions instead of silent connection failures.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Database configuration
|--------------------------------------------------------------------------
|
| Render production variables:
|
| DB_HOST
| DB_PORT
| DB_NAME
| DB_USER
| DB_PASS
|
| Local XAMPP fallback:
|
| DB_HOST = localhost
| DB_PORT = 3306
| DB_NAME = pharmacy_v1
| DB_USER = root
| DB_PASS = ""
|
*/

$dbHost = getenv('DB_HOST');
$dbPort = getenv('DB_PORT');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

// Local XAMPP defaults only when environment variables are not set.
$dbHost = ($dbHost !== false && $dbHost !== '')
    ? $dbHost
    : 'localhost';

$dbPort = ($dbPort !== false && $dbPort !== '')
    ? (int) $dbPort
    : 3306;

$dbName = ($dbName !== false && $dbName !== '')
    ? $dbName
    : 'pharmacy_v1';

$dbUser = ($dbUser !== false && $dbUser !== '')
    ? $dbUser
    : 'root';

$dbPass = ($dbPass !== false)
    ? $dbPass
    : '';

/*
|--------------------------------------------------------------------------
| Create connection
|--------------------------------------------------------------------------
*/

try {

    $conn = new mysqli(
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        $dbPort
    );

    /*
    |--------------------------------------------------------------------------
    | Character set
    |--------------------------------------------------------------------------
    */

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    /*
    |--------------------------------------------------------------------------
    | Production-safe error handling
    |--------------------------------------------------------------------------
    |
    | Do NOT expose database credentials, hostname or SQL errors
    | to customers.
    |
    */

    error_log(
        'EchoTech POS database connection failed: '
        . $e->getMessage()
    );

    http_response_code(500);

    die('Database connection unavailable.');
}
