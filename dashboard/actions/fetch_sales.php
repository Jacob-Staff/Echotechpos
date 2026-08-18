<?php
session_start();
require_once "../../includes/conn.php";

header('Content-Type: application/json');

// 1. Get session variables safely
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id = $_SESSION['branch_id'] ?? 0;

// 2. Get POST data with fallbacks
$search = $_POST['search'] ?? '';
$startDate = $_POST['startDate'] ?? date('Y-m-01');
$endDate = $_POST['endDate'] ?? date('Y-m-d');

$res = [
    'sales' => [], 
    'total_sales' => 0, 
    'total_items' => 0, 
    'total_invoices' => 0, 
    'daily_trend' => [], 
    'monthly_snapshot' => []
];

// 3. Main Sales Table Query
$sql = "SELECT s.invoice, si.quantity, si.unit_price, (si.quantity * si.unit_price) as row_total, 
               st.item_name, s.sale_date 
        FROM sales s 
        JOIN sales_items si ON s.id = si.sale_id 
        JOIN store_items st ON si.product_id = st.id 
        WHERE s.pharmacy_id = ? AND s.branch_id = ? 
        AND (st.item_name LIKE ? OR s.invoice LIKE ?)
        AND DATE(s.sale_date) BETWEEN ? AND ?
        ORDER BY s.sale_date DESC";

$searchParam = "%$search%";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iissss", $pharmacy_id, $branch_id, $searchParam, $searchParam, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$unique_invoices = [];
while ($row = $result->fetch_assoc()) {
    $res['sales'][] = [
        'invoice_no' => $row['invoice'],
        'item_name' => $row['item_name'],
        'quantity' => (int)$row['quantity'],
        'total_price' => (float)$row['row_total'],
        'date' => date('d M, H:i', strtotime($row['sale_date']))
    ];
    $res['total_sales'] += (float)$row['row_total'];
    $res['total_items'] += (int)$row['quantity'];
    $unique_invoices[] = $row['invoice'];
}
$res['total_invoices'] = count(array_unique($unique_invoices));

// 4. Daily Trend for Line Chart
$t_sql = "SELECT DATE(sale_date) as d, SUM(total_amount) as total FROM sales 
          WHERE pharmacy_id = ? AND branch_id = ? AND DATE(sale_date) BETWEEN ? AND ? 
          GROUP BY d ORDER BY d ASC";
$t_stmt = $conn->prepare($t_sql);
$t_stmt->bind_param("iiss", $pharmacy_id, $branch_id, $startDate, $endDate);
$t_stmt->execute();
$t_res = $t_stmt->get_result();
while($r = $t_res->fetch_assoc()) {
    $res['daily_trend'][date('d M', strtotime($r['d']))] = (float)$r['total'];
}

// 5. Monthly Snapshot for Doughnut Chart
$s_sql = "SELECT MONTHNAME(sale_date) as m, SUM(total_amount) as total FROM sales 
          WHERE pharmacy_id = ? AND branch_id = ? AND YEAR(sale_date) = YEAR(CURDATE()) 
          GROUP BY m, MONTH(sale_date) ORDER BY MONTH(sale_date) ASC";
$s_stmt = $conn->prepare($s_sql);
$s_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$s_stmt->execute();
$s_res = $s_stmt->get_result();
while($r = $s_res->fetch_assoc()) {
    $res['monthly_snapshot'][$r['m']] = (float)$r['total'];
}

echo json_encode($res);