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

$startDate = trim($_POST['startDate'] ?? date('Y-m-d', strtotime('-6 months')));
$endDate   = trim($_POST['endDate'] ?? date('Y-m-d'));
$trendType = trim($_POST['trendType'] ?? 'weekly');

switch ($trendType) {
    case 'monthly':
        $group_by = "YEAR(sale_date), MONTH(sale_date)";
        $label_format = "DATE_FORMAT(sale_date,'%b %Y')";
        break;
    case 'yearly':
        $group_by = "YEAR(sale_date)";
        $label_format = "YEAR(sale_date)";
        break;
    case 'weekly':
    default:
        $group_by = "YEAR(sale_date), WEEK(sale_date, 1)";
        $label_format = "CONCAT('Wk ', WEEK(sale_date, 1), ' ', YEAR(sale_date))";
        break;
}

// Fetch Trend Details
$sales_labels = [];
$sales_totals = [];
$sales_counts = [];

$sales_sql = "SELECT $label_format AS sale_label, 
                     SUM(total_amount) as total_sales, 
                     COUNT(*) as transactions
              FROM sales
              WHERE pharmacy_id = ? AND branch_id = ?
              AND DATE(sale_date) BETWEEN ? AND ?
              GROUP BY $group_by
              ORDER BY MIN(sale_date) ASC";

$stmt = $conn->prepare($sales_sql);
$stmt->bind_param("iiss", $pharmacy_id, $branch_id, $startDate, $endDate);
$stmt->execute();
$sales_result = $stmt->get_result();

while ($row = $sales_result->fetch_assoc()) {
    $sales_labels[] = $row['sale_label'];
    $sales_totals[] = (float)$row['total_sales'];
    $sales_counts[] = (int)$row['transactions'];
}
$stmt->close();

// Fetch Overall Totals for Range
$summary_sql = "SELECT SUM(total_amount) as total_sales, COUNT(*) as total_transactions
                FROM sales
                WHERE pharmacy_id = ? AND branch_id = ?
                AND DATE(sale_date) BETWEEN ? AND ?";
$s_stmt = $conn->prepare($summary_sql);
$s_stmt->bind_param("iiss", $pharmacy_id, $branch_id, $startDate, $endDate);
$s_stmt->execute();
$summary_result = $s_stmt->get_result()->fetch_assoc();
$s_stmt->close();

$total_revenue      = (float)($summary_result['total_sales'] ?? 0);
$total_transactions = (int)($summary_result['total_transactions'] ?? 0);

echo json_encode([
    'labels'             => $sales_labels,
    'totals'             => $sales_totals,
    'counts'             => $sales_counts,
    'total_revenue'      => $total_revenue,
    'total_transactions' => $total_transactions
]);
exit();