<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// Get request payload
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$item_id   = $data['item_id'] ?? $_GET['item_id'] ?? null;
$quantity  = (int)($data['quantity'] ?? $_GET['quantity'] ?? 1);
$branch_id = $data['branch_id'] ?? $_GET['branch_id'] ?? 13;

if (!$item_id) {
    echo json_encode(["status" => "error", "message" => "Item ID is required."]);
    exit;
}

// Store branch ID in session
$_SESSION['branch_id'] = $branch_id;

// Initialize cart array if missing
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Add or update quantity in session
if (isset($_SESSION['cart'][$item_id])) {
    $_SESSION['cart'][$item_id]['quantity'] += $quantity;
} else {
    $_SESSION['cart'][$item_id] = [
        'item_id' => $item_id,
        'quantity' => $quantity
    ];
}

echo json_encode([
    "status" => "success",
    "message" => "Item added to cart.",
    "cart_count" => count($_SESSION['cart'])
]);
?>
