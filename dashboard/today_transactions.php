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
   TRANSACTION HISTORY
========================================================= */

.report-wrapper {
    background-color: #ffffff !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.transaction-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 24px;
}

.transaction-heading h3 {
    margin: 0;
}

.transaction-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.transaction-search {
    min-width: 260px;
}

.transaction-search input {
    min-width: 260px;
}

.stat-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 1.25rem;
}

.stat-card-cyan {
    border-left: 4px solid #0d6efd;
}

.stat-card-orange {
    border-left: 4px solid #ffc107;
}

.report-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead th {
    background: #f1f3f5;
    padding: 14px;
    text-align: left;
    font-size: 12px;
    color: #495057 !important;
    text-transform: uppercase;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}

.report-table tbody td {
    padding: 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: #f8f9fa;
}

.invoice-number {
    white-space: nowrap;
}

.item-summary {
    min-width: 220px;
    max-width: 420px;
}

.transaction-actions {
    display: flex;
    justify-content: center;
    gap: 5px;
    white-space: nowrap;
}

.transaction-actions .btn {
    min-width: 36px;
}

.search-help {
    font-size: 11px;
    color: #6c757d;
    margin-top: 4px;
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 900px) {

    .transaction-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .transaction-filters {
        width: 100%;
    }

    .transaction-search {
        flex: 1;
        min-width: 220px;
    }

    .transaction-search input {
        width: 100%;
        min-width: 0;
    }
}

