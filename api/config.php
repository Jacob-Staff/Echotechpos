<?php
// Prevent PHP from printing HTML warnings before JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Clever Cloud MySQL Credentials
$host = getenv('MYSQL_ADDON_HOST') ?: 'bv6pzrvngmuxd9rws7r6-mysql.services.clever-cloud.com';
$db   = getenv('MYSQL_ADDON_DB')   ?: 'bv6pzrvngmuxd9rws7r6';
$user = getenv('MYSQL_ADDON_USER') ?: 'usvz3enxxtf2hqaq';
$pass = getenv('MYSQL_ADDON_PASSWORD') ?: 'DUrtGfqU1C3kkRLDvyB';
$port = getenv('MYSQL_ADDON_PORT') ?: '20719';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}
?>
