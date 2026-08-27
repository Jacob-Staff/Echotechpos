<?php
/**
 * ============================================================
 * EchoTech POS
 * FETCH SALES REPORT DATA
 * ============================================================
 *
 * Authoritative sales timestamp:
 *     sales.sale_date
 *
 * Supports:
 *     - Pharmacy / branch tenancy
 *     - Local Zambia business dates
 *     - Keyword search
 *     - Category filter
 *     - Itemized sales
 *     - Revenue totals
 *     - Daily revenue trend
 *     - Category breakdown
 *
 * Completed online orders are normal sales records in:
 *     sales + sales_items
 * and are therefore included automatically.
 * ============================================================
 */

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

function sales_json(array $payload): never
{
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function valid_report_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);

    return $d !== false
        && $d->format('Y-m-d') === $date;
}

function bind_dynamic(mysqli_stmt $stmt, string $types, array &$params): void
{
    $bind = [$types];

    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }

    if (!call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge([$stmt], $bind)
    )) {
        throw new RuntimeException(
            'Database parameter binding failed: ' . $stmt->error
        );
    }
}

/*
|--------------------------------------------------------------------------
| SESSION / TENANT
|--------------------------------------------------------------------------
*/

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    sales_json([
        'status' => 'error',
        'message' => 'Session expired.',
        'sales' => [],
        'total_sales' => '0.00',
        'total_items' => 0,
        'total_invoices' => 0,
        'monthly_snapshot' => [],
        'daily_trend' => []
    ]);
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$search = trim((string)($_POST['search'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));

$startDate = trim(
    (string)($_POST['startDate'] ?? date('Y-m-01'))
);

$endDate = trim(
    (string)($_POST['endDate'] ?? date('Y-m-d'))
);

if (!valid_report_date($startDate)) {
    $startDate = date('Y-m-01');
}

if (!valid_report_date($endDate)) {
    $endDate = date('Y-m-d');
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime   = $endDate . ' 23:59:59';

/*
|--------------------------------------------------------------------------
| COMMON FILTER
|--------------------------------------------------------------------------
|
| sale_date is used deliberately because the online-order completion
| workflow records the real POS transaction time there.
|--------------------------------------------------------------------------
*/

$where = "
    s.pharmacy_id = ?
    AND s.branch_id = ?
    AND s.sale_date >= ?
    AND s.sale_date <= ?
";

$baseTypes = 'iiss';
$baseParams = [
    $pharmacy_id,
    $branch_id,
    $startDateTime,
    $endDateTime
];

if ($search !== '') {
    $where .= "
        AND (
            s.invoice LIKE ?
            OR s.client_reference LIKE ?
            OR i.item_name LIKE ?
        )
    ";

    $searchLike = '%' . $search . '%';

    $baseTypes .= 'sss';
    $baseParams[] = $searchLike;
    $baseParams[] = $searchLike;
    $baseParams[] = $searchLike;
}

if ($category !== '') {
    $where .= " AND COALESCE(i.category, 'General') = ? ";
    $baseTypes .= 's';
    $baseParams[] = $category;
}

try {

    /*
    |--------------------------------------------------------------------------
    | 1. ITEMIZED SALES
    |--------------------------------------------------------------------------
    */

    $sales = [];
    $total_sales = 0.00;
    $total_items = 0;
    $unique_invoices = [];

    $salesSql = "
        SELECT
            s.id AS sale_id,
            s.invoice AS invoice_no,
            s.client_reference,
            s.payment_method,
            i.item_name,
            i.category,
            si.quantity,
            si.unit_price,
            (si.quantity * si.unit_price) AS total_price,
            DATE_FORMAT(
                s.sale_date,
                '%d %b %Y %H:%i'
            ) AS sale_datetime
        FROM sales s
        INNER JOIN sales_items si
            ON s.id = si.sale_id
        LEFT JOIN store_items i
            ON si.product_id = i.id
        WHERE $where
        ORDER BY
            s.sale_date DESC,
            s.id DESC,
            si.id ASC
    ";

    $stmt = $conn->prepare($salesSql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare sales report query: ' . $conn->error
        );
    }

    $params = $baseParams;
    bind_dynamic($stmt, $baseTypes, $params);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to load sales report: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $qty = (int)($row['quantity'] ?? 0);
        $lineTotal = (float)($row['total_price'] ?? 0);

        $invoiceNo = trim((string)($row['invoice_no'] ?? ''));

        if ($invoiceNo === '') {
            $invoiceNo = 'N/A';
        }

        $sales[] = [
            'sale_id' => (int)$row['sale_id'],
            'invoice_no' => $invoiceNo,
            'item_name' => $row['item_name']
                ?: 'Uncategorized Product',
            'category' => $row['category']
                ?: 'General',
            'quantity' => $qty,
            'unit_price' => (float)($row['unit_price'] ?? 0),
            'total_price' => $lineTotal,
            'payment_method' => $row['payment_method'] ?? '',
            'source' => str_starts_with(
                (string)($row['client_reference'] ?? ''),
                'ONLINE_ORDER_'
            ) ? 'Online Order' : 'POS Sale',
            'date' => $row['sale_datetime'] ?? ''
        ];

        $total_sales += $lineTotal;
        $total_items += $qty;
        $unique_invoices[$invoiceNo] = true;
    }

    $result->free();
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | 2. DAILY REVENUE TREND
    |--------------------------------------------------------------------------
    */

    $daily_trend = [];

    $dailySql = "
        SELECT
            DATE_FORMAT(
                s.sale_date,
                '%d %b'
            ) AS day_label,
            SUM(
                si.quantity * si.unit_price
            ) AS daily_total
        FROM sales s
        INNER JOIN sales_items si
            ON s.id = si.sale_id
        LEFT JOIN store_items i
            ON si.product_id = i.id
        WHERE $where
        GROUP BY DATE(s.sale_date)
        ORDER BY DATE(s.sale_date) ASC
    ";

    $stmt = $conn->prepare($dailySql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare daily sales report query: ' . $conn->error
        );
    }

    $params = $baseParams;
    bind_dynamic($stmt, $baseTypes, $params);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to load daily sales trend: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $daily_trend[(string)$row['day_label']] =
            (float)($row['daily_total'] ?? 0);
    }

    $result->free();
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | 3. CATEGORY BREAKDOWN
    |--------------------------------------------------------------------------
    */

    $monthly_snapshot = [];

    $categorySql = "
        SELECT
            COALESCE(
                i.category,
                'General'
            ) AS cat_name,
            SUM(
                si.quantity * si.unit_price
            ) AS cat_total
        FROM sales s
        INNER JOIN sales_items si
            ON s.id = si.sale_id
        LEFT JOIN store_items i
            ON si.product_id = i.id
        WHERE $where
        GROUP BY COALESCE(
            i.category,
            'General'
        )
        ORDER BY cat_total DESC
    ";

    $stmt = $conn->prepare($categorySql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare category sales report query: ' . $conn->error
        );
    }

    $params = $baseParams;
    bind_dynamic($stmt, $baseTypes, $params);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to load category sales report: ' . $stmt->error
        );
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $monthly_snapshot[(string)$row['cat_name']] =
            (float)($row['cat_total'] ?? 0);
    }

    $result->free();
    $stmt->close();

    sales_json([
        'status' => 'success',
        'sales' => $sales,
        'total_sales' => number_format(
            $total_sales,
            2,
            '.',
            ''
        ),
        'total_items' => $total_items,
        'total_invoices' => count($unique_invoices),
        'monthly_snapshot' => $monthly_snapshot,
        'daily_trend' => $daily_trend
    ]);

} catch (Throwable $e) {

    error_log(
        'EchoTech fetch_sales.php: ' . $e->getMessage()
    );

    sales_json([
        'status' => 'error',
        'message' => 'Unable to load sales report.',
        'sales' => [],
        'total_sales' => '0.00',
        'total_items' => 0,
        'total_invoices' => 0,
        'monthly_snapshot' => [],
        'daily_trend' => []
    ]);
}
?>
