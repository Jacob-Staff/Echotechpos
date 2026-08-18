<?php
session_start();
require_once '../../includes/conn.php';
header('Content-Type: application/json');

// 1. Security & Identity Check
if (!isset($_SESSION['branch_id'], $_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login.']);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$user_id = $_SESSION['user_id'];

// Validate inputs
if (!isset($_POST['layby_id']) || !isset($_POST['amount'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing layby ID or payment amount.']);
    exit;
}

$id = intval($_POST['layby_id']);
$amount = floatval($_POST['amount']);

if ($id <= 0 || $amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment amount.']);
    exit;
}

// 2. Fetch record (Ensuring it belongs to THIS branch)
$fetch_sql = "SELECT total_amount, deposit, balance_due FROM laybys WHERE id = ? AND branch_id = ?";
$stmt = mysqli_prepare($conn, $fetch_sql);
mysqli_stmt_bind_param($stmt, "ii", $id, $branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$layby = mysqli_fetch_assoc($result);

if (!$layby) {
    echo json_encode(['status' => 'error', 'message' => 'Lay-by record not found for this branch.']);
    exit;
}

$total_amount = floatval($layby['total_amount']);
$new_deposit = floatval($layby['deposit']) + $amount;
$new_balance = max($total_amount - $new_deposit, 0);
$new_status = ($new_balance <= 0.001) ? 'Completed' : 'Pending';

if ($amount > floatval($layby['balance_due'])) {
    echo json_encode(['status' => 'error', 'message' => 'Payment exceeds the remaining balance.']);
    exit;
}

// 3. Start Transaction for Rigorous Auditing
mysqli_begin_transaction($conn);

try {
    // Record payment with branch_id and user_id tracking
    $insert_payment_sql = "INSERT INTO layby_payments (layby_id, branch_id, user_id, payment_amount, method) VALUES (?, ?, ?, ?, 'Cash')";
    $insert_stmt = mysqli_prepare($conn, $insert_payment_sql);
    mysqli_stmt_bind_param($insert_stmt, "iiid", $id, $branch_id, $user_id, $amount);
    mysqli_stmt_execute($insert_stmt);

    // Update main record
    $update_sql = "UPDATE laybys SET deposit = ?, balance_due = ?, status = ? WHERE id = ? AND branch_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "ddsii", $new_deposit, $new_balance, $new_status, $id, $branch_id);
    mysqli_stmt_execute($update_stmt);

    mysqli_commit($conn);

    echo json_encode([
        'status' => 'success',
        'message' => 'Payment of K' . number_format($amount, 2) . ' recorded.',
        'new_balance' => number_format($new_balance, 2),
        'new_status' => $new_status
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['status' => 'error', 'message' => 'Transaction failed: ' . $e->getMessage()]);
}

mysqli_close($conn);