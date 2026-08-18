<?php
session_start();
header('Content-Type: application/json');
require_once "../../includes/conn.php";

/* SESSION DATA */
$pharmacy_id = intval($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = intval($_SESSION['branch_id'] ?? 0);
$user_id     = intval($_SESSION['user_id'] ?? 0);

/* INPUT DATA */
$customer_name  = trim($_POST['customer_name'] ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$deposit        = floatval($_POST['deposit'] ?? 0);
$total_amount   = floatval($_POST['total'] ?? 0);
$due_date       = $_POST['due_date'] ?? '';
$cart           = json_decode($_POST['cart'] ?? '[]', true);

/* VALIDATION */
if (empty($customer_name) || empty($cart)) {
    echo json_encode(['status' => 'error', 'message' => 'Customer name or cart missing']);
    exit;
}

if ($deposit < 0 || $total_amount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid amounts']);
    exit;
}

if ($deposit > $total_amount) {
    echo json_encode(['status' => 'error', 'message' => 'Deposit cannot exceed total']);
    exit;
}

$balance = $total_amount - $deposit;
$status  = ($balance <= 0) ? 'Completed' : 'Active';

/* START TRANSACTION */
$conn->begin_transaction();

try {

    /* 1. INSERT LAYBY */
    $stmt = $conn->prepare("
        INSERT INTO laybys 
        (pharmacy_id, branch_id, user_id, customer_name, customer_phone, total_amount, deposit, balance_due, due_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("iiissdddss",
        $pharmacy_id,
        $branch_id,
        $user_id,
        $customer_name,
        $customer_phone,
        $total_amount,
        $deposit,
        $balance,
        $due_date,
        $status
    );

    $stmt->execute();
    $layby_id = $stmt->insert_id;

    /* 2. PREPARE STATEMENTS */
    $item_stmt = $conn->prepare("
        INSERT INTO layby_items 
        (layby_id, product_name, price, qty, total, pharmacy_id, branch_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stock_stmt = $conn->prepare("
        UPDATE store_items 
        SET quantity = quantity - ? 
        WHERE id = ? AND branch_id = ? AND quantity >= ?
    ");

    /* 3. LOOP CART */
    foreach ($cart as $item) {

        $product_id = intval($item['id']);
        $name       = $item['name'];
        $price      = floatval($item['price']);
        $qty        = intval($item['qty']);

        if ($qty <= 0 || $price < 0) {
            throw new Exception("Invalid item values");
        }

        $subtotal = $price * $qty;

        /* INSERT ITEM */
        $item_stmt->bind_param("isdiidi",
            $layby_id,
            $name,
            $price,
            $qty,
            $subtotal,
            $pharmacy_id,
            $branch_id
        );

        $item_stmt->execute();

        /* UPDATE STOCK WITH SAFETY CHECK */
        $stock_stmt->bind_param("iiii",
            $qty,
            $product_id,
            $branch_id,
            $qty
        );

        $stock_stmt->execute();

        if ($stock_stmt->affected_rows === 0) {
            throw new Exception("Insufficient stock for item: " . $name);
        }
    }

    /* COMMIT */
    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'layby_id' => $layby_id
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}