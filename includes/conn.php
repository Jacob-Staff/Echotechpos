<?php
// Detect the base URL of the project
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
// This identifies your project folder (e.g., /pharmacy_v1-master/)
$base_dir = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
// We force the base directory to be the root of the project
$project_root = "/pharmacy_v1-master/"; 

if (!defined('BASE_URL')) {
    define('BASE_URL', $protocol . $host . $project_root);
}
$host = "localhost";
$user = "root";
$pass = "";
$db   = "pharmacy_v1"; // Based on your previous project data

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>