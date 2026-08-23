<?php
/**
 * ============================================================
 * EchoTech POS
 * Central MySQL Database Connection
 * ============================================================
 *
 * Production:
 *   Render -> Clever Cloud MySQL
 *
 * Local development:
 *   XAMPP -> MySQL
 *
 * REQUIRED ENVIRONMENT VARIABLES IN RENDER:
 *
 *   DB_HOST
 *   DB_PORT
 *   DB_NAME
 *   DB_USER
 *   DB_PASS
 *
 * IMPORTANT:
 *   Never place production database credentials in this file.
 * ============================================================
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| Base URL
|--------------------------------------------------------------------------
*/

if (!defined('BASE_URL')) {

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ||
        (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    $protocol = $https ? 'https://' : 'http://';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    define(
        'BASE_URL',
        $protocol . $host . '/'
    );
}


/*
|--------------------------------------------------------------------------
| Database configuration
|--------------------------------------------------------------------------
|
| Production values come ONLY from environment variables.
|
| Local XAMPP fallback:
|   host = localhost
|   port = 3306
|   database = pharmacy_v1
|   user = root
|   password = empty
|
*/

$dbHost = getenv('DB_HOST');
$dbPort = getenv('DB_PORT');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');


/*
|--------------------------------------------------------------------------
| Apply local development defaults
|--------------------------------------------------------------------------
*/

$dbHost = ($dbHost !== false && trim($dbHost) !== '')
    ? trim($dbHost)
    : 'localhost';

$dbPort = ($dbPort !== false && trim($dbPort) !== '')
    ? (int) $dbPort
    : 3306;

$dbName = ($dbName !== false && trim($dbName) !== '')
    ? trim($dbName)
    : 'pharmacy_v1';

$dbUser = ($dbUser !== false && trim($dbUser) !== '')
    ? trim($dbUser)
    : 'root';

$dbPass = ($dbPass !== false)
    ? $dbPass
    : '';


/*
|--------------------------------------------------------------------------
| Validate port
|--------------------------------------------------------------------------
*/

if ($dbPort <= 0) {
    $dbPort = 3306;
}


/*
|--------------------------------------------------------------------------
| Create database connection
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
    | UTF-8 support
    |--------------------------------------------------------------------------
    */

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {

    /*
    |--------------------------------------------------------------------------
    | Log technical error privately
    |--------------------------------------------------------------------------
    */

    error_log(
        'EchoTech POS database connection failed: '
        . $e->getMessage()
    );

    /*
    |--------------------------------------------------------------------------
    | Never expose database details to users
    |--------------------------------------------------------------------------
    */

    http_response_code(500);

    die('Database connection unavailable.');
}
