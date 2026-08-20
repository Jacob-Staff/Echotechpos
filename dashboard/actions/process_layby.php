<?php
// Turn on error logging, return strictly JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../includes/conn.php";

// Check session
if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

// Validate incoming POST data
$customer_name  = trim($_POST['customer_name'] ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$deposit        = (float)($_POST['deposit'] ?? 0);
$total_amount   = (float)($_POST['total'] ?? 0);
$due_date       = trim($_POST['due_date'] ?? '');
$cart_raw       = $_POST['cart'] ?? '[]';

if (empty($customer_name) || empty($customer_phone) || empty($due_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Please fill in all required customer fields.']);
    exit;
}

$cart = json_decode($cart_raw, true);
if (empty($cart) || !is_array($cart)) {
    echo json_encode(['status' => 'error', 'message' => 'Cart is empty or invalid.']);
    exit;
}

// Calculate total balance due
$balance_due = $total_amount - $deposit;
if ($balance_due < 0) {
    $balance_due = 0.00;
}

// Status handling: Use 'Pending' or 'Active' based on deposit status
// 'Pending' matches existing database values in your laybys table
$status = ($balance_due <= 0) ? 'Completed' : 'Pending'; 

// Begin Database Transaction
mysqli_begin_transaction($conn);

try {
    // 1. Insert into `laybys` table
    $stmt = mysqli_prepare($conn, "INSERT INTO laybys (pharmacy_id, branch_id, user_id, customer_name, customer_phone, total_amount, deposit, balance_due, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "iiissdddss", $pharmacy_id, $branch_id, $user_id, $customer_name, $customer_phone, $total_amount, $deposit, $balance_due, $due_date, $status);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Execution failed: " . mysqli_stmt_error($stmt));
    }

    $layby_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // 2. Insert items into `layby_items` table
    $item_stmt = mysqli_prepare($conn, "INSERT INTO layby_items (layby_id, product_name, price, qty, total, pharmacy_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if (!$item_stmt) {
        throw new Exception("Prepare items statement failed: " . mysqli_error($conn));
    }

    foreach ($cart as $item) {
        $p_name  = $item['name'] ?? 'Unknown Item';
        $p_price = (float)($item['price'] ?? 0);
        $p_qty   = (int)($item['qty'] ?? 1);
        $p_total = $p_price * $p_qty;

        mysqli_stmt_bind_param($item_stmt, "isdidii", $layby_id, $p_name, $p_price, $p_qty, $p_total, $pharmacy_id, $branch_id);
        
        if (!mysqli_stmt_execute($item_stmt)) {
            throw new Exception("Item insert failed: " . mysqli_stmt_error($item_stmt));
        }
    }
    mysqli_stmt_close($item_stmt);

    // 3. Record initial deposit in `layby_payments` if deposit > 0
    if ($deposit > 0) {
        $pay_stmt = mysqli_prepare($conn, "INSERT INTO layby_payments (pharmacy_id, branch_id, layby_id, user_id, payment_amount, payment_date, method, notes) VALUES (?, ?, ?, ?, ?, NOW(), 'Cash', 'Initial Deposit')");
        
        if (!$pay_stmt) {
            throw new Exception("Prepare payment statement failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($pay_stmt, "iiiid", $pharmacy_id, $branch_id, $layby_id, $user_id, $deposit);
        
        if (!mysqli_stmt_execute($pay_stmt)) {
            throw new Exception("Payment insert failed: " . mysqli_stmt_error($pay_stmt));
        }
        mysqli_stmt_close($pay_stmt);
    }

    // Commit all operations
    mysqli_commit($conn);

    echo json_encode([
        'status' => 'success',
        'message' => 'Lay-by agreement created successfully!',
        'layby_id' => $layby_id
    ]);

} catch (Exception $e) {
    // Rollback changes on failure
    mysqli_rollback($conn);

    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
