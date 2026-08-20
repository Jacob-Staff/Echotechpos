<?php
// Suppress default HTML error display and output clean JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../includes/conn.php";

// Validate user session
if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

// Retrieve POST variables
$layby_id       = (int)($_POST['layby_id'] ?? 0);
$payment_amount = (float)($_POST['payment_amount'] ?? 0);
$method         = trim($_POST['method'] ?? 'Cash');

if ($layby_id <= 0 || $payment_amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid lay-by ID or payment amount.']);
    exit;
}

// Fetch current layby status and financial balances
$layby_stmt = mysqli_prepare($conn, "SELECT deposit, total_amount, balance_due FROM laybys WHERE id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1");
mysqli_stmt_bind_param($layby_stmt, "iii", $layby_id, $pharmacy_id, $branch_id);
mysqli_stmt_execute($layby_stmt);
$layby_res = mysqli_stmt_get_result($layby_stmt);
$layby = mysqli_fetch_assoc($layby_res);

if (!$layby) {
    echo json_encode(['status' => 'error', 'message' => 'Lay-by record not found.']);
    exit;
}

$current_deposit = (float)$layby['deposit'];
$total_amount    = (float)$layby['total_amount'];
$current_balance = (float)$layby['balance_due'];

if ($payment_amount > $current_balance) {
    echo json_encode(['status' => 'error', 'message' => 'Payment amount exceeds remaining balance due.']);
    exit;
}

// Calculate updated totals
$new_deposit = $current_deposit + $payment_amount;
$new_balance = $total_amount - $new_deposit;
if ($new_balance < 0) {
    $new_balance = 0.00;
}

$new_status = ($new_balance <= 0) ? 'Completed' : 'Pending';

// Start Database Transaction
mysqli_begin_transaction($conn);

try {
    // 1. Insert transaction record into `layby_payments`
    $pay_stmt = mysqli_prepare($conn, "INSERT INTO layby_payments (pharmacy_id, branch_id, layby_id, user_id, payment_amount, payment_date, method, notes) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'Installment Payment')");
    if (!$pay_stmt) {
        throw new Exception("Prepare payment statement failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($pay_stmt, "iiiids", $pharmacy_id, $branch_id, $layby_id, $user_id, $payment_amount, $method);
    if (!mysqli_stmt_execute($pay_stmt)) {
        throw new Exception("Execute payment failed: " . mysqli_stmt_error($pay_stmt));
    }
    mysqli_stmt_close($pay_stmt);

    // 2. Update parent agreement figures in `laybys`
    $upd_stmt = mysqli_prepare($conn, "UPDATE laybys SET deposit = ?, balance_due = ?, status = ? WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
    if (!$upd_stmt) {
        throw new Exception("Prepare update statement failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($upd_stmt, "ddsiii", $new_deposit, $new_balance, $new_status, $layby_id, $pharmacy_id, $branch_id);
    if (!mysqli_stmt_execute($upd_stmt)) {
        throw new Exception("Execute update failed: " . mysqli_stmt_error($upd_stmt));
    }
    mysqli_stmt_close($upd_stmt);

    mysqli_commit($conn);

    echo json_encode([
        'status' => 'success',
        'message' => 'Payment recorded successfully!',
        'new_balance' => $new_balance
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);

    echo json_encode([
        'status' => 'error',
        'message' => 'Database operation error: ' . $e->getMessage()
    ]);
}