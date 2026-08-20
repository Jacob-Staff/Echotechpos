<?php
session_start();
require_once "../../includes/conn.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$query       = trim($_POST['query'] ?? '');

if (empty($query)) exit;

$search = "%" . $query . "%";
$stmt = $conn->prepare("
    SELECT id, item_name, price, quantity 
    FROM store_items 
    WHERE pharmacy_id = ? AND branch_id = ? AND (item_name LIKE ? OR barcode LIKE ?) AND quantity > 0
    LIMIT 10
");
$stmt->bind_param("iiss", $pharmacy_id, $branch_id, $search, $search);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        echo "<li class='product-item' 
                  data-id='".e($row['id'])."' 
                  data-name='".e($row['item_name'])."' 
                  data-price='".e($row['price'])."'>
                <div>
                    <strong>".e($row['item_name'])."</strong>
                    <small class='d-block text-muted'>In Stock: ".$row['quantity']."</small>
                </div>
                <span class='badge bg-primary rounded-pill'>K".number_format($row['price'], 2)."</span>
              </li>";
    }
} else {
    echo "<li class='p-3 text-muted text-center'>No items found</li>";
}

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
