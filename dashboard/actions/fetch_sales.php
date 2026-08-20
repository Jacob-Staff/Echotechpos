<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired',
        'sales' => [],
        'total_sales' => 0,
        'total_items' => 0,
        'total_invoices' => 0,
        'monthly_snapshot' => [],
        'daily_trend' => []
    ]);
    exit();
}

$search    = trim($_POST['search'] ?? '');
$startDate = trim($_POST['startDate'] ?? date('Y-m-01'));
$endDate   = trim($_POST['endDate'] ?? date('Y-m-d'));

// Build Base Query Filters
$where_clauses = ["s.pharmacy_id = $pharmacy_id", "s.branch_id = $branch_id"];

if (!empty($startDate)) {
    $s_date = mysqli_real_escape_string($conn, $startDate . ' 00:00:00');
    $where_clauses[] = "s.created_at >= '$s_date'";
}

if (!empty($endDate)) {
    $e_date = mysqli_real_escape_string($conn, $endDate . ' 23:59:59');
    $where_clauses[] = "s.created_at <= '$e_date'";
}

if (!empty($search)) {
    $s_term = mysqli_real_escape_string($conn, $search);
    $where_clauses[] = "(s.invoice LIKE '%$s_term%' OR i.item_name LIKE '%$s_term%')";
}

$where_sql = implode(' AND ', $where_clauses);

// 1. Fetch Itemized Sales List
$sales_sql = "SELECT 
                s.id as sale_id,
                s.invoice as invoice_no,
                i.item_name,
                i.category,
                si.quantity,
                si.unit_price,
                (si.quantity * si.unit_price) as total_price,
                DATE_FORMAT(s.created_at, '%d %b %Y %H:%i') as date
              FROM sales s
              INNER JOIN sales_items si ON s.id = si.sale_id
              LEFT JOIN store_items i ON si.product_id = i.id
              WHERE $where_sql
              ORDER BY s.created_at DESC, s.id DESC";

$sales_result = mysqli_query($conn, $sales_sql);

$sales = [];
$total_sales = 0;
$total_items = 0;
$unique_invoices = [];

if ($sales_result && mysqli_num_rows($sales_result) > 0) {
    while ($row = mysqli_fetch_assoc($sales_result)) {
        $qty = (int)$row['quantity'];
        $line_total = (float)$row['total_price'];

        $sales[] = [
            'sale_id'     => $row['sale_id'],
            'invoice_no'  => $row['invoice_no'] ?: 'N/A',
            'item_name'   => $row['item_name'] ?: 'Uncategorized Product',
            'quantity'    => $qty,
            'total_price' => $line_total,
            'date'        => $row['date']
        ];

        $total_sales += $line_total;
        $total_items += $qty;
        $unique_invoices[$row['invoice_no']] = true;
    }
}

// 2. Daily Revenue Trend (Line Chart)
$daily_sql = "SELECT 
                DATE_FORMAT(s.created_at, '%d %b') as day_label,
                SUM(si.quantity * si.unit_price) as daily_total
              FROM sales s
              INNER JOIN sales_items si ON s.id = si.sale_id
              LEFT JOIN store_items i ON si.product_id = i.id
              WHERE $where_sql
              GROUP BY DATE(s.created_at)
              ORDER BY s.created_at ASC";

$daily_res = mysqli_query($conn, $daily_sql);
$daily_trend = [];

if ($daily_res) {
    while ($row = mysqli_fetch_assoc($daily_res)) {
        $daily_trend[$row['day_label']] = (float)$row['daily_total'];
    }
}

// 3. Category Breakdown (Doughnut Chart)
$cat_sql = "SELECT 
              COALESCE(i.category, 'General') as cat_name,
              SUM(si.quantity * si.unit_price) as cat_total
            FROM sales s
            INNER JOIN sales_items si ON s.id = si.sale_id
            LEFT JOIN store_items i ON si.product_id = i.id
            WHERE $where_sql
            GROUP BY COALESCE(i.category, 'General')";

$cat_res = mysqli_query($conn, $cat_sql);
$monthly_snapshot = [];

if ($cat_res) {
    while ($row = mysqli_fetch_assoc($cat_res)) {
        $monthly_snapshot[$row['cat_name']] = (float)$row['cat_total'];
    }
}

// Output JSON
echo json_encode([
    'status'           => 'success',
    'sales'            => $sales,
    'total_sales'      => number_format($total_sales, 2, '.', ''),
    'total_items'      => $total_items,
    'total_invoices'   => count($unique_invoices),
    'monthly_snapshot' => $monthly_snapshot,
    'daily_trend'      => $daily_trend
]);
exit();
