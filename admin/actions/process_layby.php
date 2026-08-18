<?php
session_start();
header('Content-Type: application/json');
require_once "../../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;
$user_id     = $_SESSION['user_id'] ?? 0;

$customer_name  = $_POST['customer_name'] ?? '';
$customer_phone = $_POST['customer_phone'] ?? '';
$deposit        = floatval($_POST['deposit'] ?? 0);
$total          = floatval($_POST['total'] ?? 0);
$due_date       = $_POST['due_date'] ?? '';
$cart           = json_decode($_POST['cart'] ?? '[]', true);

if (!$customer_name || empty($cart)) {
    echo json_encode(['status'=>'error', 'message'=>'Customer name and items are required.']);
    exit;
}

$balance = $total - $deposit;
$status = ($balance <= 0) ? 'Completed' : 'Active';

// 1. Insert into laybys table
$stmt = $conn->prepare("INSERT INTO laybys (pharmacy_id, branch_id, user_id, customer_name, customer_phone, total_amount, deposit, balance_due, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iiissddsss", $pharmacy_id, $branch_id, $user_id, $customer_name, $customer_phone, $total, $deposit, $balance, $due_date, $status);

if ($stmt->execute()) {
    $layby_id = $stmt->insert_id;

    // 2. Process Cart Items
    foreach($cart as $item) {
        $p_id = intval($item['id']);
        $qty  = intval($item['qty']);
        $price = floatval($item['price']);

        // Insert Item
        $item_stmt = $conn->prepare("INSERT INTO layby_items (layby_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        $item_stmt->bind_param("iiid", $layby_id, $p_id, $qty, $price);
        $item_stmt->execute();

        // 3. IMPORTANT: Deduct Stock from store_items
        $update_stock = $conn->prepare("UPDATE store_items SET quantity = quantity - ? WHERE id = ? AND branch_id = ?");
        $update_stock->bind_param("iii", $qty, $p_id, $branch_id);
        $update_stock->execute();
    }

    // 4. Record Initial Payment
    if ($deposit > 0) {
        $pay_stmt = $conn->prepare("INSERT INTO layby_payments (layby_id, amount, payment_method, recorded_by) VALUES (?, ?, 'Cash', ?)");
        $pay_stmt->bind_param("idi", $layby_id, $deposit, $user_id);
        $pay_stmt->execute();
    }

    echo json_encode(['status'=>'success']);
} else {
    echo json_encode(['status'=>'error', 'message'=>'Failed to save lay-by.']);
}