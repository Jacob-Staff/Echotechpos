<?php
/**
 * ============================================================
 * EchoTech POS
 * Sales Report Data Endpoint
 * ============================================================
 *
 * Authoritative POS date:
 *     sales.sale_date
 *
 * Business timezone:
 *     Africa/Lusaka
 *
 * Features:
 * - Pharmacy isolation
 * - Branch isolation
 * - Date range filtering
 * - Invoice / medicine search
 * - Category filtering
 * - Itemized sales
 * - Revenue totals
 * - Units sold
 * - Unique invoice count
 * - Daily revenue trend
 * - Category breakdown
 * - Prepared statements
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');


/* ============================================================
   JSON RESPONSE HELPER
============================================================ */

function sales_json(array $data): void
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
   SESSION / TENANT
============================================================ */

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {

    http_response_code(401);

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


/* ============================================================
   INPUT
============================================================ */

$search = trim(
    (string)($_POST['search'] ?? '')
);

$category = trim(
    (string)($_POST['category'] ?? '')
);

$today = date('Y-m-d');

$startDate = trim(
    (string)($_POST['startDate'] ?? $today)
);

$endDate = trim(
    (string)($_POST['endDate'] ?? $today)
);


/* ============================================================
   DATE VALIDATION
============================================================ */

function valid_sales_date(string $date): bool
{
    $dt = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return (
        $dt !== false &&
        $dt->format('Y-m-d') === $date
    );
}

if (!valid_sales_date($startDate)) {
    $startDate = $today;
}

if (!valid_sales_date($endDate)) {
    $endDate = $today;
}


/*
 * If dates are reversed, correct them.
 */
if ($startDate > $endDate) {

    [$startDate, $endDate] = [
        $endDate,
        $startDate
    ];
}


/* ============================================================
   BUSINESS DATE RANGE
============================================================ */

$startDateTime = $startDate . ' 00:00:00';
$endDateTime   = $endDate . ' 23:59:59';


/* ============================================================
   COMMON FILTER
============================================================ */

$where = "
    s.pharmacy_id = ?
    AND s.branch_id = ?
    AND s.sale_date >= ?
    AND s.sale_date <= ?
";

$types = 'iiss';

$params = [
    $pharmacy_id,
    $branch_id,
    $startDateTime,
    $endDateTime
];


/* ============================================================
   SEARCH FILTER
============================================================ */

if ($search !== '') {

    $where .= "
        AND (
            s.invoice LIKE ?
            OR i.item_name LIKE ?
        )
    ";

    $searchLike = '%' . $search . '%';

    $types .= 'ss';

    $params[] = $searchLike;
    $params[] = $searchLike;
}


/* ============================================================
   CATEGORY FILTER
============================================================ */

if ($category !== '') {

    $where .= "
        AND COALESCE(i.category, '') = ?
    ";

    $types .= 's';

    $params[] = $category;
}


/* ============================================================
   PREPARED STATEMENT BINDER
============================================================ */

function bind_dynamic_params(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): bool {

    $bind = [$types];

    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }

    return call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge(
            [$stmt],
            $bind
        )
    );
}


/* ============================================================
   1. ITEMIZED SALES
============================================================ */

$sales = [];

$total_sales = 0.00;
$total_items = 0;

$unique_invoices = [];


$sales_sql = "
    SELECT
        s.id AS sale_id,

        s.invoice AS invoice_no,

        COALESCE(
            i.item_name,
            'Uncategorized Product'
        ) AS item_name,

        COALESCE(
            i.category,
            'General'
        ) AS category,

        si.quantity,

        si.unit_price,

        (
            si.quantity * si.unit_price
        ) AS total_price,

        DATE_FORMAT(
            s.sale_date,
            '%d %b %Y %H:%i'
        ) AS sale_datetime

    FROM sales s

    INNER JOIN sales_items si
        ON si.sale_id = s.id

    LEFT JOIN store_items i
        ON i.id = si.product_id

    WHERE $where

    ORDER BY
        s.sale_date DESC,
        s.id DESC,
        si.id ASC
";


$stmt = mysqli_prepare(
    $conn,
    $sales_sql
);

if (!$stmt) {

    error_log(
        'fetch_sales.php prepare failed: ' .
        mysqli_error($conn)
    );

    http_response_code(500);

    sales_json([
        'status' => 'error',
        'message' => 'Unable to prepare sales report.',
        'sales' => [],
        'total_sales' => '0.00',
        'total_items' => 0,
        'total_invoices' => 0,
        'monthly_snapshot' => [],
        'daily_trend' => []
    ]);
}


