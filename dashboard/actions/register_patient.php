<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access or session expired.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number    = trim($_POST['invoice_number'] ?? '');
    $first_name        = trim($_POST['first_name'] ?? '');
    $last_name         = trim($_POST['last_name'] ?? '');
    $contact_number    = trim($_POST['contact_number'] ?? '');
    $patient_condation = trim($_POST['patient_condation'] ?? 'No');

    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        echo json_encode(['status' => 'error', 'message' => 'Please complete all required fields.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO patients 
        (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation) 
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active', ?)");

    $stmt->bind_param("iisssss", 
        $pharmacy_id, 
        $branch_id, 
        $invoice_number, 
        $first_name, 
        $last_name, 
        $contact_number, 
        $patient_condation
    );

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Patient registered successfully! Ref: ' . $invoice_number]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save patient record: ' . $stmt->error]);
    }

    $stmt->close();
    exit();
}
