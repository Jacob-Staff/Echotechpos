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

/* =========================================================
   ECHOTECH POS â€” TRANSACTION HISTORY UI
========================================================= */

:root {
    --tx-primary: #0d6efd;
    --tx-success: #13a66a;
    --tx-dark: #172331;
    --tx-muted: #718096;
    --tx-border: #e7ebf0;
    --tx-soft: #f5f7fa;
    --tx-card: #ffffff;
}

.report-wrapper {
    min-height: calc(100vh - 70px);
    padding: 26px;
    background: #f4f7fb !important;
    color: #172331;
}

/* =========================================================
   PAGE HEADER
========================================================= */

.tx-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 22px;
}

.tx-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.tx-title-icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #e9f2ff;
    color: var(--tx-primary);
    font-size: 20px;
}

.tx-title h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -.3px;
    color: var(--tx-dark);
}

.tx-subtitle {
    margin-top: 4px;
    color: var(--tx-muted);
    font-size: 13px;
}

.tx-date-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 13px;
    border: 1px solid var(--tx-border);
    border-radius: 10px;
    background: #fff;
    color: #4a5568;
    font-size: 12px;
    font-weight: 700;
}

/* =========================================================
   FILTER PANEL
========================================================= */

.tx-filter-card {
    background: var(--tx-card);
    border: 1px solid var(--tx-border);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 5px 20px rgba(23, 35, 49, .045);
}

.tx-filter-label {
    display: block;
    margin-bottom: 7px;
    color: #596579;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.tx-search-box {
    position: relative;
}

.tx-search-box i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a0b2;
    pointer-events: none;
}

.tx-search-box input {
    height: 43px;
    padding-left: 39px;
    border-color: #dce2ea !important;
    border-radius: 10px !important;
    background: #fbfcfe !important;
}

.tx-filter-card select,
.tx-filter-card input[type="date"] {
    height: 43px;
    border-color: #dce2ea !important;
    border-radius: 10px !important;
    background: #fbfcfe !important;
}

.tx-filter-card .btn {
    height: 43px;
    border-radius: 10px;
    font-weight: 700;
}

.tx-search-help {
    margin-top: 8px;
    color: #8a95a5;
    font-size: 11px;
}

.tx-filter-actions {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}

/* =========================================================
   SUMMARY CARDS
========================================================= */

.tx-stat-card {
    position: relative;
    overflow: hidden;
    height: 100%;
    padding: 18px;
    border: 1px solid var(--tx-border);
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(23, 35, 49, .04);
}

.tx-stat-card::after {
    content: "";
    position: absolute;
    width: 90px;
    height: 90px;
    right: -32px;
    bottom: -38px;
    border-radius: 50%;
    background: rgba(13, 110, 253, .055);
}

.tx-stat-card.success::after {
    background: rgba(19, 166, 106, .065);
}

.tx-stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.tx-stat-label {
    color: #788496;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.tx-stat-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #eaf3ff;
    color: var(--tx-primary);
}

.tx-stat-card.success .tx-stat-icon {
    background: #e8f8f1;
    color: var(--tx-success);
}

.tx-stat-value {
    margin-top: 11px;
    color: var(--tx-dark);
    font-size: 24px;
    font-weight: 850;
}

.tx-stat-meta {
    margin-top: 4px;
    color: #98a2b1;
    font-size: 11px;
}

/* =========================================================
   TABLE CARD
========================================================= */

.tx-table-card {
    overflow: hidden;
    border: 1px solid var(--tx-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(23, 35, 49, .045);
}

.tx-table-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 17px 20px;
    border-bottom: 1px solid var(--tx-border);
}

.tx-table-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--tx-dark);
    font-size: 14px;
    font-weight: 800;
}

.tx-count-badge {
    min-width: 27px;
    padding: 4px 8px;
    border-radius: 20px;
    background: #eef4ff;
    color: var(--tx-primary);
    text-align: center;
    font-size: 11px;
    font-weight: 800;
}

