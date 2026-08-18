<?php
session_start();
require_once 'conn.php'; 
require_once 'auth.php'; 

// ✅ Ensure the user is logged in and branch_id is available
if (!isset($_SESSION['branch_id']) || empty($_SESSION['branch_id'])) {
    die("Error: Branch not identified. Please log in again.");
}

$branch_id = intval($_SESSION['branch_id']);

// ✅ Check for POST request and valid product ID
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['id'])) {
    die("Invalid request or missing product ID.");
}

$product_id = intval($_POST['id']);
if ($product_id <= 0) {
    die("Invalid Product ID.");
}

// ✅ Step 1: Verify that the product belongs to this branch
$check_sql = "SELECT id FROM store_items WHERE id = ? AND branch_id = ?";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    die("Error preparing validation statement: " . htmlspecialchars($conn->error));
}
$check_stmt->bind_param("ii", $product_id, $branch_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    // ❌ Prevent deletion if the item does not belong to this branch
    die("Error: You are not authorized to delete this product.");
}
$check_stmt->close();

// ✅ Step 2: Proceed to delete the product safely
$delete_sql = "DELETE FROM store_items WHERE id = ? AND branch_id = ?";
$delete_stmt = $conn->prepare($delete_sql);
if (!$delete_stmt) {
    die("Error preparing delete statement: " . htmlspecialchars($conn->error));
}

$delete_stmt->bind_param("ii", $product_id, $branch_id);

if ($delete_stmt->execute()) {
    echo "success";
} else {
    die("Database deletion failed: " . htmlspecialchars($delete_stmt->error));
}

$delete_stmt->close();
$conn->close();
?>