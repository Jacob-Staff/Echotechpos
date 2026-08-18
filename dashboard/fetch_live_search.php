<?php
session_start();
require "../includes/conn.php"; 

// ✅ Multi-Tenant Security Check
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

header('Content-Type: text/html');

// Check for a valid connection and session
if (!$conn || $conn->connect_error) {
    die('<div class="list-group-item text-danger">Database connection failed.</div>');
}

if (!$pharmacy_id || !$branch_id) {
    die('<div class="list-group-item text-warning text-center">Session expired. Please log in.</div>');
}

// 2. --- INPUT AND PREPARED STATEMENT ---
if (isset($_GET['q']) && strlen($_GET['q']) >= 1) {
    $search_term = '%' . $_GET['q'] . '%'; 
    $today = date('Y-m-d'); // 1. You MUST define this variable here

    $sql = "SELECT id, item_name, item_code, selling_price, strength 
            FROM store_items 
            WHERE pharmacy_id = ? 
              AND branch_id = ? 
              AND expiry_date > ? 
              AND (item_name LIKE ? OR item_code LIKE ?)
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    
    // 2. Updated Bind: Added 's' for the date and passed $today
    // i (pharmacy), i (branch), s (date), s (name search), s (code search)
    $stmt->bind_param("iisss", $pharmacy_id, $branch_id, $today, $search_term, $search_term);
    
    $stmt->execute();
    $result = $stmt->get_result();
    // 3. --- OUTPUT GENERATION ---
    if ($result && $result->num_rows > 0) {
        echo '<div class="list-group list-group-flush border-0 shadow-sm">';

        while ($row = $result->fetch_assoc()) {
            $name  = htmlspecialchars($row['item_name']);
            $id    = htmlspecialchars($row['id']);
            $code  = htmlspecialchars($row['item_code']);
            $price = number_format($row['selling_price'], 2);
            $stock = (int)$row['strength'];
            
            // UI Logic: Change stock badge color based on availability
            $stock_badge = ($stock <= 5) ? 'bg-danger' : 'bg-info';
            
            echo "
            <a href='javascript:void(0);' class='list-group-item list-group-item-action live-search-item' 
               style='background-color: #1a1a1a; color: #fff; border-bottom: 1px solid #333;'
               data-id='{$id}' 
               data-name='{$name}' 
               data-price='{$row['selling_price']}'>
                <div class='d-flex justify-content-between align-items-center'>
                    <div>
                        <strong style='color: #00ffae;'>{$name}</strong> 
                        <small class='text-muted d-block'>Code: {$code}</small>
                    </div>
                    <div class='text-end'>
                        <span class='badge bg-success text-dark fw-bold'>K{$price}</span>
                        <span class='badge {$stock_badge} ms-1 text-white'>Stock: {$stock}</span>
                    </div>
                </div>
            </a>";
        }
        echo '</div>'; 
    } else {
        echo '<div class="list-group-item text-center text-muted" style="background-color: #1a1a1a; border:none;">No products found in this branch.</div>';
    }
    
    $stmt->close();
} else {
    echo '<div class="list-group-item text-center text-muted" style="background-color: #1a1a1a; border:none;">Start typing to search inventory...</div>';
}

$conn->close();
?>