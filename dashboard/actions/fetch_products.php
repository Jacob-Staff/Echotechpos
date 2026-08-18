<?php
session_start();
require_once "../../includes/conn.php"; 

$q = $_POST['query'] ?? '';
$pharmacy_id = intval($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = intval($_SESSION['branch_id'] ?? 0);

if (!empty($q)) {
    // Matches your store_items columns: id, item_name, price, quantity
    $sql = "SELECT id, item_name, price, quantity FROM store_items 
            WHERE pharmacy_id = ? AND branch_id = ? AND item_name LIKE ? 
            AND quantity > 0 LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $searchTerm = "%$q%";
    $stmt->bind_param("iis", $pharmacy_id, $branch_id, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo '<li class="product-item" 
                      data-id="'.$row['id'].'" 
                      data-name="'.htmlspecialchars($row['item_name']).'" 
                      data-price="'.$row['price'].'">';
            echo htmlspecialchars($row['item_name']) . " (Stock: {$row['quantity']}) - K" . number_format($row['price'], 2);
            echo '</li>';
        }
    } else {
        echo '<li class="p-2 text-white small">No products found</li>';
    }
}
?>