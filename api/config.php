<?php
/**
 * ============================================================
 * EchoTech POS API
 * PDO Database Configuration
 * ============================================================
 *
 * API database connection.
 *
 * Production:
 *   Render -> Clever Cloud MySQL
 *
 * Configuration comes ONLY from environment variables.
 *
 * Supported variables:
 *
 *   DB_HOST
 *   DB_PORT
 *   DB_NAME
 *   DB_USER
 *   DB_PASS
 * ============================================================
 */


/*
|--------------------------------------------------------------------------
| API error handling
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

function api_database_error(string $message = 'Database connection unavailable.'): void
{
    http_response_code(500);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Read environment variables
|--------------------------------------------------------------------------
*/

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');


/*
|--------------------------------------------------------------------------
| Local development fallback
|--------------------------------------------------------------------------
*/

$host = ($host !== false && trim($host) !== '')
    ? trim($host)
    : 'localhost';

$port = ($port !== false && trim($port) !== '')
    ? (int) $port
    : 3306;

$db = ($db !== false && trim($db) !== '')
    ? trim($db)
    : 'pharmacy_v1';

$user = ($user !== false && trim($user) !== '')
    ? trim($user)
    : 'root';

$pass = ($pass !== false)
    ? $pass
    : '';


/*
|--------------------------------------------------------------------------
| PDO configuration
|--------------------------------------------------------------------------
*/

$charset = 'utf8mb4';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    $port,
    $db,
    $charset
);


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];


 /*
 |--------------------------------------------------------------------------
 | Create PDO connection
 |--------------------------------------------------------------------------
 */

try {

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        $options
    );

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | Log technical details privately
    |--------------------------------------------------------------------------
    */

    error_log(
        'EchoTech POS API database connection failed: '
        . $e->getMessage()
    );

    /*
    |--------------------------------------------------------------------------
    | Return safe JSON response
    |--------------------------------------------------------------------------
    */

    api_database_error();
}
