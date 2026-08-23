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
| Page
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>

<style>
:root{
    --pos-blue:#1769e0;
    --pos-blue-dark:#0f56bd;
    --pos-green:#169b62;
    --pos-ink:#172033;
    --pos-muted:#748096;
    --pos-border:#e6eaf0;
    --pos-bg:#f5f7fb;
    --pos-white:#fff;
    --pos-shadow:0 8px 30px rgba(22,34,55,.055);
}

.report-wrapper{
    min-height:calc(100vh - 65px);
    padding:26px;
    background:var(--pos-bg)!important;
    color:var(--pos-ink);
}

.tx-shell{
    max-width:1500px;
    margin:0 auto;
}

/* Header */
.tx-hero{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}
.tx-brand{
    display:flex;
    align-items:center;
    gap:14px;
}
.tx-brand-icon{
    width:50px;height:50px;
    display:grid;place-items:center;
    border-radius:14px;
    background:linear-gradient(145deg,#eaf3ff,#dcecff);
    color:var(--pos-blue);
    font-size:20px;
    box-shadow:inset 0 0 0 1px #d5e5fb;
}
.tx-kicker{
    margin-bottom:3px;
    color:var(--pos-blue);
    font-size:10px;
    font-weight:800;
    letter-spacing:1px;
    text-transform:uppercase;
}
.tx-title{
    margin:0;
    font-size:25px;
    line-height:1.15;
    font-weight:800;
    color:var(--pos-ink);
}
.tx-subtitle{
    margin-top:5px;
    color:var(--pos-muted);
    font-size:12px;
}
.tx-date{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border:1px solid var(--pos-border);
    border-radius:11px;
    background:#fff;
    color:#566175;
    font-size:12px;
    font-weight:700;
    box-shadow:0 3px 12px rgba(22,34,55,.025);
}

/* Filters */
.tx-filter{
    padding:18px;
    margin-bottom:20px;
    border:1px solid var(--pos-border);
    border-radius:15px;
    background:#fff;
    box-shadow:var(--pos-shadow);
}
.tx-label{
    display:block;
    margin:0 0 7px;
    color:#69758a;
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
    color:#9aa5b5;
    pointer-events:none;
}
.tx-search input{
    height:43px;
    padding-left:38px;
    border:1px solid #dce2ea!important;
    border-radius:10px!important;
    background:#fbfcfe!important;
    box-shadow:none!important;
}
.tx-filter select,
.tx-filter input[type=date]{
    height:43px;
    border:1px solid #dce2ea!important;
    border-radius:10px!important;
    background:#fbfcfe!important;
    box-shadow:none!important;
}
.tx-filter .btn{
    height:43px;
    border-radius:10px;
    font-size:12px;
    font-weight:700;
}
.tx-filter-help{
    margin-top:7px;
    color:#9aa4b2;
    font-size:10px;
}

/* KPI */
.tx-kpis{
    display:grid;
    grid-template-columns:1.3fr 1fr 1fr;
    gap:14px;
    margin-bottom:20px;
}
.tx-kpi{
    position:relative;
    overflow:hidden;
    padding:18px;
    border:1px solid var(--pos-border);
    border-radius:15px;
    background:#fff;
    box-shadow:var(--pos-shadow);
}
.tx-kpi.primary{
    background:linear-gradient(135deg,#1769e0,#135bc3);
    border-color:#1769e0;
    color:#fff;
}
.tx-kpi.primary .tx-kpi-label,
.tx-kpi.primary .tx-kpi-note{color:rgba(255,255,255,.76)}
.tx-kpi.primary .tx-kpi-value{color:#fff}
.tx-kpi-icon{
    position:absolute;
    right:17px;top:17px;
    width:38px;height:38px;
    display:grid;place-items:center;
    border-radius:11px;
    background:#eef4ff;
    color:var(--pos-blue);
}
.tx-kpi.primary .tx-kpi-icon{
    background:rgba(255,255,255,.16);
    color:#fff;
}
.tx-kpi-label{
    color:#7a8597;
    font-size:9px;
    font-weight:800;
    letter-spacing:.7px;
    text-transform:uppercase;
}
.tx-kpi-value{
    margin-top:8px;
    color:var(--pos-ink);
    font-size:25px;
    font-weight:850;
}
.tx-kpi-note{
    margin-top:4px;
    color:#9aa4b2;
    font-size:10px;
}

/* Table */
.tx-card{
    overflow:hidden;
    border:1px solid var(--pos-border);
    border-radius:16px;
    background:#fff;
    box-shadow:var(--pos-shadow);
}
.tx-card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:17px 19px;
    border-bottom:1px solid var(--pos-border);
}
.tx-card-title{
    display:flex;
    align-items:center;
    gap:9px;
    color:var(--pos-ink);
    font-size:14px;
    font-weight:800;
}
.tx-count{
    min-width:27px;
    padding:4px 8px;
    border-radius:20px;
    background:#edf4ff;
    color:var(--pos-blue);
    text-align:center;
    font-size:10px;
    font-weight:800;
}
.tx-card-meta{
    color:#8a95a5;
    font-size:11px;
}
.tx-table-wrap{overflow-x:auto}
.report-table{
    width:100%;
    min-width:980px;
    border-collapse:collapse;
}
.report-table thead th{
    padding:12px 16px;
    background:#fafbfc;
    border-bottom:1px solid var(--pos-border);
    color:#7c8798!important;
    font-size:9px;
    font-weight:800;
    letter-spacing:.65px;
    text-transform:uppercase;
    white-space:nowrap;
}
.report-table tbody td{
    padding:14px 16px;
    border-bottom:1px solid #edf0f4;
    color:#414c5d;
    font-size:12px;
    vertical-align:middle;
}
.report-table tbody tr{
    transition:background .15s ease;
}
.report-table tbody tr:hover{background:#fbfdff}
.report-table tbody tr:last-child td{border-bottom:0}

.tx-invoice{
    color:var(--pos-blue);
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}
.tx-sale-id{
    margin-top:3px;
    color:#a0a9b7;
    font-size:9px;
}
.tx-items{
    max-width:390px;
    color:#4f5a6b;
    line-height:1.45;
}
.tx-cashier{
    display:flex;
    align-items:center;
    gap:8px;
}
.tx-avatar{
    width:29px;height:29px;
    display:grid;place-items:center;
    border-radius:9px;
    background:#f0f3f7;
    color:#637084;
    font-size:11px;
    font-weight:800;
}
.tx-method{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 9px;
    border-radius:8px;
    font-size:9px;
    font-weight:800;
    white-space:nowrap;
}
.tx-method.cash{background:#fff5df;color:#9a6909}
.tx-method.card{background:#edf4ff;color:#356bc8}
.tx-method.mobile{background:#eaf8f2;color:#16865a}
.tx-total{
    color:var(--pos-green)!important;
    font-size:13px;
    font-weight:850!important;
    white-space:nowrap;
}
.tx-actions{
    display:flex;
    justify-content:center;
    gap:6px;
}
.tx-action{
    width:34px;height:34px;
    display:grid;
    place-items:center;
    border:1px solid #dfe5ec;
    border-radius:9px;
    background:#fff;
    text-decoration:none;
    transition:all .15s ease;
}
.tx-action.view{color:var(--pos-blue)}
.tx-action.print{color:var(--pos-green)}
.tx-action:hover{
    transform:translateY(-1px);
    box-shadow:0 4px 11px rgba(0,0,0,.08);
}
.tx-empty{
    padding:65px 20px!important;
    text-align:center;
}
.tx-empty-icon{
    width:58px;height:58px;
    margin:0 auto 13px;
    display:grid;place-items:center;
    border-radius:16px;
    background:#f1f4f8;
    color:#8d98a8;
    font-size:20px;
}
.tx-empty-title{color:#4a5568;font-weight:800}
.tx-empty-text{margin-top:4px;color:#9aa4b2;font-size:11px}

/* Responsive */
@media(max-width:1000px){
    .tx-kpis{grid-template-columns:1fr 1fr}
    .tx-kpi.primary{grid-column:1/-1}
}
@media(max-width:768px){
    .report-wrapper{padding:16px}
    .tx-hero{align-items:flex-start;flex-direction:column}
    .tx-date{width:max-content}
    .tx-kpis{grid-template-columns:1fr}
    .tx-kpi.primary{grid-column:auto}
}
@media(max-width:576px){
    .tx-filter{padding:14px}
    .tx-filter .row{row-gap:12px!important}
}

/* Print */
@media print{
    @page{size:A4;margin:10mm}
    html,body{
        width:100%;
        height:auto!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }
    .no-print,.topbar,.left-sidebar,nav,footer,.tx-filter{
        display:none!important;
    }
    #main-wrapper,.page-wrapper,.report-wrapper{
        width:100%!important;
        min-height:auto!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }
    .tx-card,.tx-kpi{
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

        <div class="tx-shell">

            <!-- =================================================
                 HERO
            ================================================== -->

            <div class="tx-hero">

                <div class="tx-brand">

                    <div class="tx-brand-icon">
                        <i class="fas fa-receipt"></i>
                    </div>

                    <div>

                        <div class="tx-kicker">
                            Sales Management
                        </div>

                        <h1 class="tx-title">
                            Today's Transactions
                        </h1>

                        <div class="tx-subtitle">
                            <?= tx_e(
                                strtoupper($display_pharm)
                            ) ?>
                            <span class="mx-1">â€¢</span>
                            <?= tx_e($display_bran) ?>
                        </div>

                    </div>

                </div>

                <div class="tx-date">
                    <i class="far fa-calendar-alt text-primary"></i>
                    <?= tx_e($display_date) ?>
                </div>

            </div>


            <!-- =================================================
                 FILTERS
            ================================================== -->

            <div class="tx-filter no-print">

                <form method="GET" autocomplete="off">

                    <div class="row g-3 align-items-end">

                        <div class="col-xl-5 col-lg-5 col-md-12">

                            <label
                                class="tx-label"
                                for="txSearch"
                            >
                                Search
                            </label>

                            <div class="tx-search">

                                <i class="fas fa-search"></i>

                                <input
                                    id="txSearch"
                                    type="search"
                                    name="search"
                                    class="form-control"
                                    value="<?= tx_e($search) ?>"
                                    placeholder="Invoice, medicine, cashier or transaction reference..."
                                >

                            </div>

                            <div class="tx-filter-help">
                                Find any transaction without changing the selected date.
                            </div>

                        </div>


                        <div class="col-xl-2 col-lg-2 col-md-4">

                            <label
                                class="tx-label"
                                for="txDate"
                            >
                                Transaction Date
                            </label>

                            <input
                                id="txDate"
                                type="date"
                                name="filter_date"
                                class="form-control"
                                value="<?= tx_e($filter_date) ?>"
                            >

                        </div>


                        <div class="col-xl-2 col-lg-2 col-md-4">

                            <label
                                class="tx-label"
                                for="txMethod"
                            >
                                Payment Method
                            </label>

                            <select
                                id="txMethod"
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
                                    value="Mobile"
                                    <?= $filter_method === 'Mobile'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Mobile Money
                                </option>

                                <option
                                    value="Card"
                                    <?= $filter_method === 'Card'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Card
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
                 KPI CARDS
            ================================================== -->

            <div class="tx-kpis">

                <div class="tx-kpi primary">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-wallet"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Today's Revenue
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
                        <span class="mx-1">â€¢</span>
                        <?= (int)$total_invoices ?> invoice(s)
                    </div>

                </div>


                <div class="tx-kpi">

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
                        Completed sales matching current filters
                    </div>

                </div>


                <div class="tx-kpi">

                    <div class="tx-kpi-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>

                    <div class="tx-kpi-label">
                        Business Date
                    </div>

                    <div class="tx-kpi-value" style="font-size:20px;">
                        <?= tx_e($display_date) ?>
                    </div>

                    <div class="tx-kpi-note">
                        Branch: <?= tx_e($display_bran) ?>
                    </div>

                </div>

            </div>


            <!-- =================================================
                 TRANSACTION TABLE
            ================================================== -->

            <div class="tx-card">

                <div class="tx-card-head">

                    <div class="tx-card-title">

                        <i class="fas fa-list-ul text-primary"></i>

                        Transaction Register

                        <span class="tx-count">
                            <?= (int)$total_invoices ?>
                        </span>

                    </div>

                    <div class="tx-card-meta">
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
                                    Cashier
                                </th>

                                <th class="text-end">
                                    Total
                                </th>

                                <th
                                    class="text-center no-print"
                                    style="width:95px;"
                                >
                                    Actions
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
                                        trim($paymentMethod)
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
                                            'momo',
                                            'mobile money'
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
                                        <span class="fw-semibold">
                                            <?= tx_e(
                                                $timeDisplay
                                            ) ?>
                                        </span>
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
                                        No transactions found
                                    </div>

                                    <div class="tx-empty-text">
                                        Try another date, payment method,
                                        or search term.
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
