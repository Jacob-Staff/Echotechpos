<?php
// includes/process_refund_inc.php
session_start();
require_once 'conn.php'; 
// require_once 'auth.php'; // Include authentication if needed

header('Content-Type: application/json; charset=utf-8');

// Assume POST data contains: original_product_id, refund_quantity, original_sale_id
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$item_id = intval($data['product_id'] ?? 0);
$refund_quantity = floatval($data['quantity'] ?? 0);
$sale_id = intval($data['sale_id'] ?? 0); // Optional, for logging the refund transaction

if ($item_id <= 0 || $refund_quantity <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID or quantity for refund.']);
    exit;
}

// --- START TRANSACTION ---
$conn->begin_transaction();

try {
    // 1. Replenish the stock
    // **** FIX 1: Changed table name from 'store_items' to 'inventory' ****
    $sql = "UPDATE inventory SET quantity = quantity + ? WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Error preparing replenishment statement: " . $conn->error);
    }
    
    // **** FIX 2: Changed binding from 'di' (double, integer) to 'si' (string, integer) ****
    // Since inventory.quantity is VARCHAR, we bind the numeric value as a string.
    $refund_quantity_str = (string)$refund_quantity;
  // d (quantity), i (id)
$stmt->bind_param("di", $refund_quantity, $item_id);
    if (!$stmt->execute()) {
        throw new Exception("Database stock replenishment failed: " . $stmt->error);
    }
    
    // Check if the product was actually updated (ID exists)
    if ($stmt->affected_rows === 0) {
        throw new Exception("No product found with ID $item_id to update.");
    }
    $stmt->close();

    // 2. LOG THE REFUND (Implement proper logging here if needed)
    /*
    // Example: Insert a record into a 'refunds' table (structure assumed)
    $log_stmt = $conn->prepare("INSERT INTO refunds (sale_id, product_id, qty, refund_date) VALUES (?, ?, ?, NOW())");
    $log_stmt->bind_param('iid', $sale_id, $item_id, $refund_quantity);
    if (!$log_stmt->execute()) {
        throw new Exception("Failed to log refund.");
    }
    $log_stmt->close();
    */
    
    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => "Stock replenished by $refund_quantity for item ID $item_id."]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => "Refund failed: " . $e->getMessage()]);
}

$conn->close();
?>