@media (max-width: 576px) {

    .report-wrapper {
        padding: 1rem;
    }

    .transaction-filters {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .transaction-search {
        grid-column: 1 / -1;
        min-width: 0;
    }

    .transaction-search input {
        width: 100%;
    }

    .transaction-filters .btn {
        width: 100%;
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
        background: #ffffff !important;
    }

    .no-print,
    .topbar,
    .left-sidebar,
    nav,
    footer {
        display: none !important;
    }

    #main-wrapper,
    .page-wrapper,
    .report-wrapper {
        width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .report-table-container {
        border: 1px solid #ccc !important;
    }

    .stat-card {
        border: 1px solid #ccc !important;
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
                 HEADER + FILTERS
            ================================================== -->

            <div class="transaction-toolbar">

                <div class="transaction-heading">

                    <h3 class="fw-bold text-dark mb-0">
                        <?= tx_e(
                            strtoupper(
                                $display_pharm
                            )
                        ) ?>
                    </h3>

                    <span class="text-muted small">
                        Branch:
                        <b>
                            <?= tx_e(
                                $display_bran
                            ) ?>
                        </b>

                        |

                        Date:
                        <b class="text-dark">
                            <?= tx_e(
                                $display_date
                            ) ?>
                        </b>
                    </span>

                </div>


                <div class="no-print">

                    <form
                        method="GET"
                        class="transaction-filters"
                        autocomplete="off"
                    >

                        <!-- SEARCH -->

                        <div class="transaction-search">

                            <input
                                type="search"
                                name="search"
                                class="form-control form-control-sm bg-light text-dark border-secondary"
                                value="<?= tx_e($search) ?>"
                                placeholder="Search invoice, medicine, cashier..."
                                aria-label="Search transactions"
                            >

                        </div>


                        <!-- DATE -->

                        <input
                            type="date"
                            name="filter_date"
                            class="form-control form-control-sm bg-light text-dark border-secondary"
                            value="<?= tx_e($filter_date) ?>"
                            aria-label="Transaction date"
                        >


                        <!-- PAYMENT -->

                        <select
                            name="filter_method"
                            class="form-select form-select-sm bg-light text-dark border-secondary"
                            aria-label="Payment method"
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
                                Method: All
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


                        <!-- APPLY -->

                        <button
                            type="submit"
                            class="btn btn-primary btn-sm px-3"
                        >
                            <i class="fas fa-search me-1"></i>
                            Search
                        </button>


                        <!-- CLEAR -->

                        <?php if (
                            $search !== '' ||
                            $filter_method !== 'All' ||
                            $filter_date !== $today
                        ): ?>

                            <a
                                href="today_transactions.php"
                                class="btn btn-outline-secondary btn-sm px-3"
                            >
                                <i class="fas fa-times me-1"></i>
                                Clear
                            </a>

                        <?php endif; ?>


                        <!-- PRINT -->

                        <button
                            type="button"
                            class="btn btn-outline-dark btn-sm px-3"
                            onclick="window.print()"
                        >
                            <i class="fas fa-print me-1"></i>
                            Print
                        </button>

                    </form>

                    <div class="search-help">
                        Search by invoice, transaction reference,
                        cashier or medicine.
                    </div>

                </div>

            </div>


            <!-- =================================================
                 SUMMARY
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-md-3">

                    <div class="stat-card stat-card-cyan">

                        <div class="small fw-bold text-uppercase text-muted">
                            Revenue
                            (<?= tx_e($filter_method) ?>)
                        </div>

                        <div class="h2 mb-0 fw-bold text-primary">
                            K<?= number_format(
                                $total_revenue,
                                2
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="stat-card stat-card-orange">

                        <div class="small fw-bold text-uppercase text-muted">
                            Total Invoices
                        </div>

                        <div class="h2 mb-0 fw-bold text-warning">
                            <?= (int)$total_invoices ?>
                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 TRANSACTIONS TABLE
            ================================================== -->

            <div class="report-table-container">

                <div class="table-responsive">

                    <table class="report-table">

                        <thead>

                            <tr>

                                <th class="ps-3">
                                    Invoice #
                                </th>

                                <th>
                                    Medicines Sold
                                </th>

                                <th>
                                    Method
                                </th>

                                <th>
                                    Time
                                </th>

                                <th>
                                    Handled By
                                </th>

                                <th class="text-end pe-3">
                                    Total (ZMW)
                                </th>

                                <th class="text-center no-print">
                                    Action
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

                                ?>

                                <tr>

                                    <!-- INVOICE -->

                                    <td class="ps-3 invoice-number">

                                        <div class="fw-bold text-primary">
                                            #<?= tx_e(
                                                $invoiceNumber
                                            ) ?>
                                        </div>

                                    </td>


                                    <!-- ITEMS -->

                                    <td>

                                        <div class="item-summary">
                                            <?= tx_e(
                                                $itemsSold
                                            ) ?>
                                        </div>

                                    </td>


                                    <!-- PAYMENT -->

                                    <td>

                                        <?php

                                        $paymentLower =
                                            strtolower(
                                                trim(
                                                    $paymentMethod
                                                )
                                            );

                                        $paymentLabel =
                                            $paymentMethod;

                                        if (
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
                                            $paymentLabel =
                                                'Mobile Money';
                                        }

                                        ?>

                                        <span class="badge bg-secondary">
                                            <?= tx_e(
                                                $paymentLabel
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- TIME -->

                                    <td>
                                        <?= tx_e(
                                            $timeDisplay
                                        ) ?>
                                    </td>


                                    <!-- CASHIER -->

                                    <td>
                                        <?= tx_e(
                                            $issuer
                                        ) ?>
                                    </td>


                                    <!-- TOTAL -->

                                    <td class="text-end pe-3 fw-bold text-success">

                                        K<?= number_format(
                                            $saleTotal,
                                            2
                                        ) ?>

                                    </td>


                                    <!-- ACTIONS -->

                                    <td class="text-center no-print">

                                        <div class="transaction-actions">

                                            <!-- VIEW -->

                                            <a
                                                href="view_invoice.php?id=<?= $saleId ?>"
                                                class="btn btn-sm btn-light border text-primary"
                                                title="View invoice"
                                                aria-label="View invoice"
                                            >
                                                <i class="fas fa-eye"></i>
                                            </a>


                                            <!-- REPRINT -->

                                            <a
                                                href="view_invoice.php?id=<?= $saleId ?>"
                                                class="btn btn-sm btn-light border text-success"
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
                                    class="text-center py-5 text-muted"
                                >

                                    <div class="mb-2">
                                        <i
                                            class="fas fa-receipt fa-2x"
                                        ></i>
                                    </div>

                                    <div class="fw-bold">
                                        No transactions found
                                    </div>

                                    <div class="small mt-1">
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
