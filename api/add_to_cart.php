<?php
session_start();
require "../includes/conn.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['item_id'])) {
    $item_id = intval($_POST['item_id']);
    $branch_id = intval($_POST['branch_id']);

    // 1. Fetch item details from database to ensure it exists and get price/name
    $stmt = $conn->prepare("SELECT id, item_name, price FROM store_items WHERE id = ? AND branch_id = ?");
    $stmt->bind_param("ii", $item_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();

        // 2. Initialize branch-specific session storage
        if (!isset($_SESSION['carts'])) {
            $_SESSION['carts'] = [];
        }
        if (!isset($_SESSION['carts'][$branch_id])) {
            $_SESSION['carts'][$branch_id] = [];
        }

        // 3. Add or Update quantity in active branch's cart
        if (isset($_SESSION['carts'][$branch_id][$item_id])) {
            $_SESSION['carts'][$branch_id][$item_id]['qty'] += 1;
        } else {
            $_SESSION['carts'][$branch_id][$item_id] = [
                'name' => $product['item_name'],
                'price' => $product['price'],
                'qty' => 1,
                'branch' => $branch_id
            ];
        }

        // 4. Calculate total item count for current active branch
        $total_count = 0;
        foreach ($_SESSION['carts'][$branch_id] as $item) {
            $total_count += $item['qty'];
        }

        echo $total_count;
    } else {
        // Fallback count if item fetch fails
        $total_count = 0;
        if (isset($_SESSION['carts'][$branch_id])) {
            foreach ($_SESSION['carts'][$branch_id] as $item) {
                $total_count += $item['qty'];
            }
        }
        echo $total_count;
    }
}
?>
