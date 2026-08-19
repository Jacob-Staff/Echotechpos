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
    echo "<tr><td colspan='8' class='p-3 text-center text-danger'>Invalid session. Please log in again.</td></tr>";
    exit;
}

// Prepare SQL (matching expiry checks compatible with strict MySQL rules)
$sql = "SELECT id, item_name, barcode, price, category, quantity, strength, expiry_date 
        FROM store_items 
        WHERE pharmacy_id = ? 
          AND branch_id = ? 
          AND (expiry_date > ? OR expiry_date IS NULL OR CAST(expiry_date AS CHAR) = '0000-00-00') 
          AND quantity > 0 
          AND (item_name LIKE ? OR barcode LIKE ? OR category LIKE ?)
        ORDER BY item_name ASC
        LIMIT 50";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<tr><td colspan='8' class='p-3 text-center text-danger'>Database error: {$conn->error}</td></tr>";
    exit;
}

$stmt->bind_param("iissss", $p_id, $b_id, $today, $search_term, $search_term, $search_term);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    $sn = 1;
    while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $name = htmlspecialchars($row['item_name'], ENT_QUOTES);
        $barcode = htmlspecialchars($row['barcode'] ?? '', ENT_QUOTES);
        $category = htmlspecialchars($row['category'] ?? 'Medicine', ENT_QUOTES);
        $strength = htmlspecialchars($row['strength'] ?? '', ENT_QUOTES);
        $price = floatval($row['price']);
        $quantity = intval($row['quantity']);
        $row_total = $price * $quantity;
        $expiry_date = $row['expiry_date'];

        $is_expired = ($expiry_date < $today && $expiry_date != '0000-00-00' && !empty($expiry_date));
        $stock_status = ($quantity <= 10) ? 'bg-danger' : 'bg-success';

        $display_name = $name;
        if (!empty($strength)) {
            $display_name .= " ({$strength})";
        }

        echo "
        <tr data-id='{$id}'>
            <td class='ps-4 text-muted'>{$sn}</td>
            <td>
                <span class='fw-bold text-dark'>{$display_name}</span>";
                if (!empty($barcode)) {
                    echo "<br><small class='text-muted'>Barcode: {$barcode}</small>";
                }
        echo "
            </td>
            <td class='fw-bold'>K " . number_format($price, 2) . "</td>
            <td><span class='badge bg-light text-dark border'>{$category}</span></td>
            <td>
                <span class='badge {$stock_status} text-white px-2 py-1'>" . number_format($quantity) . "</span>
            </td>
            <td class='text-dark fw-bold'>K " . number_format($row_total, 2) . "</td>
            <td>";
                if ($expiry_date != '0000-00-00' && !empty($expiry_date)) {
                    $formatted_date = date('d M Y', strtotime($expiry_date));
                    $class = $is_expired ? 'text-danger fw-bold' : 'text-muted';
                    echo "<span class='{$class}'>{$formatted_date}</span>";
                    if ($is_expired) {
                        echo "<br><small class='badge bg-danger'>EXPIRED</small>";
                    }
                } else {
                    echo "<span class='text-muted'>-</span>";
                }
        echo "
            </td>
            <td class='text-center'>
                <a href='update_product.php?id={$id}' class='btn btn-outline-info btn-sm rounded-circle me-1' title='Edit'><i class='fas fa-pen'></i></a>
                <button type='button' class='btn btn-outline-danger btn-sm rounded-circle delete-btn' data-id='{$id}' title='Delete'><i class='fas fa-trash'></i></button>
            </td>
        </tr>";

        $sn++;
    }
} else {
    $search_clean = htmlspecialchars($q, ENT_QUOTES);
    echo "<tr>
            <td colspan='8' class='text-center py-4 text-muted'>
                <i class='fas fa-search me-1'></i> No matching stock items found for '<strong>{$search_clean}</strong>'.
            </td>
          </tr>";
}

$stmt->close();
?>
