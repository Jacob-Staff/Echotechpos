<?php
session_start();
require "conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;
$query       = $_POST['query'] ?? '';

if (!$pharmacy_id || !$branch_id || empty($query)) {
    exit; // Silently exit if no session or query
}

$search = "%{$query}%";
$sql = "SELECT id, item_name, price, product_group, quantity 
        FROM store_items 
        WHERE pharmacy_id = ? AND branch_id = ? 
        AND (item_name LIKE ? OR barcode LIKE ?) 
        AND is_active = 1 LIMIT 8";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiss", $pharmacy_id, $branch_id, $search, $search);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Output clean, clickable results
        echo '
        <div class="search-result-item p-2 border-bottom border-secondary" 
             style="cursor:pointer;" 
             onclick="addToSale(\''.$row['item_name'].'\', '.$row['price'].', '.$row['id'].', '.$row['quantity'].')">
            <div class="d-flex justify-content-between">
                <span class="text-white fw-bold">'.$row['item_name'].'</span>
                <span style="color:#00ffae;">K'.number_format($row['price'], 2).'</span>
            </div>
            <small class="text-muted">'.$row['product_group'].' | Stock: '.$row['quantity'].'</small>
        </div>';
    }
} else {
    echo '<div class="p-2 text-muted">No products found.</div>';
}
?>