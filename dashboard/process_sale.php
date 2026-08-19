<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/conn.php';
header('Content-Type: application/json');

$in_transaction = false;

try {
    // 1. Validate Session & Get User Info
    $pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
    $branch_id   = $_SESSION['branch_id'] ?? null; 
    $user_id     = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;
    
    // Fallback chain for session usernames
    $issued_by   = $_SESSION['sessionUsername'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff';

    if (!$pharmacy_id || !$branch_id) {
        throw new Exception("Session expired. Please log in again.");
    }

    // 2. Get POST data
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $total_val = floatval($_POST['total_amount'] ?? 0);

    if (empty($cart)) {
        throw new Exception("Cart is empty.");
    }

    if ($total_val <= 0) {
        throw new Exception("Invalid sale total.");
    }
    
    // 3. Financial Calculations (VAT 16%)
    $subtotal_val = $total_val / 1.16;
    $vat_val = $total_val - $subtotal_val;

    // Generate unique invoice number
    $invoice_no = "PH-" . date('ymd') . "-" . strtoupper(substr(uniqid(), -4));

    // Begin MySQLi Transaction
    $conn->begin_transaction();
    $in_transaction = true;

    // 4. Insert Sale matching exact 'sales' table schema
    // Columns: id, pharmacy_id, branch_id, issued_by, invoice, total, payment, user_id, total_amount, subtotal, vat_amount, payment_method, sale_date, created_at
    $stmt = $conn->prepare("INSERT INTO sales (pharmacy_id, branch_id, issued_by, invoice, total, payment, user_id, total_amount, subtotal, vat_amount, payment_method, sale_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    // Bind Types: i (pharmacy_id), i (branch_id), s (issued_by), s (invoice), d (total), d (payment), i (user_id), d (total_amount), d (subtotal), d (vat_amount), s (payment_method)
    $stmt->bind_param("iissddiddds", 
        $pharmacy_id, 
        $branch_id, 
        $issued_by, 
        $invoice_no, 
        $total_val,      // total
        $total_val,      // payment
        $user_id,        // user_id
        $total_val,      // total_amount
        $subtotal_val,   // subtotal
        $vat_val,        // vat_amount
        $payment_method  // payment_method
    );
    
    $stmt->execute();
    $sale_id = $conn->insert_id;

    // 5. Process Items & Update Stock in 'store_items'
    $update_stock = $conn->prepare("UPDATE store_items SET quantity = quantity - ? WHERE id = ? AND branch_id = ? AND quantity >= ?");
    $record_item  = $conn->prepare("INSERT INTO sales_items (sale_id, pharmacy_id, branch_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($cart as $item) {
        $item_id    = intval($item['id']);
        $qty        = intval($item['qty']);
        $unit_price = floatval($item['price']);
        $item_name  = htmlspecialchars($item['name'] ?? 'Product');

        if ($qty <= 0) {
            continue;
        }

        // Update Stock
        $update_stock->bind_param("iiii", $qty, $item_id, $branch_id, $qty);
        $update_stock->execute();
        
        if ($update_stock->affected_rows === 0) {
            throw new Exception("Insufficient stock or unavailable item: " . $item_name);
        }

        // Record Line Item Sale
        $record_item->bind_param("iiiiid", $sale_id, $pharmacy_id, $branch_id, $item_id, $qty, $unit_price);
        $record_item->execute();
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'invoice' => $invoice_no
    ]);

} catch (Exception $e) {
    if (isset($conn) && $in_transaction) {
        $conn->rollback();
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
exit;
?>
