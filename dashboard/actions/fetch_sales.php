<?php
/**
 * ============================================================
 * EchoTech POS
 * FETCH SALES REPORT DATA
 * ============================================================
 *
 * Business timezone:
 *     Africa/Lusaka (UTC+02:00)
 *
 * Authoritative sale timestamp:
 *     sales.sale_date
 *
 * Supports:
 *     - Pharmacy isolation
 *     - Branch isolation
 *     - Search by invoice/product
 *     - Category filtering
 *     - Date range filtering
 *     - Itemized sales
 *     - Revenue totals
 *     - Invoice totals
 *     - Daily revenue trend
 *     - Category breakdown
 *
 * JSON contract:
 *     status
 *     sales
 *     total_sales
 *     total_items
 *     total_invoices
 *     monthly_snapshot
 *     daily_trend
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

/*
|--------------------------------------------------------------------------
| BUSINESS TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Lusaka');


/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function sales_json(array $payload): void
{
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit();
}


/*
|--------------------------------------------------------------------------
| ERROR RESPONSE
|--------------------------------------------------------------------------
*/

function sales_error(string $message = 'Unable to load sales report.'): void
{
    error_log('EchoTech fetch_sales.php: ' . $message);

    sales_json([
        'status'           => 'error',
        'message'          => $message,
        'sales'            => [],
        'total_sales'      => '0.00',
        'total_items'      => 0,
        'total_invoices'   => 0,
        'monthly_snapshot' => [],
        'daily_trend'      => []
    ]);
}


/*
|--------------------------------------------------------------------------
| SESSION / TENANT CONTEXT
|--------------------------------------------------------------------------
*/

$pharmacy_id = (int)(
    $_SESSION['pharmacy_id'] ?? 0
);

$branch_id = (int)(
    $_SESSION['branch_id'] ?? 0
);

if (
    $pharmacy_id <= 0 ||
    $branch_id <= 0
) {
    sales_json([
        'status'           => 'error',
        'message'          => 'Session expired',
        'sales'            => [],
        'total_sales'      => '0.00',
        'total_items'      => 0,
        'total_invoices'   => 0,
        'monthly_snapshot' => [],
        'daily_trend'      => []
    ]);
}


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$search = trim(
    (string)($_POST['search'] ?? '')
);

$category = trim(
    (string)($_POST['category'] ?? '')
);

$startDate = trim(
    (string)(
        $_POST['startDate']
        ?? date('Y-m-01')
    )
);

$endDate = trim(
    (string)(
        $_POST['endDate']
        ?? date('Y-m-d')
    )
);


/*
|--------------------------------------------------------------------------
| DATE VALIDATION
|--------------------------------------------------------------------------
*/

function valid_report_date(string $date): bool
{
    $parsed = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return (
        $parsed !== false &&
        $parsed->format('Y-m-d') === $date
    );
}


/*
|--------------------------------------------------------------------------
| FALLBACK DATES
|--------------------------------------------------------------------------
*/

if (!valid_report_date($startDate)) {
    $startDate = date('Y-m-01');
}

