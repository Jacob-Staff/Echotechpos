<?php
session_start();
require_once '../../includes/conn.php';

// Check security
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id'])) {
    die("Unauthorized access");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $layby_id   = intval($_POST['layby_id']);
    $amount     = floatval($_POST['amount']);
    $user_id    = $_SESSION['user_id'];
    $branch_id  = $_SESSION['branch_id'];
    $method     = "Cash"; // You can expand this to a dropdown in the modal later

    if ($amount <= 0) {
        die("Invalid payment amount.");
    }

    // 1. Fetch current balance to prevent overpayment
    $check_sql = "SELECT balance_due, total_amount FROM laybys WHERE id = ? AND branch_id = ?";
    $c_stmt = $conn->prepare($check_sql);
    $c_stmt->bind_param("ii", $layby_id, $branch_id);
    $c_stmt->execute();
    $current = $c_stmt->get_result()->fetch_assoc();

    if (!$current) {
        die("Lay-by record not found.");
    }

    if ($amount > $current['balance_due']) {
        die("Payment exceeds the remaining balance of K" . number_format($current['balance_due'], 2));
    }

    // 2. Start Transaction to ensure data integrity
    $conn->begin_transaction();

    try {
        // A. Insert into payments history
        $ins_payment = "INSERT INTO layby_payments (layby_id, branch_id, user_id, payment_amount, method, payment_date) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
        $p_stmt = $conn->prepare($ins_payment);
        $p_stmt->bind_param("iiids", $layby_id, $branch_id, $user_id, $amount, $method);
        $p_stmt->execute();

        // B. Update main layby table (Reduce balance)
        $new_balance = $current['balance_due'] - $amount;
        $status = ($new_balance <= 0) ? 'Completed' : 'Pending';

        $up_layby = "UPDATE laybys SET balance_due = ?, status = ? WHERE id = ?";
        $u_stmt = $conn->prepare($up_layby);
        $u_stmt->bind_param("dsi", $new_balance, $status, $layby_id);
        $u_stmt->execute();

        // C. Commit Transaction
        $conn->commit();
        echo "success";

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}