<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php';

if (!isset($_GET['id'])) {
    header("Location: ../lay_by_sell.php?error=missing_id");
    exit;
}

$layby_id = intval($_GET['id']);
$active_branch_id = $_SESSION['branch_id'] ?? 0;

// 1. FETCH ITEMS BEFORE DELETING (To restore stock)
$item_query = "SELECT product_id, quantity FROM layby_items WHERE layby_id = $layby_id";
$item_res = mysqli_query($conn, $item_query);

if ($item_res && mysqli_num_rows($item_res) > 0) {
    while ($item = mysqli_fetch_assoc($item_res)) {
        $p_id = $item['product_id'];
        $qty  = $item['quantity'];
        
        // Restore the quantity to the store/products table
        mysqli_query($conn, "UPDATE store SET Qty = Qty + $qty WHERE id = $p_id");
    }
}

// 2. DELETE THE LAY-BY (Ensuring it belongs to the active branch)
$delete_sql = "DELETE FROM laybys WHERE id = $layby_id AND branch_id = $active_branch_id";
$delete = mysqli_query($conn, $delete_sql);

if ($delete && mysqli_affected_rows($conn) > 0) {
    header("Location: ../lay_by_sell.php?msg=deleted_and_restored");
    exit;
} else {
    header("Location: ../lay_by_sell.php?error=delete_failed");
    exit;
}
?>