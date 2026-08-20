<?php
// Suppress unexpected HTML errors from breaking JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Use __DIR__ for fail-safe file inclusion
require_once __DIR__ . "/../../includes/conn.php";
require_once __DIR__ . "/../../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    echo json_encode([
        'status'  => 'error', 
        'message' => 'Session expired. Please log in again.'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number    = trim($_POST['invoice_number'] ?? '');
    $first_name        = trim($_POST['first_name'] ?? '');
    $last_name         = trim($_POST['last_name'] ?? '');
    $contact_number    = trim($_POST['contact_number'] ?? '');
    $patient_condation = trim($_POST['patient_condation'] ?? 'No');

    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'Please fill in all required fields (First Name, Last Name, Contact).'
        ]);
        exit();
    }

    if (empty($invoice_number)) {
        $invoice_number = 'PAT-' . mt_rand(100000, 999999);
    }

    $stmt = $conn->prepare("INSERT INTO patients 
        (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation) 
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active', ?)");

    if (!$stmt) {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'Database Query Error: ' . $conn->error
        ]);
        exit();
    }

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
        echo json_encode([
            'status'  => 'success', 
            'message' => 'Patient successfully registered! Ref: ' . $invoice_number
        ]);
    } else {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'Failed to save record: ' . $stmt->error
        ]);
    }

    $stmt->close();
    exit();
} else {
    echo json_encode([
        'status'  => 'error', 
        'message' => 'Invalid request method.'
    ]);
    exit();
}
