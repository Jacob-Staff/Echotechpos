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

// Prepared SQL targeting active stock matching pharmacy and branch session filters
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
    echo '<div class="p-2 text-danger small">Database query error.</div>';
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
        <div class="product-item search-result-item p-2 border-bottom border-secondary d-flex justify-content-between align-items-center" 
             style="cursor:pointer; background-color: #1a1a1a;" 
             data-id="' . $id . '" 
             data-name="' . $display_name . '" 
             data-price="' . $price . '" 
             data-stock="' . $quantity . '"
             onclick="if(typeof addToSale === \'function\'){ addToSale(\'' . addslashes($display_name) . '\', ' . $price . ', ' . $id . ', ' . $quantity . '); }">
            <div>
                <span class="fw-bold d-block" style="color:#00ffae;">' . $display_name . '</span>
                <small class="text-muted">' . (!empty($barcode) ? 'Barcode: ' . $barcode . ' | ' : '') . $category . '</small>
            </div>
            <div class="text-end ps-2">
                <span class="text-white fw-bold d-block">K' . number_format($price, 2) . '</span>
                <span class="badge bg-info" style="font-size: 0.75rem;">Stock: ' . $quantity . '</span>
            </div>
        </div>';
    }
} else {
    echo '<div class="p-3 text-center text-muted small" style="background-color: #1a1a1a;">No matching items in stock.</div>';
}

$stmt->close();
?>
