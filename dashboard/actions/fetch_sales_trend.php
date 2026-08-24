<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0
    ]);
    exit;
}

$search         = trim((string)($_POST['search'] ?? ''));
$category       = trim((string)($_POST['category'] ?? ''));
$payment_method = trim((string)($_POST['payment_method'] ?? ''));
$startDate      = trim((string)($_POST['startDate'] ?? date('Y-m-d', strtotime('-6 months'))));
$endDate        = trim((string)($_POST['endDate'] ?? date('Y-m-d')));
$trendType      = trim((string)($_POST['trendType'] ?? 'weekly'));

$validDate = static function (string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
};

if (!$validDate($startDate)) {
    $startDate = date('Y-m-d', strtotime('-6 months'));
}
if (!$validDate($endDate)) {
    $endDate = date('Y-m-d');
}
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

if (!in_array($trendType, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    $trendType = 'weekly';
}

/*
 * IMPORTANT:
 * The database uses:
 *   sales.invoice          (not invoice_no)
 *   sales.total_amount    (final sale amount)
 *   sales.payment_method
 *   sales_items.quantity
 *   sales_items.unit_price
 *   store_items.item_name/category
 *
 * The trend endpoint therefore never references the non-existent
 * sales_items.total_price or sales.invoice_no columns.
 */

switch ($trendType) {
    case 'daily':
        $groupBy = "DATE(s.sale_date)";
        $labelSql = "DATE_FORMAT(s.sale_date, '%d %b %Y')";
        break;

    case 'monthly':
        $groupBy = "YEAR(s.sale_date), MONTH(s.sale_date)";
        $labelSql = "DATE_FORMAT(s.sale_date, '%b %Y')";
        break;

    case 'yearly':
        $groupBy = "YEAR(s.sale_date)";
        $labelSql = "CAST(YEAR(s.sale_date) AS CHAR)";
        break;

    case 'weekly':
    default:
        $groupBy = "YEARWEEK(s.sale_date, 1)";
        $labelSql = "CONCAT('Week ', LPAD(WEEK(s.sale_date, 1), 2, '0'), ' ', YEAR(s.sale_date))";
        break;
}

$where = [
    "s.pharmacy_id = ?",
    "s.branch_id = ?",
    "DATE(s.sale_date) BETWEEN ? AND ?"
];

$params = [$pharmacy_id, $branch_id, $startDate, $endDate];
$types  = "iiss";

/*
 * We use EXISTS for item/category/search filters.
 * This avoids multiplying a sale when an invoice contains several items.
 */
if ($category !== '' || $search !== '') {
    $exists = [
        "si.sale_id = s.id",
        "si.pharmacy_id = s.pharmacy_id",
        "si.branch_id = s.branch_id",
        "st.id = si.product_id"
    ];

    if ($category !== '') {
        $exists[] = "st.category = ?";
        $params[] = $category;
        $types .= "s";
    }

    if ($search !== '') {
        $exists[] = "(s.invoice LIKE ? OR st.item_name LIKE ? OR st.barcode LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "sss";
    }

    $where[] = "EXISTS (
        SELECT 1
        FROM sales_items si
        INNER JOIN store_items st ON st.id = si.product_id
        WHERE " . implode(" AND ", $exists) . "
    )";
}

if ($payment_method !== '') {
    $where[] = "s.payment_method = ?";
    $params[] = $payment_method;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

/*
 * When a category/product filter is active, revenue is calculated from
 * the matching sale lines. Otherwise the authoritative sale total is used.
 */
$hasItemFilter = ($category !== '' || $search !== '');

if ($hasItemFilter) {
    $sql = "
        SELECT
            {$labelSql} AS sale_label,
            COALESCE(SUM(si.quantity * COALESCE(si.unit_price, st.price)), 0) AS total_sales,
            COUNT(DISTINCT s.id) AS transactions
        FROM sales s
        INNER JOIN sales_items si
            ON si.sale_id = s.id
           AND si.pharmacy_id = s.pharmacy_id
           AND si.branch_id = s.branch_id
        INNER JOIN store_items st
            ON st.id = si.product_id
        WHERE {$whereSql}
          AND (
              ? = ''
              OR st.category = ?
          )
          AND (
              ? = ''
              OR s.invoice LIKE ?
              OR st.item_name LIKE ?
              OR st.barcode LIKE ?
          )
        GROUP BY {$groupBy}
        ORDER BY MIN(s.sale_date) ASC
    ";

    /*
     * The EXISTS filter above determines which sales qualify, while these
     * additional conditions determine which line values contribute to the
     * filtered revenue.
     */
    $params[] = $category;
    $params[] = $category;
    $types .= "ss";

    $params[] = $search;
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
} else {
    $sql = "
        SELECT
            {$labelSql} AS sale_label,
            COALESCE(SUM(COALESCE(s.total_amount, s.total, 0)), 0) AS total_sales,
            COUNT(s.id) AS transactions
        FROM sales s
        WHERE {$whereSql}
        GROUP BY {$groupBy}
        ORDER BY MIN(s.sale_date) ASC
    ";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to prepare trend query.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0
    ]);
    exit;
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to load sales trend data.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0
    ]);
    $stmt->close();
    exit;
}

$result = $stmt->get_result();

$labels = [];
$totals = [];
$counts = [];
$totalRevenue = 0.0;
$totalTransactions = 0;

while ($row = $result->fetch_assoc()) {
    $amount = (float)$row['total_sales'];
    $count  = (int)$row['transactions'];

    $labels[] = $row['sale_label'];
    $totals[] = round($amount, 2);
    $counts[] = $count;

    $totalRevenue += $amount;
    $totalTransactions += $count;
}

$stmt->close();

echo json_encode([
    'status' => 'success',
    'filters' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'search' => $search,
        'category' => $category,
        'payment_method' => $payment_method,
        'trend_type' => $trendType
    ],
    'labels' => $labels,
    'totals' => $totals,
    'counts' => $counts,
    'total_revenue' => round($totalRevenue, 2),
    'total_transactions' => $totalTransactions
], JSON_UNESCAPED_UNICODE);

exit;
