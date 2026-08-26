<?php
/*
 * BIGE50 ONLINE STORE - ADD TO CART HANDLER
 *
 * This handler is intentionally compatible with the existing
 * online_store.php JavaScript, which POSTs:
 *   item_id
 *   branch_id
 *
 * IMPORTANT:
 * The cart is stored in:
 *   $_SESSION['carts'][$branch_id]
 *
 * This is the same structure used by the rebuilt cart page.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

/* This file normally lives inside /api/ */
$connFile = __DIR__ . '/../includes/conn.php';
if (!file_exists($connFile)) {
    $connFile = __DIR__ . '/includes/conn.php';
}

if (!file_exists($connFile)) {
    http_response_code(500);
    echo '0';
    exit;
}

require_once $connFile;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '0';
    exit;
}

$item_id = (int)($_POST['item_id'] ?? $_POST['product_id'] ?? 0);
$branch_id = (int)($_POST['branch_id'] ?? $_POST['bid'] ?? $_SESSION['current_branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));

if ($item_id <= 0 || $branch_id <= 0) {
    echo '0';
    exit;
}

/*
 * Verify the product belongs to the selected branch and is actually
 * available online. We also read the current stock so customers cannot
 * put more units into the cart than are available.
 */
$sql = "
    SELECT
        id,
        item_name,
        strength,
        category,
        price,
        online_price,
        quantity,
        image
    FROM store_items
    WHERE id = ?
      AND branch_id = ?
      AND is_active = 1
      AND is_online = 1
      AND quantity > 0
      AND (
          expiry_date IS NULL OR
          expiry_date = '' OR
          expiry_date = '0000-00-00' OR
          expiry_date >= CURDATE()
      )
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo '0';
    exit;
}

$stmt->bind_param('ii', $item_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    /* Keep the response numeric because online_store.php expects a number. */
    $existing = 0;
    if (isset($_SESSION['carts'][$branch_id]) && is_array($_SESSION['carts'][$branch_id])) {
        foreach ($_SESSION['carts'][$branch_id] as $cartItem) {
            $existing += max(0, (int)($cartItem['qty'] ?? 0));
        }
    }
    echo (string)$existing;
    exit;
}

$stock = max(0, (int)$product['quantity']);

/* Online price takes priority only when it is a positive lower price. */
$standardPrice = (float)($product['price'] ?? 0);
$onlinePrice = (float)($product['online_price'] ?? 0);
$effectivePrice = ($onlinePrice > 0 && $onlinePrice < $standardPrice)
    ? $onlinePrice
    : $standardPrice;

if ($effectivePrice < 0) {
    $effectivePrice = 0;
}

/* Use the same per-branch cart structure as the cart page. */
if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
    $_SESSION['carts'] = [];
}

if (!isset($_SESSION['carts'][$branch_id]) || !is_array($_SESSION['carts'][$branch_id])) {
    $_SESSION['carts'][$branch_id] = [];
}

$cart =& $_SESSION['carts'][$branch_id];

$currentQty = isset($cart[$item_id])
    ? max(0, (int)($cart[$item_id]['qty'] ?? 0))
    : 0;

$newQty = $currentQty + $qty;

/* Never allow the cart quantity to exceed current stock. */
if ($newQty > $stock) {
    $newQty = $stock;
}

if ($newQty <= 0) {
    echo '0';
    exit;
}

$cart[$item_id] = [
    'id'       => (int)$product['id'],
    'name'     => (string)$product['item_name'],
    'strength' => (string)($product['strength'] ?? ''),
    'category' => (string)($product['category'] ?? ''),
    'price'    => round($effectivePrice, 2),
    'qty'      => $newQty,
    'branch'   => $branch_id,
    'image'    => (string)($product['image'] ?? ''),
    'stock'    => $stock
];

$_SESSION['current_branch_id'] = $branch_id;

/* Calculate total quantity for this branch. */
$total_count = 0;
foreach ($cart as $cartItem) {
    $total_count += max(0, (int)($cartItem['qty'] ?? 0));
}

/*
 * The current online_store.php checks !isNaN(response), so return ONLY
 * the numeric cart count. This is deliberately NOT JSON.
 */
echo (string)$total_count;
exit;
