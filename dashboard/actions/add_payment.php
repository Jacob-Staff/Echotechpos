<?php
session_start();
header('Content-Type: application/json');
require_once "../../includes/conn.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

$layby_id = intval($_POST['layby_id'] ?? 0);
$amount   = floatval($_POST['amount'] ?? 0);
$method   = trim($_POST['method'] ?? 'Cash');

if ($layby_id <= 0 || $amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment parameters.']);
    exit;
}

$conn->begin_transaction();

try {
    // 1. Fetch current Lay-by
    $stmt = $conn->prepare("SELECT balance_due FROM laybys WHERE id = ? AND branch_id = ? AND pharmacy_id = ?");
    $stmt->bind_param("iii", $layby_id, $branch_id, $pharmacy_id);
    $stmt->execute();
    $layby = $stmt->get_result()->fetch_assoc();

    if (!$layby) {
        throw new Exception("Lay-by agreement not found.");
    }

    $current_balance = floatval($layby['balance_due']);
    if ($amount > $current_balance) {
        throw new Exception("Payment amount exceeds remaining balance.");
    }

    $new_balance = $current_balance - $amount;
    $new_status  = ($new_balance <= 0) ? 'Completed' : 'Active';

    // 2. Insert payment
    $pay_stmt = $conn->prepare("INSERT INTO layby_payments (pharmacy_id, branch_id, layby_id, user_id, payment_amount, payment_date, method) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $pay_stmt->bind_param("iiiids", $pharmacy_id, $branch_id, $layby_id, $user_id, $amount, $method);
    $pay_stmt->execute();

    // 3. Update Lay-by record balance
    $up_stmt = $conn->prepare("UPDATE laybys SET deposit = deposit + ?, balance_due = ?, status = ? WHERE id = ?");
    $up_stmt->bind_param("ddsi", $amount, $new_balance, $new_status, $layby_id);
    $up_stmt->execute();

    $conn->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
