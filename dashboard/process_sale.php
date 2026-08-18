<?php
session_start();
require_once '../includes/conn.php';
header('Content-Type: application/json');

try {
    // 1. Validate Session & Get User Info
    $pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
    $branch_id   = $_SESSION['branch_id'] ?? null; 
    $user_id     = $_SESSION['user_id'] ?? null;
    
    // Using 'full_name' or 'username' from your session for 'issued_by'
    $issued_by   = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Staff');

    if (!$pharmacy_id || !$branch_id || !$user_id) {
        throw new Exception("Session missing. Please log in again.");
    }

    // 2. Get POST data
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $total_val = floatval($_POST['total_amount'] ?? 0);

    if (empty($cart)) throw new Exception("Cart is empty.");
    
    // 3. Financial Calculations (VAT 16%)
    $subtotal_val = $total_val / 1.16;
    $vat_val = $total_val - $subtotal_val;

    // Generate unique invoice number
    $invoice_no = "PH-" . date('ymd') . "-" . strtoupper(substr(uniqid(), -4));

    $conn->begin_transaction();

    // 4. Insert Sale into 'sales' table
    // Note: I included both 'total' and 'total_amount' since your table has both.
    $stmt = $conn->prepare("INSERT INTO sales (pharmacy_id, branch_id, issued_by, invoice, total, total_amount, subtotal, vat_amount, payment_method, user_id, sale_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    
    if (!$stmt) throw new Exception($conn->error);
    
    // Types: i (int), i (int), s (string), s (string), d (double) x 4, s (string), i (int)
    $stmt->bind_param("iissddddsi", 
        $pharmacy_id, 
        $branch_id, 
        $issued_by, 
        $invoice_no, 
        $total_val,    // total
        $total_val,    // total_amount
        $subtotal_val, // subtotal
        $vat_val,      // vat_amount
        $payment_method, 
        $user_id
    );
    
    $stmt->execute();
    $sale_id = $conn->insert_id;

    // 5. Process Items & Update Stock
    // Using 'store_items' table as per your preview
    $update_stock = $conn->prepare("UPDATE store_items SET quantity = quantity - ? WHERE id = ? AND branch_id = ? AND quantity >= ?");
    $record_item = $conn->prepare("INSERT INTO sales_items (sale_id, pharmacy_id, branch_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($cart as $item) {
        $item_id = intval($item['id']);
        $qty = intval($item['qty']);
        $unit_price = floatval($item['price']);

        // Update Stock
        $update_stock->bind_param("iiii", $qty, $item_id, $branch_id, $qty);
        $update_stock->execute();
        
        if ($update_stock->affected_rows === 0) {
            throw new Exception("Insufficient stock for item: " . $item['name']);
        }

        // Record Individual Item Sale
        $record_item->bind_param("iiiiid", $sale_id, $pharmacy_id, $branch_id, $item_id, $qty, $unit_price);
        $record_item->execute();
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'invoice' => $invoice_no
    ]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
exit;