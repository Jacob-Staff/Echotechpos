<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Dynamic DB inclusion
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    echo json_encode(["status" => "error", "message" => "DB connection missing"]);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $item_id   = intval($_POST['item_id']);
    
    // Resolve branch ID from POST, or SESSION, or default fallback to first active branch
    $branch_id = isset($_POST['branch_id']) && intval($_POST['branch_id']) > 0 
        ? intval($_POST['branch_id']) 
        : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 0);

    if ($item_id <= 0 || $branch_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid Branch or Item ID."]);
        exit();
    }

    // Set active branch session
    $_SESSION['current_branch_id'] = $branch_id;

    // Fetch product details
    $stmt = $conn->prepare("SELECT id, item_name, price FROM store_items WHERE id = ? AND branch_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $item_id, $branch_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $product = $res->fetch_assoc();

            // Initialize cart session storage
            if (!isset($_SESSION['carts'])) {
                $_SESSION['carts'] = [];
            }
            if (!isset($_SESSION['carts'][$branch_id])) {
                $_SESSION['carts'][$branch_id] = [];
            }

            // Add or increment item
            if (isset($_SESSION['carts'][$branch_id][$item_id])) {
                $_SESSION['carts'][$branch_id][$item_id]['qty'] += 1;
            } else {
                $_SESSION['carts'][$branch_id][$item_id] = [
                    'id'    => $product['id'],
                    'name'  => $product['item_name'],
                    'price' => floatval($product['price']),
                    'qty'   => 1
                ];
            }

            // Calculate total count for active branch
            $total_count = 0;
            foreach ($_SESSION['carts'][$branch_id] as $item) {
                $total_count += intval($item['qty']);
            }

            echo json_encode([
                "status" => "success",
                "count"  => $total_count,
                "message" => "Item added successfully"
            ]);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }
}

echo json_encode(["status" => "error", "message" => "Item not found in this branch store."]);
exit();
?>
