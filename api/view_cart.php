<?php
session_start();

// Set clean JSON response headers
header('Content-Type: application/json');

// Include database configuration from the same directory
require_once __DIR__ . '/config.php';

// 1. Determine active branch ID (Session -> GET parameter -> Fallback default)
$branch_id = $_SESSION['branch_id'] ?? $_GET['branch_id'] ?? 13;

// 2. Check if cart session exists or is empty
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    echo json_encode([
        "status" => "success",
        "branch_id" => (int)$branch_id,
        "cart" => [],
        "total_amount" => 0,
        "message" => "Cart is empty."
    ]);
    exit;
}

$cart_items = [];
$grand_total = 0;

try {
    // 3. Prepare SQL query to fetch product details matching item_id and branch_id
    $stmt = $pdo->prepare("
        SELECT id, item_name, price, quantity AS stock_qty 
        FROM store_items 
        WHERE id = :item_id AND branch_id = :branch_id AND is_active = 1
    ");

    foreach ($_SESSION['cart'] as $cart_key => $cart_item) {
        $item_id = $cart_item['item_id'] ?? $cart_key;
        $qty     = $cart_item['quantity'] ?? 1;

        $stmt->execute([
            ':item_id'   => $item_id,
            ':branch_id' => $branch_id
        ]);
        
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $subtotal     = (float)$product['price'] * (int)$qty;
            $grand_total += $subtotal;

            $cart_items[] = [
                'item_id'   => (int)$product['id'],
                'item_name' => $product['item_name'],
                'price'     => (float)$product['price'],
                'quantity'  => (int)$qty,
                'stock_qty' => (int)$product['stock_qty'],
                'subtotal'  => (float)$subtotal
            ];
        }
    }

    echo json_encode([
        "status"       => "success",
        "branch_id"    => (int)$branch_id,
        "cart"         => $cart_items,
        "total_amount" => (float)$grand_total
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Database query failed: " . $e->getMessage()
    ]);
}
?>
