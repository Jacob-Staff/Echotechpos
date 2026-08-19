<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";

$pharmacy_id = intval($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = intval($_SESSION['branch_id'] ?? 0);
$query       = trim($_POST['query'] ?? '');

if ($pharmacy_id <= 0 || $branch_id <= 0 || empty($query)) {
    exit;
}

$today = date('Y-m-d');
$search_term = "%{$query}%";

$sql = "SELECT id, item_name, barcode, price, category, quantity, strength 
        FROM store_items 
        WHERE pharmacy_id = ? 
          AND branch_id = ? 
          AND (expiry_date > ? OR expiry_date IS NULL OR CAST(expiry_date AS CHAR) = '0000-00-00') 
          AND quantity > 0 
          AND (item_name LIKE ? OR barcode LIKE ? OR category LIKE ?)
        ORDER BY item_name ASC
        LIMIT 10";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo '<li class="p-3 text-danger small">Database query error.</li>';
    exit;
}

$stmt->bind_param("iissss", $pharmacy_id, $branch_id, $today, $search_term, $search_term, $search_term);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $id       = intval($row['id']);
        $name     = htmlspecialchars($row['item_name'], ENT_QUOTES);
        $barcode  = htmlspecialchars($row['barcode'] ?? '', ENT_QUOTES);
        $category = htmlspecialchars($row['category'] ?? 'Medicine', ENT_QUOTES);
        $strength = htmlspecialchars($row['strength'] ?? '', ENT_QUOTES);
        $price    = floatval($row['price']);
        $quantity = intval($row['quantity']);

        $display_name = $name;
        if (!empty($strength)) {
            $display_name .= " ({$strength})";
        }

        echo '
        <li class="product-item d-flex justify-content-between align-items-center p-3 border-bottom" 
            style="cursor:pointer; background-color: #1a1a1a; border-color: #333 !important;" 
            data-id="' . $id . '" 
            data-name="' . $display_name . '" 
            data-price="' . $price . '" 
            data-stock="' . $quantity . '">
            <div>
                <strong style="color: #00ffae; font-size: 1rem; display: block;">' . $display_name . '</strong>
                <span style="color: #d1d1d1; font-size: 0.85rem; display: inline-block; mt-1">' 
                    . (!empty($barcode) ? 'Barcode: <b style="color:#ffffff;">' . $barcode . '</b> | ' : '') . $category . 
                '</span>
            </div>
            <div class="text-end ps-2">
                <span class="text-success fw-bold d-block" style="font-size: 1.05rem;">K' . number_format($price, 2) . '</span>
                <span class="badge bg-info text-dark fw-bold" style="font-size: 0.75rem;">Stock: ' . $quantity . '</span>
            </div>
        </li>';
    }
} else {
    echo '<li class="p-3 text-center small" style="color: #aaaaaa; background-color: #1a1a1a;">No matching items in stock.</li>';
}

$stmt->close();
?>