if (!bind_dynamic_params(
    $stmt,
    $types,
    $params
)) {

    error_log(
        'fetch_sales.php bind failed: ' .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

    http_response_code(500);

    sales_json([
        'status' => 'error',
        'message' => 'Unable to prepare sales filters.',
        'sales' => [],
        'total_sales' => '0.00',
        'total_items' => 0,
        'total_invoices' => 0,
        'monthly_snapshot' => [],
        'daily_trend' => []
    ]);
}


if (!mysqli_stmt_execute($stmt)) {

    error_log(
        'fetch_sales.php execute failed: ' .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

    http_response_code(500);

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


$result = mysqli_stmt_get_result($stmt);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {

        $sale_id = (int)$row['sale_id'];

        $invoice = trim(
            (string)($row['invoice_no'] ?? '')
        );

        if ($invoice === '') {
            $invoice = 'N/A';
        }

        $quantity = (int)(
            $row['quantity'] ?? 0
        );

        $unit_price = (float)(
            $row['unit_price'] ?? 0
        );

        $line_total = (float)(
            $row['total_price'] ?? 0
        );


        $sales[] = [

            'sale_id' => $sale_id,

            'invoice_no' => $invoice,

            'item_name' =>
                $row['item_name']
                ?: 'Uncategorized Product',

            'category' =>
                $row['category']
                ?: 'General',

            'quantity' => $quantity,

            'unit_price' => $unit_price,

            'total_price' => $line_total,

            'date' =>
                $row['sale_datetime']
                ?? ''
        ];


        $total_sales += $line_total;

        $total_items += $quantity;

        /*
         * Count invoices once even when an invoice
         * contains multiple medicines.
         */
        $unique_invoices[$invoice] = true;
    }

    mysqli_free_result($result);
}

mysqli_stmt_close($stmt);


/* ============================================================
   2. DAILY REVENUE TREND
============================================================ */

$daily_trend = [];


$daily_sql = "
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
        ON si.sale_id = s.id

    LEFT JOIN store_items i
        ON i.id = si.product_id

    WHERE $where

    GROUP BY
        DATE(s.sale_date)

    ORDER BY
        DATE(s.sale_date) ASC
";


$stmt = mysqli_prepare(
    $conn,
    $daily_sql
);

if ($stmt) {

    $dailyParams = $params;

    if (bind_dynamic_params(
        $stmt,
        $types,
        $dailyParams
    )) {

        if (mysqli_stmt_execute($stmt)) {

            $dailyResult =
                mysqli_stmt_get_result($stmt);

            if ($dailyResult) {

                while (
                    $row =
                    mysqli_fetch_assoc(
                        $dailyResult
                    )
                ) {

                    $daily_trend[
                        $row['day_label']
                    ] = (float)(
                        $row['daily_total']
                        ?? 0
                    );
                }

                mysqli_free_result(
                    $dailyResult
                );
            }
        }
    }

    mysqli_stmt_close($stmt);
}


/* ============================================================
   3. CATEGORY BREAKDOWN
============================================================ */

$monthly_snapshot = [];


/*
 * IMPORTANT:
 *
 * Category filtering is intentionally applied here too,
 * so the chart always matches the visible report filter.
 */

$category_sql = "
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
        ON si.sale_id = s.id

    LEFT JOIN store_items i
        ON i.id = si.product_id

    WHERE $where

    GROUP BY
        COALESCE(
            i.category,
            'General'
        )

    ORDER BY
        cat_total DESC
";


$stmt = mysqli_prepare(
    $conn,
    $category_sql
);

if ($stmt) {

    $categoryParams = $params;

    if (bind_dynamic_params(
        $stmt,
        $types,
        $categoryParams
    )) {

        if (mysqli_stmt_execute($stmt)) {

            $categoryResult =
                mysqli_stmt_get_result(
                    $stmt
                );

            if ($categoryResult) {

                while (
                    $row =
                    mysqli_fetch_assoc(
                        $categoryResult
                    )
                ) {

                    $monthly_snapshot[
                        $row['cat_name']
                    ] = (float)(
                        $row['cat_total']
                        ?? 0
                    );
                }

                mysqli_free_result(
                    $categoryResult
                );
            }
        }
    }

    mysqli_stmt_close($stmt);
}


/* ============================================================
   FINAL RESPONSE
============================================================ */

sales_json([

    'status' => 'success',

    /*
     * Debug information is useful while we are finishing
     * Phase 4. These values can later be removed.
     */
    'filters' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'search' => $search,
        'category' => $category
    ],

    'sales' => $sales,

    'total_sales' =>
        number_format(
            $total_sales,
            2,
            '.',
            ''
        ),

    'total_items' =>
        $total_items,

    'total_invoices' =>
        count($unique_invoices),

    'monthly_snapshot' =>
        $monthly_snapshot,

    'daily_trend' =>
        $daily_trend
]);
