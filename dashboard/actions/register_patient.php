<?php
// Prevent PHP HTML error dumps from breaking JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Fail-safe absolute path resolving
$base_dir = dirname(__DIR__, 2); // Navigates up to root folder
$conn_path = $base_dir . '/includes/conn.php';
$auth_path = $base_dir . '/includes/auth.php';

if (!file_exists($conn_path)) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection file missing at: ' . $conn_path]);
    exit();
}

require_once $conn_path;
if (file_exists($auth_path)) {
    require_once $auth_path;
}

// Multi-tenant Session Validation
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please re-login.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_number    = trim($_POST['invoice_number'] ?? '');
    $first_name        = trim($_POST['first_name'] ?? '');
    $last_name         = trim($_POST['last_name'] ?? '');
    $contact_number    = trim($_POST['contact_number'] ?? '');
    $patient_condation = trim($_POST['patient_condation'] ?? 'No');

    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in First Name, Last Name, and Phone Number.']);
        exit();
    }

    if (empty($invoice_number)) {
        $invoice_number = 'PAT-' . mt_rand(100000, 999999);
    }

    // Insert Query matching the exact column names of the patients table
    $stmt = $conn->prepare("INSERT INTO patients 
        (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation) 
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Active', ?)");

    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'SQL Query Error: ' . $conn->error]);
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
            'message' => 'Patient registered successfully! Ref: ' . $invoice_number
        ]);
    } else {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'Insert Error: ' . $stmt->error
        ]);
    }

    $stmt->close();
    exit();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit();
}
