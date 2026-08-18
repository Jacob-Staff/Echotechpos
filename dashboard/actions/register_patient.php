<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

session_start();
require_once "../../includes/conn.php";

// Multi-tenant Security
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    echo json_encode(['status'=>'error', 'message'=>'Unauthorized access.']);
    exit;
}

// Ensure POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error', 'message'=>'Invalid request method.']);
    exit;
}

// Fetch and sanitize input
$invoice_number = $_POST['invoice_number'] ?? '';
$first_name     = trim($_POST['first_name'] ?? '');
$last_name      = trim($_POST['last_name'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$patient_condation_notes = trim($_POST['patient_condation_notes'] ?? '');
$patient_condation = $_POST['patient_condation'] ?? 'No';

// Validation
if (!$invoice_number || !$first_name || !$last_name || !$contact_number) {
    echo json_encode(['status'=>'error', 'message'=>'Please fill all required fields.']);
    exit;
}

// Check for duplicate invoice_number in this branch
$stmtCheck = $conn->prepare("SELECT patient_id FROM patients WHERE invoice_number=? AND pharmacy_id=? AND branch_id=?");
$stmtCheck->bind_param("sii", $invoice_number, $pharmacy_id, $branch_id);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();

if($resultCheck->num_rows > 0){
    echo json_encode(['status'=>'error', 'message'=>'This invoice/reference number already exists.']);
    exit;
}

// Insert new patient
$registration_date = date('Y-m-d H:i:s');
$status = 'Active';

$stmt = $conn->prepare("INSERT INTO patients (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iisssssss", $pharmacy_id, $branch_id, $invoice_number, $first_name, $last_name, $contact_number, $registration_date, $status, $patient_condation);

if ($stmt->execute()) {
    echo json_encode(['status'=>'success', 'message'=>'Patient registered successfully.']);
} else {
    echo json_encode(['status'=>'error', 'message'=>'Database error: '.$conn->error]);
}
?>