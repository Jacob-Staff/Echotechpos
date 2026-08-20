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
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or session expired.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number          = trim($_POST['invoice_number'] ?? '');
    $first_name              = trim($_POST['first_name'] ?? '');
    $last_name               = trim($_POST['last_name'] ?? '');
    $contact_number          = trim($_POST['contact_number'] ?? '');
    $gender                  = trim($_POST['gender'] ?? 'Male');
    $age                     = trim($_POST['age'] ?? '');
    $address                 = trim($_POST['address'] ?? '');
    $patient_condation       = trim($_POST['patient_condation'] ?? 'No');
    $patient_condation_notes = trim($_POST['patient_condation_notes'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO patients 
        (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, gender, age, address, patient_condation, patient_condation_notes, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmt->bind_param("iisssssssss", 
        $pharmacy_id, 
        $branch_id, 
        $invoice_number, 
        $first_name, 
        $last_name, 
        $contact_number, 
        $gender, 
        $age, 
        $address, 
        $patient_condation, 
        $patient_condation_notes
    );

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Patient registered successfully! Reference: ' . $invoice_number]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
    }

    $stmt->close();
    exit();
}
