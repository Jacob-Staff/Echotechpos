<?php
session_start();
require_once '../includes/conn.php';

header('Content-Type: application/json');

// ✅ 1. Multi-Tenant & Input Validation
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
    exit;
}

if (!isset($_POST['id'], $_POST['amount'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters.']);
    exit;
}

$layby_id = intval($_POST['id']);
$amount = floatval($_POST['amount']);

if ($layby_id <= 0 || $amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID or amount.']);
    exit;
}

// ✅ 2. Fetch record SECURELY (Check ownership)
// We add pharmacy_id and branch_id to the WHERE clause so users can't pay for other branches' laybys.
$query = "SELECT deposit_amount, balance_due, total_amount FROM laybys 
          WHERE id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $layby_id, $pharmacy_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Lay-by not found for this branch.']);
    exit;
}

$layby = $result->fetch_assoc();
$current_deposit = floatval($layby['deposit_amount']);
$total_amount = floatval($layby['total_amount']);

// ✅ 3. Logic Calculations
$new_deposit = $current_deposit + $amount;
$new_balance = $total_amount - $new_deposit;

if ($new_balance < -0.01) { // Allowance for tiny float rounding errors
    echo json_encode(['status' => 'error', 'message' => 'Payment exceeds remaining balance.']);
    exit;
}

// ✅ 4. Update with Prepared Statement
$status = ($new_balance <= 0) ? 'Completed' : 'Pending';

$update_sql = "UPDATE laybys 
               SET deposit_amount = ?, balance_due = ?, status = ? 
               WHERE id = ? AND pharmacy_id = ? AND branch_id = ?";

$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("ddsiii", $new_deposit, $new_balance, $status, $layby_id, $pharmacy_id, $branch_id);

if (!$update_stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
    exit;
}

// ✅ 5. Record History
// Ensure the history table also tracks WHO took the payment (pharmacy/branch)
$insert_payment = $conn->prepare("INSERT INTO layby_payments (layby_id, pharmacy_id, branch_id, amount_paid, payment_date) VALUES (?, ?, ?, ?, NOW())");
$insert_payment->bind_param("iiid", $layby_id, $pharmacy_id, $branch_id, $amount);
$insert_payment->execute();

// ✅ 6. Success
echo json_encode([
    'status' => 'success',
    'message' => 'Payment of K' . number_format($amount, 2) . ' processed.',
    'new_balance' => number_format(max(0, $new_balance), 2),
    'status_text' => $status
]);