if (!valid_report_date($endDate)) {
    $endDate = date('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| NORMALIZE REVERSED DATE RANGE
|--------------------------------------------------------------------------
*/

if ($startDate > $endDate) {
    [$startDate, $endDate] = [
        $endDate,
        $startDate
    ];
}


/*
|--------------------------------------------------------------------------
| BUSINESS DATE BOUNDARIES
|--------------------------------------------------------------------------
|
| These are LOCAL Zambia business dates.
|
| Example:
|
| 24 Aug 2026 00:55 Zambia
|
| remains:
|
| 2026-08-24 00:55:00
|
| and is NOT shifted back to 23 Aug.
|
|--------------------------------------------------------------------------
*/

$startDateTime =
    $startDate . ' 00:00:00';

$endDateTime =
    $endDate . ' 23:59:59';


/*
|--------------------------------------------------------------------------
| COMMON WHERE CLAUSE
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| sales.sale_date is the POS sale timestamp.
|
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


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
|
| Search:
|     invoice
|     medicine/product name
|
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where .= "
        AND (
            s.invoice LIKE ?
            OR i.item_name LIKE ?
        )
    ";

    $searchLike =
        '%' . $search . '%';

    $baseTypes .= 'ss';

    $baseParams[] =
        $searchLike;

    $baseParams[] =
        $searchLike;
}


/*
|--------------------------------------------------------------------------
| CATEGORY FILTER
|--------------------------------------------------------------------------
*/

if ($category !== '') {

    $where .= "
        AND i.category = ?
    ";

    $baseTypes .= 's';

    $baseParams[] =
        $category;
}


/*
|--------------------------------------------------------------------------
| 1. ITEMIZED SALES
|--------------------------------------------------------------------------
*/

$sales = [];

$total_sales = 0.00;

$total_items = 0;

$unique_invoices = [];


$sales_sql = "
    SELECT

        s.id AS sale_id,

        s.invoice AS invoice_no,

        i.item_name,

        i.category,

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
        ON s.id = si.sale_id

    LEFT JOIN store_items i
        ON si.product_id = i.id

    WHERE $where

    ORDER BY
        s.sale_date DESC,
        s.id DESC
";


$stmt = mysqli_prepare(
    $conn,
    $sales_sql
);


if (!$stmt) {

    sales_error(
        'Unable to prepare sales query.'
    );
}


$bindParams = [
    $baseTypes
];

foreach ($baseParams as $key => $value) {

    $bindParams[] =
        &$baseParams[$key];
}


if (!call_user_func_array(
    'mysqli_stmt_bind_param',
    array_merge(
        [$stmt],
        $bindParams
    )
)) {

    mysqli_stmt_close($stmt);

    sales_error(
        'Unable to bind sales query parameters.'
    );
}


if (!mysqli_stmt_execute($stmt)) {

    $error =
        mysqli_stmt_error($stmt);

    mysqli_stmt_close($stmt);

    error_log(
        'fetch_sales sales query execute failed: ' .
        $error
    );

    sales_error(
        'Unable to load sales data.'
    );
}


$result =
    mysqli_stmt_get_result($stmt);


if (!$result) {

    $error =
        mysqli_stmt_error($stmt);

    mysqli_stmt_close($stmt);

    error_log(
        'fetch_sales result failed: ' .
        $error
    );

    sales_error(
        'Unable to retrieve sales data.'
    );
}


while ($row = mysqli_fetch_assoc($result)) {

    $qty = (int)(
        $row['quantity'] ?? 0
    );

    $lineTotal = (float)(
        $row['total_price'] ?? 0
    );

    $invoiceNo =
        $row['invoice_no']
        ?: 'N/A';

    $sales[] = [

        'sale_id' =>
            (int)$row['sale_id'],

        'invoice_no' =>
            $invoiceNo,

        'item_name' =>
            $row['item_name']
            ?: 'Uncategorized Product',

        'quantity' =>
            $qty,

        'total_price' =>
            $lineTotal,

        'date' =>
            $row['sale_datetime']
            ?? ''
    ];


    $total_sales +=
        $lineTotal;


    $total_items +=
        $qty;


    $unique_invoices[
        $invoiceNo
    ] = true;
}


mysqli_free_result($result);

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| 2. DAILY REVENUE TREND
|--------------------------------------------------------------------------
*/

$daily_trend = [];


$daily_sql = "
    SELECT

        DATE_FORMAT(
            s.sale_date,
            '%d %b'
        ) AS day_label,

        SUM(
            si.quantity *
            si.unit_price
        ) AS daily_total

    FROM sales s

    INNER JOIN sales_items si
        ON s.id = si.sale_id

    LEFT JOIN store_items i
        ON si.product_id = i.id

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

    $params =
        $baseParams;

    $bindParams = [
        $baseTypes
    ];

    foreach ($params as $key => $value) {

        $bindParams[] =
            &$params[$key];
    }


    if (call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge(
            [$stmt],
            $bindParams
        )
    )) {

        if (mysqli_stmt_execute($stmt)) {

            $result =
                mysqli_stmt_get_result($stmt);

            if ($result) {

                while (
                    $row =
                    mysqli_fetch_assoc($result)
                ) {

                    $daily_trend[
                        $row['day_label']
                    ] = (float)(
                        $row['daily_total']
                        ?? 0
                    );
                }

                mysqli_free_result(
                    $result
                );
            }
        }
    }

    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| 3. CATEGORY BREAKDOWN
|--------------------------------------------------------------------------
*/

$monthly_snapshot = [];


$category_sql = "
    SELECT

        COALESCE(
            i.category,
            'General'
        ) AS cat_name,

        SUM(
            si.quantity *
            si.unit_price
        ) AS cat_total

    FROM sales s

    INNER JOIN sales_items si
        ON s.id = si.sale_id

    LEFT JOIN store_items i
        ON si.product_id = i.id

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

    $params =
        $baseParams;

    $bindParams = [
        $baseTypes
    ];

    foreach ($params as $key => $value) {

        $bindParams[] =
            &$params[$key];
    }


    if (call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge(
            [$stmt],
            $bindParams
        )
    )) {

        if (mysqli_stmt_execute($stmt)) {

            $result =
                mysqli_stmt_get_result($stmt);

            if ($result) {

                while (
                    $row =
                    mysqli_fetch_assoc($result)
                ) {

                    $monthly_snapshot[
                        $row['cat_name']
                    ] = (float)(
                        $row['cat_total']
                        ?? 0
                    );
                }

                mysqli_free_result(
                    $result
                );
            }
        }
    }

    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| FINAL JSON RESPONSE
|--------------------------------------------------------------------------
*/

sales_json([

    'status' =>
        'success',

    'filters' => [

        'start_date' =>
            $startDate,

        'end_date' =>
            $endDate,

        'search' =>
            $search,

        'category' =>
            $category
    ],

    'sales' =>
        $sales,

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
