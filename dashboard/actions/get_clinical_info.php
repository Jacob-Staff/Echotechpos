<?php
require "../../includes/conn.php";
header('Content-Type: application/json');

$pid = $_GET['product_id'] ?? 0;

// CHANGE THIS LINE in your get_clinical_info.php
$stmt = $conn->prepare("SELECT * FROM product_details_info WHERE product_id = ?");
$stmt->bind_param("i", $pid);
$stmt->execute();
$result = $stmt->get_result();
$info = $result->fetch_assoc();

if ($info) {
    echo json_encode(['success' => true, 'info' => $info]);
} else {
    // Return empty strings if no data exists yet
    echo json_encode(['success' => true, 'info' => [
        'about_text' => '',
        'uses' => '',
        'directions' => '',
        'side_effects' => '',
        'how_it_works' => '',
        'storage_info' => 'Store below 30°C'
    ]]);
}