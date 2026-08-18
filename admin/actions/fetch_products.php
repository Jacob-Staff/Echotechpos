<?php
session_start();
// Updated path to go up TWO levels to find includes
require_once '../../includes/conn.php';

// 1. Security Check
if (!isset($_SESSION['branch_id'])) {
    echo "<li class='p-3 text-danger border-bottom'><i class='fas fa-exclamation-triangle me-2'></i>Session expired.</li>";
    exit();
}

$branch_id = $_SESSION['branch_id'];
$query = trim($_REQUEST['q'] ?? $_POST['query'] ?? '');

if (empty($query)) {
    exit();
}

$searchTerm = "%$query%";

try {
    // 2. Query store_items for THIS specific branch
    $sql = "SELECT id, item_name, price, quantity, capacity, barcode 
            FROM store_items 
            WHERE (item_name LIKE ? OR barcode LIKE ?) 
            AND branch_id = ? 
            AND quantity > 0 
            ORDER BY item_name ASC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $searchTerm, $searchTerm, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // 3. Generate UI List Items
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $displayBarcode = !empty($row['barcode']) ? $row['barcode'] : 'N/A';
            $price = number_format($row['price'], 2);
            $fullName = htmlspecialchars($row['item_name'], ENT_QUOTES);
            $capacity = htmlspecialchars($row['capacity'] ?? 'N/A', ENT_QUOTES);
            $jsDisplayName = "$fullName ($capacity)";

            echo "
            <li class='search-item p-3 border-bottom list-unstyled text-white' 
                onclick=\"addToCart({$row['id']}, '{$jsDisplayName}', {$row['price']})\"
                style='cursor:pointer; transition: background 0.2s;'>
                
                <div class='d-flex justify-content-between align-items-center'>
                    <div style='max-width: 70%;'>
                        <span class='fw-bold' style='color:#00d2ff; font-size: 0.95rem;'>{$fullName}</span>
                        <br>
                        <small class='text-muted' style='font-size: 0.8rem;'>
                            Stock: <span class='text-" . ($row['quantity'] < 10 ? 'danger' : 'info') . "'>{$row['quantity']}</span> | 
                            {$capacity}
                        </small>
                    </div>
                    <div class='text-end'>
                        <span class='badge bg-success px-2 py-1' style='font-size: 0.85rem;'>K{$price}</span>
                        <br>
                        <small class='text-muted' style='font-size: 0.65rem; display: block; margin-top: 4px;'>
                            <i class='fas fa-barcode me-1'></i>{$displayBarcode}
                        </small>
                    </div>
                </div>
            </li>";
        }
    } else {
        echo "<li class='p-3 text-center text-muted border-bottom list-unstyled'>No items found.</li>";
    }
    $stmt->close();
} catch (Exception $e) {
    echo "<li class='p-3 text-danger list-unstyled'>Error: " . htmlspecialchars($e->getMessage()) . "</li>";
}
?>