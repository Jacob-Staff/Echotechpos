<?php
session_start();
// Go up one level to find includes since this is in the /api folder
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

        // 2. Initialize cart session if it doesn't exist
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        // 3. Add or Update quantity
        if (isset($_SESSION['cart'][$item_id])) {
            $_SESSION['cart'][$item_id]['qty'] += 1;
        } else {
            $_SESSION['cart'][$item_id] = [
                'name' => $product['item_name'],
                'price' => $product['price'],
                'qty' => 1,
                'branch' => $branch_id
            ];
        }

        // 4. Calculate total count to send back to the JavaScript badge
        $total_count = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_count += $item['qty'];
        }

        echo $total_count;
    } else {
        // Item not found or doesn't belong to this branch
        echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
    }
}
?>