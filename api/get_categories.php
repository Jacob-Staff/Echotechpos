<?php
require "config.php";

// Pull the branch ID from the app request
$branch_id = isset($_GET['branch_id']) ? $_GET['branch_id'] : 10;

// SQL: Get unique categories that have active online products
$sql = "SELECT DISTINCT category 
        FROM store_items 
        WHERE is_online = 1 
        AND branch_id = '$branch_id' 
        AND quantity > 0 
        AND category != ''
        ORDER BY category ASC";

$result = $conn->query($sql);
$categories = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    echo json_encode([
        "status" => "success",
        "branch" => $branch_id,
        "count" => count($categories),
        "data" => $categories
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No active categories found for this branch."
    ]);
}
?>