<?php
session_start();
require_once "../includes/conn.php";

if (!isset($_POST['query'])) {
    exit;
}

$today = date('Y-m-d');
$q = trim($_POST['query']);
$search_term = "%$q%";

// Cast session variables to int
$p_id = intval($_SESSION['pharmacy_id'] ?? 0);
$b_id = intval($_SESSION['branch_id'] ?? 0);

if ($p_id <= 0 || $b_id <= 0) {
    echo "<div class='p-3 text-center text-danger'>Invalid session. Please log in again.</div>";
    exit;
}

// Prepare SQL
$sql = "SELECT id, item_name, barcode, price, category, quantity, strength 
        FROM store_items 
        WHERE pharmacy_id = ? 
          AND branch_id = ? 
          AND expiry_date > ? 
          AND quantity > 0 
          AND (item_name LIKE ? OR barcode LIKE ?)
        ORDER BY item_name ASC
        LIMIT 10";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<div class='p-3 text-center text-danger'>Database error: {$conn->error}</div>";
    exit;
}

$stmt->bind_param("iisss", $p_id, $b_id, $today, $search_term, $search_term);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $name = htmlspecialchars($row['item_name'], ENT_QUOTES);
        $barcode = htmlspecialchars($row['barcode'], ENT_QUOTES);
        $category = htmlspecialchars($row['category'] ?? 'Medicine', ENT_QUOTES);
        $strength = htmlspecialchars($row['strength'], ENT_QUOTES);
        $price = floatval($row['price']);
        $quantity = intval($row['quantity']);

        echo "
        <div class='product-item d-flex justify-content-between border-bottom p-2' 
             style='cursor:pointer; background-color: #1a1a1a; color: #fff;'
             data-id='{$id}' 
             data-name='{$name}' 
             data-price='{$price}' 
             data-stock='{$quantity}'
             data-strength='{$strength}'
             data-category='{$category}'>
            <span>
                <strong style='color: #00ffae;'>{$name} ({$strength})</strong><br>
                <small class='text-muted'>Barcode: {$barcode} | {$category}</small>
            </span>
            <div class='text-end'>
                <span class='text-success fw-bold'>K" . number_format($price, 2) . "</span><br>
                <small class='badge bg-info'>Stock: {$quantity}</small>
            </div>
        </div>";
    }
} else {
    echo "<div class='p-3 text-center text-muted small' style='background-color: #1a1a1a;'>No available stock found.</div>";
}

$stmt->close();
?>