.tx-table-wrap {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    min-width: 950px;
    border-collapse: collapse;
}

.report-table thead th {
    padding: 13px 16px;
    background: #fafbfc;
    border-bottom: 1px solid var(--tx-border);
    color: #7b8798 !important;
    font-size: 9px;
    font-weight: 850;
    letter-spacing: .65px;
    text-transform: uppercase;
    white-space: nowrap;
}

.report-table tbody td {
    padding: 15px 16px;
    border-bottom: 1px solid #edf0f4;
    color: #364152;
    font-size: 13px;
    vertical-align: middle;
}

.report-table tbody tr {
    transition: background .15s ease;
}

.report-table tbody tr:hover {
    background: #fbfdff;
}

.report-table tbody tr:last-child td {
    border-bottom: none;
}

.tx-invoice {
    color: var(--tx-primary);
    font-weight: 800;
    white-space: nowrap;
}

.tx-invoice-sub {
    margin-top: 3px;
    color: #9aa4b2;
    font-size: 10px;
}

.tx-items {
    max-width: 390px;
    color: #4b5565;
    line-height: 1.5;
}

.tx-method {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 8px;
    background: #f1f4f8;
    color: #566173;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.tx-method.card {
    background: #eef4ff;
    color: #376bc6;
}

.tx-method.mobile {
    background: #eaf9f3;
    color: #16865a;
}

.tx-method.cash {
    background: #fff6e5;
    color: #a66b00;
}

.tx-total {
    color: var(--tx-success) !important;
    font-weight: 850 !important;
    white-space: nowrap;
}

.tx-actions {
    display: flex;
    justify-content: center;
    gap: 7px;
    white-space: nowrap;
}

.tx-action {
    width: 35px;
    height: 35px;
    display: grid;
    place-items: center;
    border: 1px solid #dfe5ec;
    border-radius: 9px;
    background: #fff;
    text-decoration: none;
    transition: all .15s ease;
}

.tx-action.view {
    color: var(--tx-primary);
}

.tx-action.print {
    color: var(--tx-success);
}

.tx-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,0,0,.07);
}

.tx-empty {
    padding: 65px 20px !important;
    text-align: center;
}

.tx-empty-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 13px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    background: #f1f4f8;
    color: #8c97a7;
    font-size: 21px;
}

.tx-empty-title {
    color: #4a5568;
    font-weight: 800;
}

.tx-empty-text {
    margin-top: 4px;
    color: #9aa4b2;
    font-size: 12px;
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 992px) {

    .report-wrapper {
        padding: 20px;
    }

    .tx-page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .tx-date-pill {
        width: fit-content;
    }
}

@media (max-width: 768px) {

    .report-wrapper {
        padding: 15px;
    }

    .tx-title h2 {
        font-size: 20px;
    }

    .tx-filter-card {
        padding: 14px;
    }

    .tx-filter-actions {
        align-items: stretch;
    }

    .tx-filter-actions .btn {
        flex: 1;
    }

    .tx-stat-value {
        font-size: 21px;
    }
}

@media (max-width: 576px) {

    .tx-title-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
    }

    .tx-filter-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .tx-filter-actions .btn:last-child {
        grid-column: 1 / -1;
    }
}

/* =========================================================
   PRINT
========================================================= */

