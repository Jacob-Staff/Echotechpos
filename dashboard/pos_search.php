<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_POST['query'])) {
    exit;
}

$today = date('Y-m-d');
$q = trim($_POST['query']);
$search_term = "%$q%";

$p_id = intval($_SESSION['pharmacy_id'] ?? 0);
$b_id = intval($_SESSION['branch_id'] ?? 0);

if ($p_id <= 0 || $b_id <= 0) {
    echo "<li class='p-3 text-center text-danger small'>Invalid Session</li>";
    exit;
}

$sql = "SELECT id, item_name, barcode, price, category, quantity, strength 
        FROM store_items 
        WHERE pharmacy_id = ? 
          AND branch_id = ? 
          AND (expiry_date > ? OR expiry_date IS NULL OR CAST(expiry_date AS CHAR) = '0000-00-00') 
          AND quantity > 0 
          AND (item_name LIKE ? OR barcode LIKE ?)
        ORDER BY item_name ASC
        LIMIT 10";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<li class='p-3 text-center text-danger small'>Query error</li>";
    exit;
}

$stmt->bind_param("iisss", $p_id, $b_id, $today, $search_term, $search_term);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $name = htmlspecialchars($row['item_name'], ENT_QUOTES);
        $barcode = htmlspecialchars($row['barcode'] ?? '', ENT_QUOTES);
        $category = htmlspecialchars($row['category'] ?? 'Medicine', ENT_QUOTES);
        $strength = htmlspecialchars($row['strength'] ?? '', ENT_QUOTES);
        $price = floatval($row['price']);
        $quantity = intval($row['quantity']);

        $display_name = $name;
        if (!empty($strength)) {
            $display_name .= " ({$strength})";
        }

        echo "
        <li class='product-item d-flex justify-content-between align-items-center p-3 border-bottom' 
            style='cursor:pointer; background-color: #1a1a1a; color: #fff;'
            data-id='{$id}' 
            data-name='{$display_name}' 
            data-price='{$price}' 
            data-stock='{$quantity}'>
            <div>
                <strong style='color: #00ffae;'>{$display_name}</strong><br>
                <small class='text-muted'>" . (!empty($barcode) ? "Barcode: {$barcode} | " : "") . "{$category}</small>
            </div>
            <div class='text-end'>
                <span class='text-success fw-bold'>K" . number_format($price, 2) . "</span><br>
                <small class='badge bg-info'>Stock: {$quantity}</small>
            </div>
        </li>";
    }
} else {
    echo "<li class='p-3 text-center text-muted small'>No available stock found.</li>";
}

$stmt->close();
?>