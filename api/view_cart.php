<?php
header('Content-Type: application/json');
session_start();

require_once '../config/db.php'; // Adjust path if your DB connection file is elsewhere

// 1. Check if session cart exists
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    echo json_encode([
        "status" => "success",
        "cart" => [],
        "total_amount" => 0,
        "message" => "Cart is empty."
    ]);
    exit;
}

// 2. Get active branch ID from session or request
$branch_id = $_SESSION['branch_id'] ?? $_GET['branch_id'] ?? null;

if (!$branch_id) {
    echo json_encode([
        "status" => "error",
        "message" => "Active branch session not found."
    ]);
    exit;
}

$cart_items = [];
$grand_total = 0;

try {
    // Prepare query to fetch item details matching both item_id and branch_id
    $stmt = $pdo->prepare("
        SELECT id, item_name, price, quantity AS stock_qty 
        FROM store_items 
        WHERE id = :item_id AND branch_id = :branch_id AND is_active = 1
    ");

    foreach ($_SESSION['cart'] as $cart_key => $cart_item) {
        // Extract item ID from array key or object
        $item_id = $cart_item['item_id'] ?? $cart_key;
        $qty = $cart_item['quantity'] ?? 1;

        $stmt->execute([
            ':item_id' => $item_id,
            ':branch_id' => $branch_id
        ]);
        
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $subtotal = $product['price'] * $qty;
            $grand_total += $subtotal;

            $cart_items[] = [
                'item_id' => $product['id'],
                'item_name' => $product['item_name'],
                'price' => (float)$product['price'],
                'quantity' => (int)$qty,
                'stock_qty' => (int)$product['stock_qty'],
                'subtotal' => (float)$subtotal
            ];
        } else {
            // Optional: Remove stale items from cart session if they don't exist in this branch
            unset($_SESSION['cart'][$cart_key]);
        }
    }

    echo json_encode([
        "status" => "success",
        "branch_id" => $branch_id,
        "cart" => $cart_items,
        "total_amount" => $grand_total
    ]);

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
