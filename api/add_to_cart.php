<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set clean JSON header for AJAX calls
header('Content-Type: application/json');

// 1. Dynamic DB connection file lookup
if (file_exists(__DIR__ . "/../../includes/conn.php")) {
    require_once __DIR__ . "/../../includes/conn.php";
} elseif (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    echo json_encode(["status" => "error", "message" => "Database connection missing."]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Standardize product ID and branch ID from POST payload
    $item_id   = isset($_POST['product_id']) ? intval($_POST['product_id']) : (isset($_POST['item_id']) ? intval($_POST['item_id']) : 0);
    $branch_id = isset($_POST['bid']) ? intval($_POST['bid']) : (isset($_POST['branch_id']) ? intval($_POST['branch_id']) : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 10));
    $qty       = isset($_POST['qty']) ? max(1, intval($_POST['qty'])) : 1;

    if ($item_id <= 0 || $branch_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid product or branch context."]);
        exit();
    }

    // 2. Query product details safely from store_items
    $stmt = $conn->prepare("SELECT id, item_name, price, online_price FROM store_items WHERE id = ? AND branch_id = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("ii", $item_id, $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $product = $result->fetch_assoc();

            // Use online_price if active and lower than standard price
            $effective_price = ($product['online_price'] > 0 && $product['online_price'] < $product['price'])
                ? floatval($product['online_price'])
                : floatval($product['price']);

            // 3. Multidimensional session cart state per branch
            if (!isset($_SESSION['carts'])) {
                $_SESSION['carts'] = [];
            }
            if (!isset($_SESSION['carts'][$branch_id])) {
                $_SESSION['carts'][$branch_id] = [];
            }

            // 4. Update cart contents
            if (isset($_SESSION['carts'][$branch_id][$item_id])) {
                $_SESSION['carts'][$branch_id][$item_id]['qty'] += $qty;
            } else {
                $_SESSION['carts'][$branch_id][$item_id] = [
                    'id'     => $product['id'],
                    'name'   => $product['item_name'],
                    'price'  => $effective_price,
                    'qty'    => $qty,
                    'branch' => $branch_id
                ];
            }

            // 5. Calculate total item count for active branch
            $total_count = 0;
            foreach ($_SESSION['carts'][$branch_id] as $item) {
                $total_count += intval($item['qty'] ?? 1);
            }

            echo json_encode([
                "status"     => "success",
                "success"    => true,
                "cart_count" => $total_count,
                "count"      => $total_count,
                "message"    => "Item added to cart."
            ]);
            $stmt->close();
            exit();
        } else {
            $stmt->close();
        }
    }
}

// Fallback response for missing or invalid items
$total_count = 0;
$branch_id = $_SESSION['current_branch_id'] ?? 10;
if (isset($_SESSION['carts'][$branch_id])) {
    foreach ($_SESSION['carts'][$branch_id] as $item) {
        $total_count += intval($item['qty'] ?? 1);
    }
}

echo json_encode([
    "status"     => "error",
    "success"    => false,
    "cart_count" => $total_count,
    "count"      => $total_count,
    "message"    => "Item could not be found or added to cart."
]);
exit();
?>