@media print {

    @page {
        size: A4;
        margin: 10mm;
    }

    html,
    body {
        width: 100%;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .no-print,
    .topbar,
    .left-sidebar,
    nav,
    footer,
    .tx-filter-card {
        display: none !important;
    }

    #main-wrapper,
    .page-wrapper,
    .report-wrapper {
        width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .tx-table-card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
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

        <div class="container-fluid p-0">

            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div class="tx-page-header">

                <div class="tx-title-wrap">

                    <div class="tx-title-icon">
                        <i class="fas fa-receipt"></i>
                    </div>

                    <div class="tx-title">

                        <h2>
                            Transaction History
                        </h2>

                        <div class="tx-subtitle">
                            <?= tx_e(
                                strtoupper(
                                    $display_pharm
                                )
                            ) ?>

                            <span class="mx-1">â€¢</span>

                            <?= tx_e(
                                $display_bran
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="tx-date-pill">

                    <i class="far fa-calendar-alt"></i>

                    <?= tx_e(
                        $display_date
                    ) ?>

                </div>

            </div>


            <!-- =================================================
                 FILTER PANEL
            ================================================== -->

            <div class="tx-filter-card no-print">

                <form
                    method="GET"
                    autocomplete="off"
                >

                    <div class="row g-3 align-items-end">

                        <!-- SEARCH -->

                        <div class="col-lg-5 col-md-6">

                            <label
                                class="tx-filter-label"
                                for="txSearch"
                            >
                                Search Transactions
                            </label>

                            <div class="tx-search-box">

                                <i class="fas fa-search"></i>

                                <input
                                    id="txSearch"
                                    type="search"
                                    name="search"
                                    class="form-control text-dark"
                                    value="<?= tx_e(
                                        $search
                                    ) ?>"
                                    placeholder="Invoice, medicine, cashier or reference..."
                                    autocomplete="off"
                                >

                            </div>

                            <div class="tx-search-help">
                                Search by invoice, transaction reference,
                                cashier or medicine.
                            </div>

                        </div>


                        <!-- DATE -->

                        <div class="col-lg-2 col-md-3">

                            <label
                                class="tx-filter-label"
                                for="txDate"
                            >
                                Date
                            </label>

                            <input
                                id="txDate"
                                type="date"
                                name="filter_date"
                                class="form-control text-dark"
                                value="<?= tx_e(
                                    $filter_date
                                ) ?>"
                            >

                        </div>


                        <!-- METHOD -->

                        <div class="col-lg-2 col-md-3">

                            <label
                                class="tx-filter-label"
                                for="txMethod"
                            >
                                Payment Method
                            </label>

                            <select
                                id="txMethod"
                                name="filter_method"
                                class="form-select text-dark"
                            >

                                <option
                                    value="All"
                                    <?= (
                                        $filter_method === 'All'
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    All Methods
                                </option>

                                <option
                                    value="Cash"
                                    <?= (
                                        $filter_method === 'Cash'
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Cash
                                </option>

                                <option
                                    value="Mobile"
                                    <?= (
                                        $filter_method === 'Mobile'
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Mobile Money
                                </option>

                                <option
                                    value="Card"
                                    <?= (
                                        $filter_method === 'Card'
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Card
                                </option>

                            </select>

                        </div>


                        <!-- ACTIONS -->

                        <div class="col-lg-3 col-md-12">

                            <div class="tx-filter-actions">

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    <i class="fas fa-filter me-1"></i>
                                    Apply Filters
                                </button>


                                <?php if (
                                    $search !== '' ||
                                    $filter_method !== 'All' ||
                                    $filter_date !== $today
                                ): ?>

                                    <a
                                        href="today_transactions.php"
                                        class="btn btn-light border"
                                    >
                                        <i class="fas fa-rotate-left me-1"></i>
                                        Reset
                                    </a>

                                <?php endif; ?>


                                <button
                                    type="button"
                                    class="btn btn-outline-dark"
                                    onclick="window.print()"
                                >
                                    <i class="fas fa-print me-1"></i>
                                    Print
                                </button>

                            </div>

                        </div>

                    </div>

                </form>

            </div>


            <!-- =================================================
                 SUMMARY CARDS
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-md-6 col-xl-4">

                    <div class="tx-stat-card success">

                        <div class="tx-stat-top">

                            <div class="tx-stat-label">
                                Filtered Revenue
                            </div>

                            <div class="tx-stat-icon">
                                <i class="fas fa-wallet"></i>
                            </div>

                        </div>

                        <div class="tx-stat-value">
                            K<?= number_format(
                                $total_revenue,
                                2
                            ) ?>
                        </div>

                        <div class="tx-stat-meta">
                            <?= tx_e(
                                $filter_method === 'All'
                                    ? 'All payment methods'
                                    : $filter_method
                            ) ?>
                            â€¢
                            <?= tx_e(
                                $display_date
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-6 col-xl-4">

                    <div class="tx-stat-card">

                        <div class="tx-stat-top">

                            <div class="tx-stat-label">
                                Transactions
                            </div>

                            <div class="tx-stat-icon">
                                <i class="fas fa-receipt"></i>
                            </div>

                        </div>

                        <div class="tx-stat-value">
                            <?= (int)$total_invoices ?>
                        </div>

                        <div class="tx-stat-meta">
                            Matching invoices
                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 TRANSACTION TABLE
            ================================================== -->

            <div class="tx-table-card">

                <div class="tx-table-head">

                    <div class="tx-table-title">

                        <i class="fas fa-list-ul text-primary"></i>

                        Sales

                        <span class="tx-count-badge">
                            <?= (int)$total_invoices ?>
                        </span>

                    </div>

                    <div class="text-muted small">
                        <?= tx_e(
                            $display_date
                        ) ?>
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
                                    style="width:100px;"
                                >
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php if (
                            !empty($sales_data)
                        ): ?>

                            <?php foreach (
                                $sales_data
                                as $row
                            ): ?>

                                <?php

                                $saleId = (int)(
                                    $row['id']
                                    ?? 0
                                );

                                $invoiceNumber =
                                    $row['invoice']
                                    ?? '';

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

                                if (
                                    $createdAt !== ''
                                ) {

                                    $timestamp =
                                        strtotime(
                                            $createdAt
                                        );

                                    if (
                                        $timestamp !== false
                                    ) {

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

                                $paymentClass =
                                    'cash';

                                $paymentIcon =
                                    'fa-money-bill-wave';

                                $paymentLabel =
                                    'Cash';

                                if (
                                    $paymentLower === 'card'
                                ) {

                                    $paymentClass =
                                        'card';

                                    $paymentIcon =
                                        'fa-credit-card';

                                    $paymentLabel =
                                        'Card';

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

                                    $paymentClass =
                                        'mobile';

                                    $paymentIcon =
                                        'fa-mobile-alt';

                                    $paymentLabel =
                                        'Mobile Money';

                                } else {

                                    $paymentLabel =
                                        $paymentMethod;
                                }

                                ?>

                                <tr>

                                    <!-- INVOICE -->

                                    <td class="ps-3">

                                        <div class="tx-invoice">
                                            #<?= tx_e(
                                                $invoiceNumber
                                            ) ?>
                                        </div>

                                        <div class="tx-invoice-sub">
                                            Sale ID:
                                            <?= $saleId ?>
                                        </div>

                                    </td>


                                    <!-- ITEMS -->

                                    <td>

                                        <div class="tx-items">
                                            <?= tx_e(
                                                $itemsSold
                                            ) ?>
                                        </div>

                                    </td>


                                    <!-- PAYMENT -->

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


                                    <!-- TIME -->

                                    <td class="text-nowrap">

                                        <span class="fw-semibold">
                                            <?= tx_e(
                                                $timeDisplay
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- CASHIER -->

                                    <td>
                                        <?= tx_e(
                                            $issuer
                                        ) ?>
                                    </td>


                                    <!-- TOTAL -->

                                    <td class="text-end">

                                        <span class="tx-total">
                                            K<?= number_format(
                                                $saleTotal,
                                                2
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- ACTIONS -->

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
                                        Try a different date,
                                        payment method, or search term.
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
document.title = 'Transaction History';

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
