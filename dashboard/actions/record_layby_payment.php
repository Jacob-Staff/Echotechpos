<?php
ob_start();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn_path = __DIR__ . '/../../includes/conn.php';
if (file_exists($conn_path)) {
    require_once $conn_path;
} else {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection file not found at: ' . $conn_path]);
    exit;
}

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

$layby_id       = (int)($_POST['layby_id'] ?? 0);
$payment_amount = (float)($_POST['payment_amount'] ?? 0);
$method         = mysqli_real_escape_string($conn, trim($_POST['method'] ?? 'Cash'));

if ($layby_id <= 0 || $payment_amount <= 0) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment amount or lay-by ID.']);
    exit;
}

// Check existing layby record
$check_sql = "SELECT deposit, balance_due FROM laybys WHERE id = $layby_id AND pharmacy_id = $pharmacy_id AND branch_id = $branch_id LIMIT 1";
$res = mysqli_query($conn, $check_sql);

if (!$res || mysqli_num_rows($res) === 0) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Lay-by record not found.']);
    exit;
}

$layby = mysqli_fetch_assoc($res);
$current_deposit = (float)$layby['deposit'];
$current_balance = (float)$layby['balance_due'];

if ($payment_amount > $current_balance) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Payment amount (K' . number_format($payment_amount, 2) . ') cannot exceed balance due (K' . number_format($current_balance, 2) . ').']);
    exit;
}

$new_deposit = $current_deposit + $payment_amount;
$new_balance = $current_balance - $payment_amount;
$new_status  = ($new_balance <= 0) ? 'Completed' : 'Pending';

// Insert into layby_payments table
$pay_sql = "INSERT INTO layby_payments (pharmacy_id, branch_id, layby_id, user_id, payment_amount, payment_date, method, notes) 
            VALUES ($pharmacy_id, $branch_id, $layby_id, $user_id, $payment_amount, NOW(), '$method', 'Installment Payment')";

if (!mysqli_query($conn, $pay_sql)) {
    $err = mysqli_error($conn);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Failed to record payment in DB: ' . $err]);
    exit;
}

// Update laybys table
$upd_sql = "UPDATE laybys SET deposit = $new_deposit, balance_due = $new_balance, status = '$new_status' WHERE id = $layby_id AND pharmacy_id = $pharmacy_id AND branch_id = $branch_id";

if (!mysqli_query($conn, $upd_sql)) {
    $err = mysqli_error($conn);
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Failed to update layby totals: ' . $err]);
    exit;
}

ob_end_clean();
echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully!']);
exit;