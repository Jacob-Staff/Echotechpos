<?php
// Dynamic Base URL detection (Works for local XAMPP and live Render)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];

if (!defined('BASE_URL')) {
    define('BASE_URL', $protocol . $host . '/');
}

// Database Credentials (Reads Render Environment Variables, defaults to XAMPP local)
$db_host = getenv('DB_HOST') ?: "localhost";
$db_user = getenv('DB_USER') ?: "root";
$db_pass = getenv('DB_PASS') ?: "";
$db_name = getenv('DB_NAME') ?: "pharmacy_v1";
$db_port = getenv('DB_PORT') ?: 3306;

// Connection with error suppression to prevent fatal page crashes
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name, (int)$db_port);

if ($conn->connect_error) {
    // Log error locally/silently without crashing the UI layout
    error_log("Database Connection Error: " . $conn->connect_error);
}
?>
