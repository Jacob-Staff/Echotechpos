<?php
require "config.php"; // Contains headers and DB connection

$branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 10;
$category  = isset($_GET['category']) ? $_GET['category'] : null;
$search    = isset($_GET['search']) ? $_GET['search'] : null;

// Base Query - Grouped to prevent duplicates across batches
$sql = "SELECT id, item_name, strength, price, category, product_image, 
        requires_prescription, SUM(quantity) as total_qty 
        FROM store_items 
        WHERE is_online = 1 
        AND branch_id = '$branch_id' 
        AND quantity > 0";

// Filter by Category if clicked
if ($category && $category !== 'All') {
    $safe_cat = $conn->real_escape_string($category);
    $sql .= " AND category = '$safe_cat'";
}

// Filter by Search keywords
if ($search) {
    $safe_search = $conn->real_escape_string($search);
    $sql .= " AND (item_name LIKE '%$safe_search%' OR category LIKE '%$safe_search%')";
}

$sql .= " GROUP BY item_name ORDER BY item_name ASC";

$result = $conn->query($sql);
$products = [];

while($row = $result->fetch_assoc()) {
    // Add full URL for Mobile App image loading
    $row['image_url'] = "http://localhost/pharmacy_v1-master/uploads/products/" . ($row['product_image'] ?: 'default_med.png');
    $products[] = $row;
}

echo json_encode([
    "status" => "success",
    "timestamp" => date('Y-m-d H:i:s'),
    "count" => count($products),
    "data" => $products
]);
?>