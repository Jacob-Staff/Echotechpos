<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    echo json_encode([
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0
    ]);
    exit();
}

// Extract Inputs
$search         = trim($_POST['search'] ?? '');
$category       = trim($_POST['category'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? '');
$startDate      = trim($_POST['startDate'] ?? date('Y-m-d', strtotime('-6 months')));
$endDate        = trim($_POST['endDate'] ?? date('Y-m-d'));
$trendType      = trim($_POST['trendType'] ?? 'weekly');

// Grouping Logic
switch ($trendType) {
    case 'daily':
        $group_by = "DATE(s.sale_date)";
        $label_format = "DATE_FORMAT(s.sale_date, '%d %b %Y')";
        break;
    case 'monthly':
        $group_by = "YEAR(s.sale_date), MONTH(s.sale_date)";
        $label_format = "DATE_FORMAT(s.sale_date, '%b %Y')";
        break;
    case 'yearly':
        $group_by = "YEAR(s.sale_date)";
        $label_format = "YEAR(s.sale_date)";
        break;
    case 'weekly':
    default:
        $group_by = "YEAR(s.sale_date), WEEK(s.sale_date, 1)";
        $label_format = "CONCAT('Wk ', WEEK(s.sale_date, 1), ' ', YEAR(s.sale_date))";
        break;
}

// Build Base Query Filters
$where_clauses = ["s.pharmacy_id = ?", "s.branch_id = ?", "DATE(s.sale_date) BETWEEN ? AND ?"];
$params = [$pharmacy_id, $branch_id, $startDate, $endDate];
$types  = "iiss";

// Optional Joins Flag (if category or product search is used)
$needs_items_join = false;

if (!empty($category)) {
    $needs_items_join = true;
    $where_clauses[] = "si.category = ?";
    $params[] = $category;
    $types .= "s";
}

if (!empty($payment_method)) {
    $where_clauses[] = "s.payment_method = ?";
    $params[] = $payment_method;
    $types .= "s";
}

if (!empty($search)) {
    $needs_items_join = true;
    $where_clauses[] = "(s.invoice_no LIKE ? OR si.item_name LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = implode(" AND ", $where_clauses);

// Build SQL Query
if ($needs_items_join) {
    $sql = "SELECT $label_format AS sale_label, 
                   SUM(si_items.total_price) as total_sales, 
                   COUNT(DISTINCT s.id) as transactions
            FROM sales s
            JOIN sales_items si_items ON s.id = si_items.sale_id
            JOIN store_items si ON si_items.item_id = si.id
            WHERE $where_sql
            GROUP BY $group_by
            ORDER BY MIN(s.sale_date) ASC";
} else {
    $sql = "SELECT $label_format AS sale_label, 
                   SUM(s.total_amount) as total_sales, 
                   COUNT(s.id) as transactions
            FROM sales s
            WHERE $where_sql
            GROUP BY $group_by
            ORDER BY MIN(s.sale_date) ASC";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$sales_labels = [];
$sales_totals = [];
$sales_counts = [];
$total_revenue = 0;
$total_transactions = 0;

while ($row = $result->fetch_assoc()) {
    $sales_labels[] = $row['sale_label'];
    $sales_totals[] = (float)$row['total_sales'];
    $sales_counts[] = (int)$row['transactions'];
    $total_revenue += (float)$row['total_sales'];
    $total_transactions += (int)$row['transactions'];
}
$stmt->close();

echo json_encode([
    'labels'             => $sales_labels,
    'totals'             => $sales_totals,
    'counts'             => $sales_counts,
    'total_revenue'      => $total_revenue,
    'total_transactions' => $total_transactions
]);
exit();
