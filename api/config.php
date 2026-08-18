<?php
// api/config.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Database Credentials
$host = "localhost";
$db_name = "pharmacy_v1";
$username = "root";
$password = "";

try {
    $conn = new mysqli($host, $username, $password, $db_name);
    if ($conn->connect_error) {
        die(json_encode(["error" => "Connection failed"]));
    }
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>