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


/*
|--------------------------------------------------------------------------
| BUILD FILTERS
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The sales table is the authoritative transaction table.
| We aggregate one row per sale, not one row per sales_items row.
|
| Revenue:
|   Prefer total_amount.
|   Fall back to total for older POS records where total_amount is 0/NULL.
|
| Online:
|   client_reference LIKE ONLINE_ORDER:%
|   OR payment_method LIKE Online/%
|--------------------------------------------------------------------------
*/

$where = [
    "s.pharmacy_id = ?",
    "s.branch_id = ?",
    "s.sale_date >= ?",
    "s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)"
];

$params = [
    $pharmacy_id,
    $branch_id,
    $startDate . ' 00:00:00',
    $endDate . ' 00:00:00'
];

$types = "iiss";

if ($category !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM sales_items si_cat
        INNER JOIN store_items st_cat
            ON st_cat.id = si_cat.product_id
        WHERE si_cat.sale_id = s.id
          AND si_cat.pharmacy_id = s.pharmacy_id
          AND si_cat.branch_id = s.branch_id
          AND st_cat.category = ?
    )";

    $params[] = $category;
    $types .= "s";
}

if ($search !== '') {
    $searchLike = '%' . $search . '%';

    $where[] = "(
        s.invoice LIKE ?
        OR s.client_reference LIKE ?
        OR EXISTS (
            SELECT 1
            FROM sales_items si_search
            INNER JOIN store_items st_search
                ON st_search.id = si_search.product_id
            WHERE si_search.sale_id = s.id
              AND si_search.pharmacy_id = s.pharmacy_id
              AND si_search.branch_id = s.branch_id
              AND (
                  st_search.item_name LIKE ?
                  OR st_search.barcode LIKE ?
              )
        )
    )";

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "ssss";
}

if ($payment_method !== '') {
    $where[] = "s.payment_method = ?";
    $params[] = $payment_method;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

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

/*
 * One row in sales = one transaction.
 * This prevents sales_items from multiplying transaction counts.
 */
$sql = "
    SELECT
        {$labelSql} AS sale_label,

        COALESCE(
            SUM(
                CASE
                    WHEN COALESCE(s.total_amount, 0) > 0
                        THEN s.total_amount
                    ELSE COALESCE(s.total, 0)
                END
            ),
            0
        ) AS total_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN (
                        COALESCE(s.client_reference, '') LIKE 'ONLINE_ORDER:%'
                        OR COALESCE(s.payment_method, '') LIKE 'Online/%'
                    )
                    THEN
                        CASE
                            WHEN COALESCE(s.total_amount, 0) > 0
                                THEN s.total_amount
                            ELSE COALESCE(s.total, 0)
                        END
                    ELSE 0
                END
            ),
            0
        ) AS online_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN NOT (
                        COALESCE(s.client_reference, '') LIKE 'ONLINE_ORDER:%'
                        OR COALESCE(s.payment_method, '') LIKE 'Online/%'
                    )
                    THEN
                        CASE
                            WHEN COALESCE(s.total_amount, 0) > 0
                                THEN s.total_amount
                            ELSE COALESCE(s.total, 0)
                        END
                    ELSE 0
                END
            ),
            0
        ) AS pos_sales,

        COUNT(*) AS transactions,

        SUM(
            CASE
                WHEN (
                    COALESCE(s.client_reference, '') LIKE 'ONLINE_ORDER:%'
                    OR COALESCE(s.payment_method, '') LIKE 'Online/%'
                )
                THEN 1
                ELSE 0
            END
        ) AS online_transactions,

        SUM(
            CASE
                WHEN NOT (
                    COALESCE(s.client_reference, '') LIKE 'ONLINE_ORDER:%'
                    OR COALESCE(s.payment_method, '') LIKE 'Online/%'
                )
                THEN 1
                ELSE 0
            END
        ) AS pos_transactions

    FROM sales s
    WHERE {$whereSql}
    GROUP BY {$groupBy}
    ORDER BY MIN(s.sale_date) ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log(
        'fetch_sales_trend prepare failed: ' . $conn->error
    );

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to prepare the sales trend query.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'online_totals' => [],
        'pos_totals' => [],
        'online_counts' => [],
        'pos_counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0,
        'total_online_revenue' => 0,
        'total_pos_revenue' => 0,
        'total_online_transactions' => 0,
        'total_pos_transactions' => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * mysqli bind_param requires references. Build them explicitly.
 */
$bind = [$types];

foreach ($params as $key => &$value) {
    $bind[] = &$value;
}

if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
    error_log(
        'fetch_sales_trend bind_param failed: ' . $stmt->error
    );

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to bind the sales trend filters.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'online_totals' => [],
        'pos_totals' => [],
        'online_counts' => [],
        'pos_counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0,
        'total_online_revenue' => 0,
        'total_pos_revenue' => 0,
        'total_online_transactions' => 0,
        'total_pos_transactions' => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
unset($value);

if (!$stmt->execute()) {
    error_log(
        'fetch_sales_trend execute failed: ' . $stmt->error
    );

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to load sales trend data from the database.',
        'labels' => [],
        'totals' => [],
        'counts' => [],
        'online_totals' => [],
        'pos_totals' => [],
        'online_counts' => [],
        'pos_counts' => [],
        'total_revenue' => 0,
        'total_transactions' => 0,
        'total_online_revenue' => 0,
        'total_pos_revenue' => 0,
        'total_online_transactions' => 0,
        'total_pos_transactions' => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $stmt->get_result();

$labels = [];
$totals = [];
$counts = [];
$onlineTotals = [];
$posTotals = [];
$onlineCounts = [];
$posCounts = [];

$totalRevenue = 0.0;
$totalTransactions = 0;
$totalOnlineRevenue = 0.0;
$totalPosRevenue = 0.0;
$totalOnlineTransactions = 0;
$totalPosTransactions = 0;

while ($row = $result->fetch_assoc()) {
    $amount = (float)($row['total_sales'] ?? 0);
    $count = (int)($row['transactions'] ?? 0);
    $onlineAmount = (float)($row['online_sales'] ?? 0);
    $posAmount = (float)($row['pos_sales'] ?? 0);
    $onlineCount = (int)($row['online_transactions'] ?? 0);
    $posCount = (int)($row['pos_transactions'] ?? 0);

    $labels[] = (string)$row['sale_label'];
    $totals[] = round($amount, 2);
    $counts[] = $count;
    $onlineTotals[] = round($onlineAmount, 2);
    $posTotals[] = round($posAmount, 2);
    $onlineCounts[] = $onlineCount;
    $posCounts[] = $posCount;

    $totalRevenue += $amount;
    $totalTransactions += $count;
    $totalOnlineRevenue += $onlineAmount;
    $totalPosRevenue += $posAmount;
    $totalOnlineTransactions += $onlineCount;
    $totalPosTransactions += $posCount;
}

$result->free();
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
    'online_totals' => $onlineTotals,
    'pos_totals' => $posTotals,
    'online_counts' => $onlineCounts,
    'pos_counts' => $posCounts,
    'total_revenue' => round($totalRevenue, 2),
    'total_transactions' => $totalTransactions,
    'total_online_revenue' => round($totalOnlineRevenue, 2),
    'total_pos_revenue' => round($totalPosRevenue, 2),
    'total_online_transactions' => $totalOnlineTransactions,
    'total_pos_transactions' => $totalPosTransactions
], JSON_UNESCAPED_UNICODE);

exit;
