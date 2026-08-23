<?php
/**
 * ============================================================
 * ECHOTECH POS
 * TODAY'S TRANSACTIONS / TRANSACTION HISTORY
 * Phase 3G-3
 * ============================================================
 *
 * Features:
 * - Tenant + branch isolation
 * - Date filtering
 * - Payment-method filtering
 * - Invoice / reference / cashier / medicine search
 * - Filtered revenue + invoice count
 * - View invoice
 * - Reprint invoice
 * - Prepared SQL parameters throughout
 * ============================================================
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

/*
|--------------------------------------------------------------------------
| Tenant / Branch Context
|--------------------------------------------------------------------------
*/

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

if ($p_id <= 0 || $b_id <= 0) {
    http_response_code(401);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Session expired. Please log in again.
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function tx_e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$filter_date = trim(
    (string)($_GET['filter_date'] ?? $today)
);

$filter_method = trim(
    (string)($_GET['filter_method'] ?? 'All')
);

$search = trim(
    (string)($_GET['search'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Validate Date
|--------------------------------------------------------------------------
*/

$dateObject = DateTime::createFromFormat(
    'Y-m-d',
    $filter_date
);

$dateIsValid =
    $dateObject !== false &&
    $dateObject->format('Y-m-d') === $filter_date;

if (!$dateIsValid) {
    $filter_date = $today;
}

$display_date = date(
    'd M Y',
    strtotime($filter_date)
);

/*
|--------------------------------------------------------------------------
| Normalize Payment Filter
|--------------------------------------------------------------------------
*/

$allowedMethods = [
    'All',
    'Cash',
    'Card',
    'Mobile'
];

if (!in_array(
    $filter_method,
    $allowedMethods,
    true
)) {
    $filter_method = 'All';
}

/*
|--------------------------------------------------------------------------
| Branding
|--------------------------------------------------------------------------
*/

$infoSql = "
    SELECT
        p.name,
        b.branch_name
    FROM pharmacies p
    INNER JOIN branches b
        ON b.pharmacy_id = p.id
    WHERE p.id = ?
      AND b.id = ?
    LIMIT 1
";

$infoStmt = mysqli_prepare(
    $conn,
    $infoSql
);

if (!$infoStmt) {
    error_log(
        'today_transactions.php branding prepare failed: ' .
        mysqli_error($conn)
    );

    http_response_code(500);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Unable to load pharmacy information.
        </div>
    ");
}

mysqli_stmt_bind_param(
    $infoStmt,
    "ii",
    $p_id,
    $b_id
);

if (!mysqli_stmt_execute($infoStmt)) {
    error_log(
        'today_transactions.php branding execute failed: ' .
        mysqli_stmt_error($infoStmt)
    );

    mysqli_stmt_close($infoStmt);

    http_response_code(500);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Unable to load pharmacy information.
        </div>
    ");
}

$infoResult = mysqli_stmt_get_result(
    $infoStmt
);

$info = mysqli_fetch_assoc(
    $infoResult
);

mysqli_stmt_close(
    $infoStmt
);

$display_pharm =
    $info['name']
    ?? 'PHARMANOVA';

$display_bran =
    $info['branch_name']
    ?? 'Main Branch';

/*
|--------------------------------------------------------------------------
| Build Secure Transaction Query
|--------------------------------------------------------------------------
|
| Every dynamic value is bound as a parameter.
| No user-supplied filter is concatenated into SQL.
|--------------------------------------------------------------------------
*/

$where = [
    "s.pharmacy_id = ?",
    "s.branch_id = ?",
    "DATE(s.created_at) = ?"
];

$params = [
    $p_id,
    $b_id,
    $filter_date
];

$types = "iis";

/*
|--------------------------------------------------------------------------
| Payment Method Filter
|--------------------------------------------------------------------------
*/

if ($filter_method === 'Mobile') {

    $where[] = "
        LOWER(COALESCE(s.payment_method, ''))
        IN ('mobile', 'mobile money', 'momo')
    ";

} elseif ($filter_method !== 'All') {

    $where[] = "
        LOWER(COALESCE(s.payment_method, ''))
        = LOWER(?)
    ";

    $params[] = $filter_method;
    $types .= "s";
}

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
|
| Searches:
| - Invoice number
| - Client transaction reference
| - Cashier / issuer
| - Medicine name
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $searchLike = '%' . $search . '%';

    $where[] = "
        (
            LOWER(COALESCE(s.invoice, ''))
                LIKE LOWER(?)

            OR LOWER(COALESCE(s.client_reference, ''))
                LIKE LOWER(?)

            OR LOWER(
                COALESCE(
                    u.username,
                    u.full_name,
                    s.issued_by,
                    ''
                )
            ) LIKE LOWER(?)

            OR EXISTS (
                SELECT 1
                FROM sales_items si_search
                INNER JOIN store_items st_search
                    ON st_search.id = si_search.product_id
                WHERE si_search.sale_id = s.id
                  AND si_search.pharmacy_id = s.pharmacy_id
                  AND si_search.branch_id = s.branch_id
                  AND LOWER(
                        COALESCE(
                            st_search.item_name,
                            ''
                        )
                      ) LIKE LOWER(?)
            )
        )
    ";

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;

    $types .= "ssss";
}

/*
|--------------------------------------------------------------------------
| Final Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.*,

        COALESCE(
            u.username,
            u.full_name,
            s.issued_by,
            'System'
        ) AS issuer,

        (
            SELECT
                GROUP_CONCAT(
                    CONCAT(
                        st.item_name,
                        ' (x',
                        si.quantity,
                        ')'
                    )
                    SEPARATOR ', '
                )
            FROM sales_items si
            INNER JOIN store_items st
                ON si.product_id = st.id
            WHERE si.sale_id = s.id
              AND si.pharmacy_id = s.pharmacy_id
              AND si.branch_id = s.branch_id
        ) AS items_sold

    FROM sales s

    LEFT JOIN users u
        ON s.user_id = u.id

    WHERE " .
    implode(
        "\n AND ",
        $where
    ) . "

    ORDER BY s.id DESC
";

/*
|--------------------------------------------------------------------------
| Prepare
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    $sql
);

if (!$stmt) {

    error_log(
        'today_transactions.php sales prepare failed: ' .
        mysqli_error($conn)
    );

    http_response_code(500);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Unable to load transactions.
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Bind Dynamic Parameters
|--------------------------------------------------------------------------
*/

$bindValues = [];

$bindValues[] = $types;

foreach ($params as $key => $value) {
    $bindValues[] = &$params[$key];
}

if (!call_user_func_array(
    'mysqli_stmt_bind_param',
    array_merge(
        [$stmt],
        $bindValues
    )
)) {

    error_log(
        'today_transactions.php bind failed: ' .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

    http_response_code(500);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Unable to prepare transaction filters.
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

if (!mysqli_stmt_execute($stmt)) {

    error_log(
        'today_transactions.php sales execute failed: ' .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

    http_response_code(500);

    die("
        <div class='alert alert-danger text-center mt-3'>
            Unable to load transactions.
        </div>
    ");
}

$result = mysqli_stmt_get_result(
    $stmt
);

/*
|--------------------------------------------------------------------------
| Collect Results + Totals
|--------------------------------------------------------------------------
*/

$total_revenue = 0.00;
$total_invoices = 0;
$sales_data = [];

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {

        $sales_data[] = $row;

        $total_revenue += (float)(
            $row['total']
            ?? $row['total_amount']
            ?? 0
        );

        $total_invoices++;
    }
}

mysqli_stmt_close(
    $stmt
);

/*
|--------------------------------------------------------------------------
| Payment Summary
|--------------------------------------------------------------------------
*/

$paymentSummary = [
    'cash' => ['count' => 0, 'amount' => 0.00],
    'card' => ['count' => 0, 'amount' => 0.00],
    'mobile' => ['count' => 0, 'amount' => 0.00],
];

foreach ($sales_data as $tx) {

    $method = strtolower(
        trim(
            (string)($tx['payment_method'] ?? 'cash')
        )
    );

    $amount = (float)(
        $tx['total']
        ?? $tx['total_amount']
        ?? 0
    );

    if ($method === 'card') {
        $paymentSummary['card']['count']++;
        $paymentSummary['card']['amount'] += $amount;

    } elseif (
        in_array(
            $method,
            ['mobile', 'mobile money', 'momo'],
            true
        )
    ) {
        $paymentSummary['mobile']['count']++;
        $paymentSummary['mobile']['amount'] += $amount;

    } else {
        $paymentSummary['cash']['count']++;
        $paymentSummary['cash']['amount'] += $amount;
    }
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>

<style>
/* =========================================================
   ECHOTECH POS â€” TODAY'S TRANSACTIONS
   Dashboard-matched professional UI
========================================================= */

:root{
    --dash-navy:#2d3e4f;
    --dash-header:#405466;
    --dash-blue:#419bd0;
    --dash-blue-dark:#257db5;
    --dash-slate:#394b5d;
    --dash-orange:#f27b0b;
    --dash-orange-dark:#e34d12;
    --dash-red:#c90718;
    --dash-green:#16a064;
    --dash-bg:#f1f4f8;
    --dash-border:#e0e5eb;
    --dash-text:#1f2937;
    --dash-muted:#738096;
    --dash-shadow:0 5px 18px rgba(31,45,61,.07);
}

.report-wrapper{
    min-height:calc(100vh - 65px);
    padding:22px;
    background:var(--dash-bg)!important;
    color:var(--dash-text);
}

.tx-page{
    max-width:1500px;
    margin:0 auto;
}

/* ---------------------------------------------------------
   PAGE HEADING
--------------------------------------------------------- */

.tx-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    margin-bottom:18px;
}

.tx-heading-left{
    display:flex;
    align-items:center;
    gap:13px;
}

.tx-heading-icon{
    width:48px;
    height:48px;
    display:grid;
    place-items:center;
    border-radius:10px;
    background:var(--dash-blue);
    color:#fff;
    font-size:19px;
    box-shadow:0 5px 12px rgba(65,155,208,.2);
}

.tx-heading h1{
    margin:0;
    color:var(--dash-navy);
    font-size:24px;
    line-height:1.1;
    font-weight:800;
}

.tx-heading-sub{
    margin-top:5px;
    color:var(--dash-muted);
    font-size:12px;
}

.tx-date-chip{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border:1px solid var(--dash-border);
    border-radius:8px;
    background:#fff;
    color:#536174;
    font-size:12px;
    font-weight:700;
    box-shadow:var(--dash-shadow);
}

/* ---------------------------------------------------------
   FILTER PANEL
--------------------------------------------------------- */

.tx-filter-card{
    padding:16px;
    margin-bottom:18px;
    border:1px solid var(--dash-border);
    border-radius:9px;
    background:#fff;
    box-shadow:var(--dash-shadow);
}

.tx-label{
    display:block;
    margin-bottom:6px;
    color:#667386;
    font-size:9px;
    font-weight:800;
    letter-spacing:.7px;
    text-transform:uppercase;
}

.tx-search{
    position:relative;
}

.tx-search i{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#8d99a9;
    pointer-events:none;
}

.tx-search input{
    height:42px;
    padding-left:38px;
    border:1px solid #d9dfe7!important;
    border-radius:6px!important;
    background:#fff!important;
}

.tx-filter-card input[type=date],
.tx-filter-card select{
    height:42px;
    border:1px solid #d9dfe7!important;
    border-radius:6px!important;
    background:#fff!important;
}

.tx-filter-card .btn{
    height:42px;
    border-radius:6px;
    font-size:12px;
    font-weight:700;
}

.tx-filter-help{
    margin-top:6px;
    color:#99a3b1;
    font-size:10px;
}

/* ---------------------------------------------------------
   DASHBOARD-STYLE KPI CARDS
--------------------------------------------------------- */

.tx-kpis{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
    margin-bottom:18px;
}

.tx-kpi{
    position:relative;
    overflow:hidden;
    min-height:124px;
    padding:18px 19px;
    border-radius:8px;
    color:#fff;
    box-shadow:var(--dash-shadow);
}

.tx-kpi.blue{
    background:linear-gradient(145deg,#429fd4,#318bc5);
}

.tx-kpi.slate{
    background:linear-gradient(145deg,#435668,#344657);
}

.tx-kpi.orange{
    background:linear-gradient(145deg,#f58a09,#ef650d);
}

.tx-kpi.red{
    background:linear-gradient(145deg,#d30c1d,#b90012);
}

.tx-kpi::after{
    content:"";
    position:absolute;
    width:100px;
    height:100px;
    right:-35px;
    bottom:-45px;
    border-radius:50%;
    background:rgba(255,255,255,.08);
}

.tx-kpi-label{
    position:relative;
    z-index:1;
    font-size:10px;
    font-weight:800;
    letter-spacing:.45px;
    text-transform:uppercase;
}

.tx-kpi-value{
    position:relative;
    z-index:1;
    margin-top:10px;
    font-size:25px;
    line-height:1;
    font-weight:850;
}

.tx-kpi-note{
    position:relative;
    z-index:1;
    margin-top:9px;
    color:rgba(255,255,255,.78);
    font-size:10px;
}

.tx-kpi-icon{
    position:absolute;
    z-index:1;
    right:16px;
    top:16px;
    width:37px;
    height:37px;
    display:grid;
    place-items:center;
    border-radius:8px;
    background:rgba(255,255,255,.15);
    font-size:15px;
}

/* ---------------------------------------------------------
   TRANSACTION REGISTER
--------------------------------------------------------- */

.tx-register{
    overflow:hidden;
    border:1px solid var(--dash-border);
    border-radius:9px;
    background:#fff;
    box-shadow:var(--dash-shadow);
}

.tx-register-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:15px 18px;
    border-bottom:1px solid var(--dash-border);
}

.tx-register-title{
    display:flex;
    align-items:center;
    gap:8px;
    color:var(--dash-navy);
    font-size:14px;
    font-weight:800;
}

.tx-register-title i{
    color:var(--dash-blue);
}

.tx-count{
    min-width:25px;
    padding:3px 8px;
    border-radius:12px;
    background:#eaf4fb;
    color:var(--dash-blue-dark);
    text-align:center;
    font-size:10px;
    font-weight:800;
}

.tx-register-date{
    color:#8994a4;
    font-size:11px;
}

.tx-table-wrap{
    overflow-x:auto;
}

.report-table{
    width:100%;
    min-width:980px;
    border-collapse:collapse;
}

.report-table thead th{
    padding:12px 15px;
    background:#f5f7f9;
    border-bottom:1px solid #dfe4ea;
    color:#596678!important;
    font-size:10px;
    font-weight:800;
    letter-spacing:.45px;
    text-transform:uppercase;
    white-space:nowrap;
}

.report-table tbody td{
    padding:13px 15px;
    color:#394657;
    border-bottom:1px solid #edf0f3;
    font-size:12px;
    vertical-align:middle;
}

.report-table tbody tr:hover{
    background:#f8fbfd;
}

.report-table tbody tr:last-child td{
    border-bottom:0;
}

.tx-invoice{
    color:#176dcc;
    font-weight:800;
    white-space:nowrap;
}

.tx-sale-id{
    margin-top:3px;
    color:#9ca6b4;
    font-size:9px;
}

.tx-items{
    max-width:390px;
    line-height:1.45;
}

.tx-cashier{
    display:flex;
    align-items:center;
    gap:7px;
}

.tx-avatar{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#e9eef4;
    color:#536275;
    font-size:10px;
    font-weight:800;
}

.tx-method{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 8px;
    border-radius:5px;
    font-size:9px;
    font-weight:800;
    white-space:nowrap;
}

.tx-method.cash{
    background:#fff2dd;
    color:#a66700;
}

.tx-method.card{
    background:#e8f3fc;
    color:#176dcc;
}

.tx-method.mobile{
    background:#e7f7ef;
    color:#16865a;
}

.tx-total{
    color:var(--dash-green)!important;
    font-weight:850!important;
    white-space:nowrap;
}

.tx-actions{
    display:flex;
    justify-content:center;
    gap:6px;
}

.tx-action{
    width:32px;
    height:32px;
    display:grid;
    place-items:center;
    border:1px solid #dce2e8;
    border-radius:5px;
    background:#fff;
    text-decoration:none;
    transition:.15s ease;
}

.tx-action.view{
    color:#176dcc;
}

.tx-action.print{
    color:#16865a;
}

.tx-action:hover{
    transform:translateY(-1px);
    box-shadow:0 4px 9px rgba(0,0,0,.08);
}

.tx-empty{
    padding:65px 20px!important;
    text-align:center;
}

.tx-empty-icon{
    width:58px;
    height:58px;
    margin:0 auto 12px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#eef2f6;
    color:#8793a3;
    font-size:20px;
}

.tx-empty-title{
    color:#4b5768;
    font-weight:800;
}

.tx-empty-text{
    margin-top:4px;
    color:#9aa4b1;
    font-size:11px;
}

/* ---------------------------------------------------------
   RESPONSIVE
--------------------------------------------------------- */

@media(max-width:1100px){
    .tx-kpis{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:768px){
    .report-wrapper{
        padding:15px;
    }

    .tx-heading{
        align-items:flex-start;
        flex-direction:column;
    }

    .tx-date-chip{
        width:max-content;
    }

    .tx-kpis{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:520px){
    .tx-kpis{
        grid-template-columns:1fr;
    }

    .tx-heading h1{
        font-size:21px;
    }

    .tx-filter-card{
        padding:13px;
    }
}

/* ---------------------------------------------------------
   PRINT
--------------------------------------------------------- */

@media print{
    @page{
        size:A4;
        margin:10mm;
    }

    html,body{
        background:#fff!important;
        width:100%;
        height:auto!important;
        margin:0!important;
        padding:0!important;
    }

    .no-print,
    .topbar,
    .left-sidebar,
    nav,
    footer,
    .tx-filter-card{
        display:none!important;
    }

    #main-wrapper,
    .page-wrapper,
    .report-wrapper{
        width:100%!important;
        min-height:auto!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }

    .tx-kpi{
        color:#000!important;
        box-shadow:none!important;
        border:1px solid #ccc!important;
        background:#fff!important;
    }

    .tx-kpi-value,
    .tx-kpi-label,
    .tx-kpi-note{
        color:#000!important;
    }

    .tx-register{
        box-shadow:none!important;
    }
}
</style>

<div id="main-wrapper">

    <?php

    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }

    ?>

    <div class="page-wrapper report-wrapper">

        <div class="tx-page">

            <!-- =================================================
                 HEADER
            ================================================== -->

            <div class="tx-heading">

                <div class="tx-heading-left">

                    <div class="tx-heading-icon">
                        <i class="fas fa-receipt"></i>
                    </div>

                    <div>

                        <h1>
                            Today's Transactions
                        </h1>

                        <div class="tx-heading-sub">
                            <?= tx_e(
                                strtoupper($display_pharm)
                            ) ?>

                            <span class="mx-1">â€¢</span>

                            <?= tx_e($display_bran) ?>

                            <span class="mx-1">â€¢</span>

                            <?= (int)$total_invoices ?>
                            completed sale(s)
                        </div>

                    </div>

                </div>


                <div class="tx-date-chip">

                    <i class="far fa-calendar-alt text-primary"></i>

                    <?= tx_e($display_date) ?>

                </div>

            </div>


            <!-- =================================================
                 FILTERS
            ================================================== -->

            <div class="tx-filter-card no-print">

                <form method="GET" autocomplete="off">

                    <div class="row g-3 align-items-end">

                        <div class="col-xl-5 col-lg-5 col-md-12">

                            <label
                                class="tx-label"
                                for="transactionSearch"
                            >
                                Search Transactions
                            </label>

                            <div class="tx-search">

                                <i class="fas fa-search"></i>

                                <input
                                    id="transactionSearch"
                                    type="search"
                                    name="search"
                                    class="form-control"
                                    value="<?= tx_e($search) ?>"
                                    placeholder="Invoice, medicine, cashier or transaction reference..."
                                >

                            </div>

                            <div class="tx-filter-help">
                                Search the selected business date.
                            </div>

                        </div>


                        <div class="col-xl-2 col-lg-2 col-md-4">

                            <label
                                class="tx-label"
                                for="transactionDate"
                            >
                                Business Date
                            </label>

                            <input
                                id="transactionDate"
                                type="date"
                                name="filter_date"
                                class="form-control"
                                value="<?= tx_e($filter_date) ?>"
                            >

                        </div>


                        <div class="col-xl-2 col-lg-2 col-md-4">

                            <label
                                class="tx-label"
                                for="transactionMethod"
                            >
                                Payment Method
                            </label>

                            <select
                                id="transactionMethod"
                                name="filter_method"
                                class="form-select"
                            >

                                <option
                                    value="All"
                                    <?= $filter_method === 'All'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    All Methods
                                </option>

                                <option
                                    value="Cash"
                                    <?= $filter_method === 'Cash'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Cash
                                </option>

                                <option
                                    value="Card"
                                    <?= $filter_method === 'Card'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Card
                                </option>

                                <option
                                    value="Mobile"
                                    <?= $filter_method === 'Mobile'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Mobile Money
                                </option>

                            </select>

                        </div>


                        <div class="col-xl-3 col-lg-3 col-md-4">

                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-primary flex-grow-1"
                                >
                                    <i class="fas fa-search me-1"></i>
                                    Search
                                </button>


                                <?php if (
                                    $search !== '' ||
                                    $filter_method !== 'All' ||
                                    $filter_date !== $today
                                ): ?>

                                    <a
                                        href="today_transactions.php"
                                        class="btn btn-light border"
                                        title="Reset filters"
                                    >
                                        <i class="fas fa-times"></i>
                                    </a>

                                <?php endif; ?>


                                <button
                                    type="button"
                                    class="btn btn-outline-dark"
                                    onclick="window.print()"
                                    title="Print transaction report"
                                >
                                    <i class="fas fa-print"></i>
                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>


            <!-- =================================================
                 DASHBOARD COLOR SUMMARY
            ================================================== -->

            <div class="tx-kpis">

                <div class="tx-kpi blue">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-wallet"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Revenue
                    </div>

                    <div class="tx-kpi-value">
                        K<?= number_format(
                            $total_revenue,
                            2
                        ) ?>
                    </div>

                    <div class="tx-kpi-note">
                        <?= tx_e(
                            $filter_method === 'All'
                                ? 'All payment methods'
                                : $filter_method
                        ) ?>
                    </div>

                </div>


                <div class="tx-kpi slate">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-receipt"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Transactions
                    </div>

                    <div class="tx-kpi-value">
                        <?= (int)$total_invoices ?>
                    </div>

                    <div class="tx-kpi-note">
                        Completed sales
                    </div>

                </div>


                <div class="tx-kpi orange">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Cash Sales
                    </div>

                    <div class="tx-kpi-value">
                        <?= (int)$paymentSummary['cash']['count'] ?>
                    </div>

                    <div class="tx-kpi-note">
                        K<?= number_format(
                            $paymentSummary['cash']['amount'],
                            2
                        ) ?>
                    </div>

                </div>


                <div class="tx-kpi red">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Card + Mobile
                    </div>

                    <div class="tx-kpi-value">
                        <?= (
                            (int)$paymentSummary['card']['count'] +
                            (int)$paymentSummary['mobile']['count']
                        ) ?>
                    </div>

                    <div class="tx-kpi-note">
                        K<?= number_format(
                            $paymentSummary['card']['amount'] +
                            $paymentSummary['mobile']['amount'],
                            2
                        ) ?>
                    </div>

                </div>

            </div>


            <!-- =================================================
                 REGISTER
            ================================================== -->

            <div class="tx-register">

                <div class="tx-register-head">

                    <div class="tx-register-title">

                        <i class="fas fa-list-ul"></i>

                        Transaction Register

                        <span class="tx-count">
                            <?= (int)$total_invoices ?>
                        </span>

                    </div>

                    <div class="tx-register-date">
                        <?= tx_e($display_date) ?>
                    </div>

                </div>


                <div class="tx-table-wrap">

                    <table class="report-table">

                        <thead>

                            <tr>

                                <th class="ps-3">
                                    Invoice
                                </th>

                                <th>
                                    Medicines Sold
                                </th>

                                <th>
                                    Payment
                                </th>

                                <th>
                                    Time
                                </th>

                                <th>
                                    Handled By
                                </th>

                                <th class="text-end">
                                    Total (ZMW)
                                </th>

                                <th
                                    class="text-center no-print"
                                    style="width:95px;"
                                >
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php if (!empty($sales_data)): ?>

                            <?php foreach ($sales_data as $row): ?>

                                <?php

                                $saleId = (int)(
                                    $row['id'] ?? 0
                                );

                                $invoiceNumber =
                                    $row['invoice'] ?? '';

                                $itemsSold =
                                    $row['items_sold']
                                    ?: 'No items recorded';

                                $paymentMethod =
                                    $row['payment_method']
                                    ?: 'Cash';

                                $createdAt =
                                    $row['created_at']
                                    ?? '';

                                $timeDisplay = 'N/A';

                                if ($createdAt !== '') {

                                    $timestamp =
                                        strtotime($createdAt);

                                    if ($timestamp !== false) {
                                        $timeDisplay =
                                            date(
                                                'h:i A',
                                                $timestamp
                                            );
                                    }
                                }

                                $issuer =
                                    $row['issuer']
                                    ?: 'System';

                                $saleTotal = (float)(
                                    $row['total']
                                    ?? $row['total_amount']
                                    ?? 0
                                );

                                $paymentLower =
                                    strtolower(
                                        trim(
                                            $paymentMethod
                                        )
                                    );

                                $paymentClass = 'cash';
                                $paymentIcon =
                                    'fa-money-bill-wave';
                                $paymentLabel = 'Cash';

                                if (
                                    $paymentLower === 'card'
                                ) {

                                    $paymentClass = 'card';
                                    $paymentIcon =
                                        'fa-credit-card';
                                    $paymentLabel = 'Card';

                                } elseif (
                                    in_array(
                                        $paymentLower,
                                        [
                                            'mobile',
                                            'mobile money',
                                            'momo'
                                        ],
                                        true
                                    )
                                ) {

                                    $paymentClass = 'mobile';
                                    $paymentIcon =
                                        'fa-mobile-alt';
                                    $paymentLabel =
                                        'Mobile Money';

                                } else {

                                    $paymentLabel =
                                        $paymentMethod;
                                }

                                $cashierInitial =
                                    strtoupper(
                                        substr(
                                            trim($issuer),
                                            0,
                                            1
                                        )
                                    );

                                ?>

                                <tr>

                                    <td class="ps-3">

                                        <div class="tx-invoice">
                                            #<?= tx_e(
                                                $invoiceNumber
                                            ) ?>
                                        </div>

                                        <div class="tx-sale-id">
                                            Sale ID <?= $saleId ?>
                                        </div>

                                    </td>


                                    <td>

                                        <div class="tx-items">
                                            <?= tx_e(
                                                $itemsSold
                                            ) ?>
                                        </div>

                                    </td>


                                    <td>

                                        <span
                                            class="tx-method <?= tx_e(
                                                $paymentClass
                                            ) ?>"
                                        >

                                            <i
                                                class="fas <?= tx_e(
                                                    $paymentIcon
                                                ) ?>"
                                            ></i>

                                            <?= tx_e(
                                                $paymentLabel
                                            ) ?>

                                        </span>

                                    </td>


                                    <td class="text-nowrap">
                                        <?= tx_e(
                                            $timeDisplay
                                        ) ?>
                                    </td>


                                    <td>

                                        <div class="tx-cashier">

                                            <div class="tx-avatar">
                                                <?= tx_e(
                                                    $cashierInitial
                                                ) ?>
                                            </div>

                                            <span>
                                                <?= tx_e(
                                                    $issuer
                                                ) ?>
                                            </span>

                                        </div>

                                    </td>


                                    <td class="text-end">

                                        <span class="tx-total">
                                            K<?= number_format(
                                                $saleTotal,
                                                2
                                            ) ?>
                                        </span>

                                    </td>


                                    <td class="text-center no-print">

                                        <div class="tx-actions">

                                            <a
                                                href="view_invoice.php?id=<?= $saleId ?>"
                                                class="tx-action view"
                                                title="View invoice"
                                                aria-label="View invoice"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </a>

                                            <a
                                                href="view_invoice.php?id=<?= $saleId ?>"
                                                class="tx-action print"
                                                title="Reprint invoice"
                                                aria-label="Reprint invoice"
                                            >
                                                <i class="fas fa-print"></i>
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td
                                    colspan="7"
                                    class="tx-empty"
                                >

                                    <div class="tx-empty-icon">
                                        <i class="fas fa-receipt"></i>
                                    </div>

                                    <div class="tx-empty-title">
                                        No transactions recorded
                                    </div>

                                    <div class="tx-empty-text">
                                        There are no completed sales for
                                        <?= tx_e($display_date) ?>.
                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>

<?php

if (
    file_exists(
        "../includes/footer.php"
    )
) {
    require_once "../includes/footer.php";
}

?>

<script>
document.title = 'Today\'s Transactions';

(function () {


    const searchInput =
        document.querySelector(
            'input[name="search"]'
        );

    if (searchInput) {

        searchInput.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key === 'Enter'
                ) {
                    event.preventDefault();

                    const form =
                        searchInput.closest(
                            'form'
                        );

                    if (form) {
                        form.submit();
                    }
                }

            }
        );

    }

})();
</script>

</body>
</html>
