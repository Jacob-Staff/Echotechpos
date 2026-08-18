<?php
session_start();
require "../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !isset($_POST['query'])) exit;

$query = "%" . $_POST['query'] . "%";
$output = "";

// --- 1. SEARCH MEDICINES / STOCK ---
$stock_sql = "SELECT item_name, quantity, selling_price, category 
              FROM store_items 
              WHERE pharmacy_id = ? AND branch_id = ? AND item_name LIKE ? 
              LIMIT 5";
$stmt1 = $conn->prepare($stock_sql);
$stmt1->bind_param("iis", $pharmacy_id, $branch_id, $query);
$stmt1->execute();
$stock_res = $stmt1->get_result();

if($stock_res->num_rows > 0) {
    $output .= '<div class="section-title">Medicines & Inventory</div>';
    $output .= '<ul class="list-group list-group-flush mb-4">';
    while($row = $stock_res->fetch_assoc()) {
        $output .= "
        <li class='list-group-item d-flex justify-content-between align-items-center'>
            <div>
                <i class='bi bi-capsule text-success me-2'></i>
                <strong>{$row['item_name']}</strong> <small class='text-muted'>({$row['category']})</small>
            </div>
            <span>
                <span class='badge bg-info text-dark me-2'>Stock: {$row['quantity']}</span>
                <span class='fw-bold text-success'>ZMW {$row['selling_price']}</span>
            </span>
        </li>";
    }
    $output .= '</ul>';
}

// --- 2. SEARCH INVOICES / SALES ---
$sales_sql = "SELECT invoice_no, total, created_at 
              FROM sales 
              WHERE pharmacy_id = ? AND branch_id = ? AND (invoice_no LIKE ? OR customer_name LIKE ?) 
              LIMIT 5";
$stmt2 = $conn->prepare($sales_sql);
$stmt2->bind_param("iiss", $pharmacy_id, $branch_id, $query, $query);
$stmt2->execute();
$sales_res = $stmt2->get_result();

if($sales_res->num_rows > 0) {
    $output .= '<div class="section-title">Sales & Invoices</div>';
    $output .= '<ul class="list-group list-group-flush mb-4">';
    while($row = $sales_res->fetch_assoc()) {
        $date = date('d M Y', strtotime($row['created_at']));
        $output .= "
        <li class='list-group-item d-flex justify-content-between align-items-center'>
            <div>
                <i class='bi bi-receipt text-primary me-2'></i>
                Invoice: <strong>#{$row['invoice_no']}</strong>
            </div>
            <div class='text-end'>
                <div class='fw-bold'>ZMW {$row['total']}</div>
                <small class='text-muted'>{$date}</small>
            </div>
        </li>";
    }
    $output .= '</ul>';
}

// --- 3. SEARCH SUPPLIERS ---
$supp_sql = "SELECT supplier_name, contact_person, phone 
             FROM suppliers 
             WHERE pharmacy_id = ? AND supplier_name LIKE ? 
             LIMIT 3";
$stmt3 = $conn->prepare($supp_sql);
$stmt3->bind_param("is", $pharmacy_id, $query);
$stmt3->execute();
$supp_res = $stmt3->get_result();

if($supp_res->num_rows > 0) {
    $output .= '<div class="section-title">Suppliers</div>';
    $output .= '<ul class="list-group list-group-flush">';
    while($row = $supp_res->fetch_assoc()) {
        $output .= "
        <li class='list-group-item d-flex justify-content-between align-items-center'>
            <div>
                <i class='bi bi-truck text-warning me-2'></i>
                <strong>{$row['supplier_name']}</strong>
            </div>
            <small><i class='bi bi-telephone'></i> {$row['phone']}</small>
        </li>";
    }
    $output .= '</ul>';
}

if($output == "") {
    echo "<div class='text-center py-5'><i class='bi bi-exclamation-circle text-danger' style='font-size: 2rem;'></i><p class='mt-2'>No matching records found for '<b>".htmlspecialchars($_POST['query'])."</b>'.</p></div>";
} else {
    echo $output;
}
?>