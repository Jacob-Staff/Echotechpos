<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Dynamic DB inclusion check
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    echo json_encode(["status" => "error", "message" => "Database connection missing."]);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $item_id   = intval($_POST['item_id']);
    $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 0);

    if ($item_id <= 0 || $branch_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid branch or item."]);
        exit();
    }

    // 2. Fetch active item details
    $stmt = $conn->prepare("SELECT id, item_name, price FROM store_items WHERE id = ? AND branch_id = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("ii", $item_id, $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $product = $result->fetch_assoc();

            // 3. Multidimensional session cart state
            if (!isset($_SESSION['carts'])) {
                $_SESSION['carts'] = [];
            }
            if (!isset($_SESSION['carts'][$branch_id])) {
                $_SESSION['carts'][$branch_id] = [];
            }

            // 4. Update cart contents
            if (isset($_SESSION['carts'][$branch_id][$item_id])) {
                $_SESSION['carts'][$branch_id][$item_id]['qty'] += 1;
            } else {
                $_SESSION['carts'][$branch_id][$item_id] = [
                    'id'     => $product['id'],
                    'name'   => $product['item_name'],
                    'price'  => floatval($product['price']),
                    'qty'    => 1,
                    'branch' => $branch_id
                ];
            }

            // 5. Calculate total item count for active branch
            $total_count = 0;
            foreach ($_SESSION['carts'][$branch_id] as $item) {
                $total_count += intval($item['qty']);
            }

            echo json_encode([
                "status" => "success",
                "count"  => $total_count,
                "message" => "Item added to cart."
            ]);
            $stmt->close();
            exit();
        } else {
            $stmt->close();
        }
    }
}

// Fallback return
$total_count = 0;
if (isset($branch_id) && isset($_SESSION['carts'][$branch_id])) {
    foreach ($_SESSION['carts'][$branch_id] as $item) {
        $total_count += intval($item['qty']);
    }
}

echo json_encode([
    "status" => "error",
    "count"  => $total_count,
    "message" => "Item could not be found or added."
]);
exit();
?>
