<?php
// Clever Cloud MySQL Configuration
$host     = 'bv6pzrvngmuxd9rws7r6-mysql.services.clever-cloud.com';
$user     = 'usvz3enxxtf2hqaq';
$pass     = 'DUrtGfqU1C3kkRLDvyB';
$dbname   = 'bv6pzrvngmuxd9rws7r6';
$port     = 20719;

// Enable error reporting for MySQLi
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database connection error: " . $e->getMessage());
}
