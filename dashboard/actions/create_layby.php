<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Relative paths to step out of dashboard/actions back to root
require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

$customer_name  = trim($_POST['customer_name'] ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$deposit        = (float)($_POST['deposit'] ?? 0);
$due_date       = trim($_POST['due_date'] ?? '');
$total_amount   = (float)($_POST['total_amount'] ?? 0);
$cart_raw       = $_POST['cart'] ?? '[]';

$cart = json_decode($cart_raw, true);

if (empty($customer_name) || empty($customer_phone) || empty($due_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Please fill in all required customer details.']);
    exit;
}

if (empty($cart) || !is_array($cart)) {
    echo json_encode(['status' => 'error', 'message' => 'Your cart is empty.']);
    exit;
}

$balance_due = $total_amount - $deposit;
if ($balance_due < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Deposit cannot exceed the total amount.']);
    exit;
}

$status = ($balance_due <= 0) ? 'Completed' : 'Pending';

mysqli_begin_transaction($conn);

try {
    // 1. Insert main Lay-by record
    $stmt = mysqli_prepare($conn, "INSERT INTO laybys (pharmacy_id, branch_id, user_id, customer_name, customer_phone, total_amount, deposit, balance_due, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    mysqli_stmt_bind_param($stmt, "iiissdddss", $pharmacy_id, $branch_id, $user_id, $customer_name, $customer_phone, $total_amount, $deposit, $balance_due, $due_date, $status);
    mysqli_stmt_execute($stmt);
    
    $layby_id = mysqli_insert_id($conn);

    // 2. Insert items into layby_items table (Matching exact schema: layby_id, product_name, price, qty, total, pharmacy_id, branch_id)
    $item_stmt = mysqli_prepare($conn, "INSERT INTO layby_items (layby_id, product_name, price, qty, total, pharmacy_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($cart as $item) {
        $p_name  = $item['name'];
        $p_price = (float)$item['price'];
        $p_qty   = (int)$item['qty'];
        $p_total = $p_price * $p_qty;

        mysqli_stmt_bind_param($item_stmt, "isdidii", $layby_id, $p_name, $p_price, $p_qty, $p_total, $pharmacy_id, $branch_id);
        mysqli_stmt_execute($item_stmt);
    }

    // 3. Record initial deposit in layby_payments if deposit > 0
    if ($deposit > 0) {
        $pay_stmt = mysqli_prepare($conn, "INSERT INTO layby_payments (pharmacy_id, layby_id, branch_id, user_id, payment_amount, payment_date, method) VALUES (?, ?, ?, ?, ?, NOW(), 'Cash')");
        mysqli_stmt_bind_param($pay_stmt, "iiiid", $pharmacy_id, $layby_id, $branch_id, $user_id, $deposit);
        mysqli_stmt_execute($pay_stmt);
    }

    mysqli_commit($conn);
    echo json_encode(['status' => 'success', 'message' => 'Agreement Created Successfully!']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
