<?php
/**
 * ============================================================
 * EchoTech POS
 * Fetch Sales Report Data
 * ============================================================
 *
 * Business timezone:
 *   Africa/Lusaka / UTC+02:00
 *
 * Authoritative POS sale timestamp:
 *   sales.sale_date
 *
 * This endpoint preserves the existing JSON contract used by
 * sales_report.php while using prepared statements throughout.
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


/*
|--------------------------------------------------------------------------
| JSON helper
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
| Session / tenant context
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
        'status' => 'error',
        'message' => 'Session expired',
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
| Input
|--------------------------------------------------------------------------
*/

$search = trim(
    (string)($_POST['search'] ?? '')
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
| Validate dates
|--------------------------------------------------------------------------
*/

function valid_report_date(string $date): bool
{
    $d = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return (
        $d !== false &&
        $d->format('Y-m-d') === $date
    );
}

if (!valid_report_date($startDate)) {
    $startDate = date('Y-m-01');
}

if (!valid_report_date($endDate)) {
    $endDate = date('Y-m-d');
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [
        $endDate,
        $startDate
    ];
}


/*
|--------------------------------------------------------------------------
| Business-date boundaries
|--------------------------------------------------------------------------
|
| conn.php already sets MySQL session timezone to +02:00.
| We still send explicit local business boundaries to SQL.
|
*/

$startDateTime = $startDate . ' 00:00:00';
$endDateTime   = $endDate . ' 23:59:59';


/*
|--------------------------------------------------------------------------
| Common WHERE clause
|--------------------------------------------------------------------------
|
| IMPORTANT:
| sales.sale_date is the authoritative POS business timestamp.
|
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
| Search condition
|--------------------------------------------------------------------------
|
| Search:
| - invoice
| - medicine name
|
*/

if ($search !== '') {

    $where .= "
        AND (
            s.invoice LIKE ?
            OR i.item_name LIKE ?
        )
    ";

    $searchLike = '%' . $search . '%';

    $baseTypes .= 'ss';

    $baseParams[] = $searchLike;
    $baseParams[] = $searchLike;
}


/*
|--------------------------------------------------------------------------
| Helper for prepared SELECTs
|--------------------------------------------------------------------------
*/

function execute_sales_query(
    mysqli $conn,
    string $sql,
    string $types,
    array $params
): mysqli_result {

    $stmt = mysqli_prepare(
        $conn,
        $sql
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Database query preparation failed.'
        );
    }

    $bind = [$types];

    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    if (!call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge([$stmt], $bind)
    )) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        throw new RuntimeException(
            'Database parameter binding failed: ' .
            $error
        );
    }

    if (!mysqli_stmt_execute($stmt)) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        throw new RuntimeException(
            'Database query execution failed: ' .
            $error
        );
    }

    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        throw new RuntimeException(
            'Database result retrieval failed: ' .
            $error
        );
    }

    /*
     * Keep the result alive after the statement is closed.
     */
    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_free_result($result);
    mysqli_stmt_close($stmt);

    /*
     * Return a temporary in-memory result equivalent is not possible
     * with mysqli_result, so this helper is intentionally unused below.
     */
    throw new RuntimeException(
        'Internal report query helper misuse.'
    );
}


/*
|--------------------------------------------------------------------------
| 1. Itemized sales
|--------------------------------------------------------------------------
|
| Use one prepared statement directly so the returned rows remain
| straightforward and memory-safe.
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
        s.id DESC
";

$stmt = mysqli_prepare(
    $conn,
    $sales_sql
);

if (!$stmt) {

    error_log(
        'fetch_sales sales query prepare failed: ' .
        mysqli_error($conn)
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

$bindParams = [$baseTypes];

foreach ($baseParams as $key => $value) {
    $bindParams[] = &$baseParams[$key];
}

call_user_func_array(
    'mysqli_stmt_bind_param',
    array_merge(
        [$stmt],
        $bindParams
    )
);

if (!mysqli_stmt_execute($stmt)) {

    error_log(
        'fetch_sales sales query execute failed: ' .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

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
            'sale_id' => $row['sale_id'],
            'invoice_no' => $invoiceNo,
            'item_name' =>
                $row['item_name']
                ?: 'Uncategorized Product',
            'quantity' => $qty,
            'total_price' => $lineTotal,
            'date' =>
                $row['sale_datetime']
                ?? ''
        ];

        $total_sales += $lineTotal;
        $total_items += $qty;

        /*
         * Preserve the existing invoice-count contract.
         */
        $unique_invoices[$invoiceNo] = true;
    }

    mysqli_free_result($result);
}

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| 2. Daily Revenue Trend
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

$stmt = mysqli_prepare(
    $conn,
    $daily_sql
);

if ($stmt) {

    $params = $baseParams;
    $bindParams = [$baseTypes];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge(
            [$stmt],
            $bindParams
        )
    );

    if (mysqli_stmt_execute($stmt)) {

        $result = mysqli_stmt_get_result($stmt);

        if ($result) {

            while ($row = mysqli_fetch_assoc($result)) {

                $daily_trend[
                    $row['day_label']
                ] = (float)(
                    $row['daily_total']
                    ?? 0
                );
            }

            mysqli_free_result($result);
        }
    }

    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| 3. Category Breakdown
|--------------------------------------------------------------------------
*/

$monthly_snapshot = [];

$cat_sql = "
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

$stmt = mysqli_prepare(
    $conn,
    $cat_sql
);

if ($stmt) {

    $params = $baseParams;
    $bindParams = [$baseTypes];

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array(
        'mysqli_stmt_bind_param',
        array_merge(
            [$stmt],
            $bindParams
        )
    );

    if (mysqli_stmt_execute($stmt)) {

        $result = mysqli_stmt_get_result($stmt);

        if ($result) {

            while ($row = mysqli_fetch_assoc($result)) {

                $monthly_snapshot[
                    $row['cat_name']
                ] = (float)(
                    $row['cat_total']
                    ?? 0
                );
            }

            mysqli_free_result($result);
        }
    }

    mysqli_stmt_close($stmt);
}


/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

sales_json([
    'status' => 'success',

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
