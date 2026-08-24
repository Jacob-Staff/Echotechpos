<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * EXPIRED PRODUCTS â€” FINAL / SAFE DISPOSAL VERSION
 * ============================================================
 *
 * Zambia POS timezone: Africa/Lusaka
 *
 * DISPOSAL RULE:
 *
 * 1. If an expired store_items row has NEVER been used by
 *    sales_items:
 *       -> DELETE the store_items row completely.
 *
 * 2. If an expired store_items row IS referenced by sales_items:
 *       -> DO NOT DELETE the row, because that can break or
 *          orphan sales history.
 *       -> Set its quantity to 0.
 *       -> The expired stock disappears from this page while
 *          the historical sale remains intact.
 *
 * 3. Every operation is restricted to the logged-in pharmacy
 *    and branch.
 *
 * 4. All discard operations use a transaction.
 * ============================================================
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

if (!isset($_SESSION['pharmacy_id'], $_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

$p_id = (int)$_SESSION['pharmacy_id'];
$b_id = (int)$_SESSION['branch_id'];

if ($p_id <= 0 || $b_id <= 0) {
    die("<div class='alert alert-danger text-center mt-3'>Invalid pharmacy or branch session.</div>");
}

$today = date('Y-m-d');

$success = '';
$error = '';
$discarded_count = 0;
$preserved_sales_count = 0;
$deleted_count = 0;
$zeroed_count = 0;

/* ============================================================
   HELPERS
   ============================================================ */

function expired_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   SAFE SINGLE / BULK DISPOSAL
   ============================================================
   We intentionally do NOT use a blanket DELETE.

   For every expired store_items row:
     - no sales reference -> DELETE
     - sales reference -> quantity = 0

   This preserves sales history.
   ============================================================ */

if (isset($_POST['dispose_product']) || isset($_POST['clear_expired'])) {

    $is_single = isset($_POST['dispose_product']);

    $single_id = 0;

    if ($is_single) {
        $single_id = isset($_POST['product_id'])
            ? (int)$_POST['product_id']
            : 0;

        if ($single_id <= 0) {
            $error = "Invalid expired product selected.";
        }
    }

    if ($error === '') {

        try {
            mysqli_begin_transaction($conn);

            /* ------------------------------------------------
               Find the exact eligible expired rows.
               For single disposal, ownership is enforced here.
               ------------------------------------------------ */

            if ($is_single) {

                $find_sql = "
                    SELECT
                        si.id,
                        si.item_name,
                        si.quantity,
                        si.expiry_date,
                        EXISTS (
                            SELECT 1
                            FROM sales_items sx
                            WHERE sx.product_id = si.id
                        ) AS has_sales
                    FROM store_items si
                    WHERE si.id = ?
                      AND si.pharmacy_id = ?
                      AND si.branch_id = ?
                      AND si.expiry_date < ?
                    LIMIT 1
                ";

                $find_stmt = mysqli_prepare($conn, $find_sql);

                if (!$find_stmt) {
                    throw new Exception("Could not prepare expired product lookup.");
                }

                mysqli_stmt_bind_param(
                    $find_stmt,
                    "iiis",
                    $single_id,
                    $p_id,
                    $b_id,
                    $today
                );

                mysqli_stmt_execute($find_stmt);

                $find_result = mysqli_stmt_get_result($find_stmt);
                $rows = [];

                if ($find_result) {
                    while ($r = mysqli_fetch_assoc($find_result)) {
                        $rows[] = $r;
                    }
                    mysqli_free_result($find_result);
                }

                mysqli_stmt_close($find_stmt);

                if (empty($rows)) {
                    throw new Exception(
                        "The expired item was not found, is already disposed, " .
                        "or does not belong to this branch."
                    );
                }

            } else {

                $find_sql = "
                    SELECT
                        si.id,
                        si.item_name,
                        si.quantity,
                        si.expiry_date,
                        EXISTS (
                            SELECT 1
                            FROM sales_items sx
                            WHERE sx.product_id = si.id
                        ) AS has_sales
                    FROM store_items si
                    WHERE si.pharmacy_id = ?
                      AND si.branch_id = ?
                      AND si.expiry_date < ?
                    ORDER BY si.expiry_date ASC, si.id ASC
                ";

                $find_stmt = mysqli_prepare($conn, $find_sql);

                if (!$find_stmt) {
                    throw new Exception("Could not prepare expired stock lookup.");
                }

                mysqli_stmt_bind_param(
                    $find_stmt,
                    "iis",
                    $p_id,
                    $b_id,
                    $today
                );

                mysqli_stmt_execute($find_stmt);

                $find_result = mysqli_stmt_get_result($find_stmt);
                $rows = [];

                if ($find_result) {
                    while ($r = mysqli_fetch_assoc($find_result)) {
                        $rows[] = $r;
                    }
                    mysqli_free_result($find_result);
                }

                mysqli_stmt_close($find_stmt);
            }

            if (empty($rows)) {
                throw new Exception("No eligible expired stock was found.");
            }

            /* ------------------------------------------------
               Prepare operations once.
               ------------------------------------------------ */

            $delete_stmt = mysqli_prepare(
                $conn,
                "DELETE FROM store_items
                 WHERE id = ?
                   AND pharmacy_id = ?
                   AND branch_id = ?"
            );

            $zero_stmt = mysqli_prepare(
                $conn,
                "UPDATE store_items
                 SET quantity = 0
                 WHERE id = ?
                   AND pharmacy_id = ?
                   AND branch_id = ?"
            );

            if (!$delete_stmt || !$zero_stmt) {
                throw new Exception("Could not prepare disposal operation.");
            }

            /* ------------------------------------------------
               Process every expired row.
               ------------------------------------------------ */

            foreach ($rows as $expired_row) {

                $item_id = (int)$expired_row['id'];
                $has_sales = ((int)$expired_row['has_sales'] === 1);

                if ($has_sales) {

                    /*
                     * Sales history exists.
                     * Preserve the row and historical relationship,
                     * but remove its remaining expired stock.
                     */
                    mysqli_stmt_bind_param(
                        $zero_stmt,
                        "iii",
                        $item_id,
                        $p_id,
                        $b_id
                    );

                    if (!mysqli_stmt_execute($zero_stmt)) {
                        throw new Exception(
                            "Failed to remove expired quantity for " .
                            $expired_row['item_name']
                        );
                    }

                    $zeroed_count++;
                    $preserved_sales_count++;

                } else {

                    /*
                     * No sales history exists.
                     * It is safe to remove the inventory row entirely.
                     */
                    mysqli_stmt_bind_param(
                        $delete_stmt,
                        "iii",
                        $item_id,
                        $p_id,
                        $b_id
                    );

                    if (!mysqli_stmt_execute($delete_stmt)) {
                        throw new Exception(
                            "Failed to permanently remove " .
                            $expired_row['item_name']
                        );
                    }

                    if (mysqli_stmt_affected_rows($delete_stmt) > 0) {
                        $deleted_count++;
                    }
                }

                $discarded_count++;
            }

            mysqli_stmt_close($delete_stmt);
            mysqli_stmt_close($zero_stmt);

            mysqli_commit($conn);

            if ($is_single) {

                if ($preserved_sales_count > 0) {
                    $success =
                        "Expired stock discarded successfully. " .
                        "Its sales history was preserved and the expired quantity was removed.";
                } else {
                    $success =
                        "Expired product permanently removed from inventory.";
                }

            } else {

                $success =
                    "Expired stock disposal completed. " .
                    "{$deleted_count} item(s) permanently removed";

                if ($zeroed_count > 0) {
                    $success .=
                        " and {$zeroed_count} item(s) had their expired quantity cleared while sales history was preserved";
                }

                $success .= ".";
            }

        } catch (Throwable $e) {

            try {
                mysqli_rollback($conn);
            } catch (Throwable $rollback_error) {
                // Ignore rollback failure.
            }

            error_log(
                'EXPIRED STOCK DISPOSAL ERROR: ' .
                $e->getMessage()
            );

            $success = '';
            $error = $e->getMessage();
        }
    }
}

/* ============================================================
   BRANDING
   ============================================================ */

$display_pharm = 'PHARMANOVA';
$display_bran  = 'Main Branch';

$info_stmt = mysqli_prepare(
    $conn,
    "SELECT p.name, b.branch_name
     FROM pharmacies p
     INNER JOIN branches b ON b.pharmacy_id = p.id
     WHERE p.id = ?
       AND b.id = ?
     LIMIT 1"
);

if ($info_stmt) {

    mysqli_stmt_bind_param(
        $info_stmt,
        "ii",
        $p_id,
        $b_id
    );

    mysqli_stmt_execute($info_stmt);

    $info_res = mysqli_stmt_get_result($info_stmt);

    if ($info_res && ($info = mysqli_fetch_assoc($info_res))) {
        $display_pharm = $info['name'] ?? $display_pharm;
        $display_bran  = $info['branch_name'] ?? $display_bran;
    }

    mysqli_stmt_close($info_stmt);
}

/* ============================================================
   SEARCH
   ============================================================ */

$search = trim((string)($_GET['search'] ?? ''));

/* ============================================================
   SUMMARY COUNTS
   ============================================================ */

$total_expired = 0;
$total_expired_qty = 0;

$summary_where = "
    pharmacy_id = {$p_id}
    AND branch_id = {$b_id}
    AND expiry_date < '" . mysqli_real_escape_string($conn, $today) . "'
";

if ($search !== '') {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $like = "'%" . $safe_search . "%'";

    $summary_where .= "
        AND (
            item_name LIKE {$like}
            OR strength LIKE {$like}
            OR category LIKE {$like}
            OR barcode LIKE {$like}
            OR CAST(id AS CHAR) LIKE {$like}
        )
    ";
}

$summary_sql = "
    SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(COALESCE(quantity, 0)), 0) AS total_quantity
    FROM store_items
    WHERE {$summary_where}
";

$summary_result = mysqli_query($conn, $summary_sql);

if ($summary_result && ($summary = mysqli_fetch_assoc($summary_result))) {
    $total_expired = (int)$summary['total_items'];
    $total_expired_qty = (int)$summary['total_quantity'];
}

if ($summary_result) {
    mysqli_free_result($summary_result);
}

/* ============================================================
   PAGINATION
   ============================================================ */

$num_per_page = 50;

$page = isset($_GET['page'])
    ? (int)$_GET['page']
    : 1;

if ($page < 1) {
    $page = 1;
}

$total_pages = max(
    1,
    (int)ceil($total_expired / $num_per_page)
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$start_from = ($page - 1) * $num_per_page;

/* ============================================================
   EXPIRED PRODUCT QUERY
   ============================================================ */

$expired_products = false;

$where_sql = "
    si.pharmacy_id = ?
    AND si.branch_id = ?
    AND si.expiry_date < ?
";

if ($search !== '') {

    $where_sql .= "
        AND (
            si.item_name LIKE ?
            OR si.strength LIKE ?
            OR si.category LIKE ?
            OR si.barcode LIKE ?
            OR CAST(si.id AS CHAR) LIKE ?
        )
    ";
}

if ($search !== '') {

    $safe_like = '%' . $search . '%';

    $sql = "
        SELECT
            si.id,
            si.item_name,
            si.strength,
            si.category,
            si.quantity,
            si.expiry_date,
            si.barcode,
            si.cost,
            si.price,
            si.manufacturer,
            EXISTS (
                SELECT 1
                FROM sales_items sx
                WHERE sx.product_id = si.id
            ) AS has_sales
        FROM store_items si
        WHERE {$where_sql}
        ORDER BY si.expiry_date ASC, si.item_name ASC, si.id ASC
        LIMIT ?, ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "issssssii",
            $p_id,
            $b_id,
            $today,
            $safe_like,
            $safe_like,
            $safe_like,
            $safe_like,
            $safe_like,
            $start_from,
            $num_per_page
        );

        mysqli_stmt_execute($stmt);
        $expired_products = mysqli_stmt_get_result($stmt);
    }

} else {

    $sql = "
        SELECT
            si.id,
            si.item_name,
            si.strength,
            si.category,
            si.quantity,
            si.expiry_date,
            si.barcode,
            si.cost,
            si.price,
            si.manufacturer,
            EXISTS (
                SELECT 1
                FROM sales_items sx
                WHERE sx.product_id = si.id
            ) AS has_sales
        FROM store_items si
        WHERE {$where_sql}
        ORDER BY si.expiry_date ASC, si.item_name ASC, si.id ASC
        LIMIT ?, ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "iisii",
            $p_id,
            $b_id,
            $today,
            $start_from,
            $num_per_page
        );

        mysqli_stmt_execute($stmt);
        $expired_products = mysqli_stmt_get_result($stmt);
    }
}

require_once "../includes/head.php";
?>

<style>
:root {
    --expired-red: #dc3545;
    --expired-red-dark: #b02a37;
    --expired-orange: #f17808;
    --expired-green: #198754;
    --expired-dark: #3e4f60;
    --expired-bg: #f4f6f9;
    --expired-border: #dfe6ee;
}

.expired-page {
    background: var(--expired-bg);
    min-height: calc(100vh - 70px);
    padding: 15px;
}

.expired-shell {
    max-width: 1600px;
    margin: 0 auto;
}

.expired-card {
    background: #fff;
    border: 1px solid var(--expired-border);
    border-radius: 10px;
    box-shadow: 0 2px 7px rgba(31, 48, 67, .05);
}

.expired-header {
    padding: 18px 20px;
    border-left: 5px solid var(--expired-red);
}

.expired-icon {
    width: 48px;
    height: 48px;
    border-radius: 11px;
    background: #ffeded;
    color: var(--expired-red);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex: 0 0 auto;
}

.expired-kpi {
    min-height: 110px;
    color: #fff;
}

.expired-kpi-red {
    background: linear-gradient(180deg, #dc3545, #b02a37);
}

.expired-kpi-dark {
    background: #3f4d5b;
}

.expired-kpi-orange {
    background: linear-gradient(180deg, #f17808, #ca1818);
}

.expired-kpi-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
    opacity: .9;
}

.expired-kpi-value {
    font-size: 25px;
    font-weight: 800;
    margin-top: 4px;
}

.expired-kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
}

.expired-filter {
    padding: 14px;
}

.expired-label {
    font-size: 10px;
    font-weight: 800;
    color: #637286;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.expired-table-wrap {
    background: #fff;
    border: 1px solid var(--expired-border);
    border-radius: 10px;
    overflow: hidden;
}

.expired-table {
    width: 100%;
    margin-bottom: 0;
}

.expired-table thead th {
    background: var(--expired-red);
    color: #fff !important;
    padding: 13px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .35px;
    white-space: nowrap;
    border-bottom: 2px solid var(--expired-red-dark);
}

.expired-table tbody td {
    padding: 13px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.expired-table tbody tr:hover {
    background: #fff8f8;
}

.expired-product {
    font-weight: 800;
    color: #26364a;
}

.expired-sub {
    color: #8793a2;
    font-size: 11px;
}

.expired-qty {
    display: inline-block;
    min-width: 55px;
    padding: 5px 10px;
    border-radius: 6px;
    background: #ffeded;
    color: #dc3545;
    border: 1px solid #ffcccc;
    font-weight: 800;
    text-align: center;
}

.sales-preserved {
    display: inline-block;
    margin-top: 4px;
    font-size: 10px;
    color: #198754;
    font-weight: 700;
}

.discard-btn {
    min-width: 108px;
}

.empty-expired {
    padding: 60px 20px !important;
    text-align: center;
    color: #7c8998 !important;
}

.empty-expired i {
    display: block;
    font-size: 42px;
    opacity: .28;
    margin-bottom: 12px;
}

@media (max-width: 768px) {
    .expired-page {
        padding: 10px;
    }

    .expired-kpi-value {
        font-size: 21px;
    }
}

@media print {
    .no-print,
    .topbar,
    .left-sidebar,
    footer {
        display: none !important;
    }

    .expired-page {
        padding: 0 !important;
        background: #fff !important;
    }

    .expired-card,
    .expired-table-wrap {
        box-shadow: none !important;
    }

    .expired-table thead th {
        background: #dc3545 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
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

<main class="page-wrapper expired-page">
<div class="expired-shell">

    <!-- HEADER -->
    <div class="expired-card expired-header mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

        <div class="d-flex align-items-center gap-3">

            <div class="expired-icon">
                <i class="fas fa-calendar-times"></i>
            </div>

            <div>
                <h3 class="fw-bold text-dark mb-1">
                    Expired Products
                </h3>

                <div class="small text-danger">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Expired Stock
                    <span class="mx-1">â€¢</span>
                    Branch:
                    <strong><?= expired_h($display_bran) ?></strong>
                    <span class="mx-1">â€¢</span>
                    Zambia POS Date:
                    <strong class="text-dark"><?= date('d F Y') ?></strong>
                </div>

                <div class="small text-muted mt-1">
                    Discarding removes expired stock without destroying historical sales.
                </div>
            </div>

        </div>

        <div class="no-print d-flex flex-wrap gap-2">

            <?php if ($total_expired > 0): ?>

                <form
                    method="post"
                    class="d-inline"
                    onsubmit="return confirm(
                        'DISCARD ALL EXPIRED STOCK?\\n\\n' +
                        'Items with no sales history will be permanently removed.\\n' +
                        'Items linked to sales will have their expired quantity set to 0 so sales history remains intact.\\n\\n' +
                        'This cannot be undone.'
                    );"
                >
                    <button
                        type="submit"
                        name="clear_expired"
                        class="btn btn-danger fw-bold"
                    >
                        <i class="fas fa-trash-alt me-1"></i>
                        Discard All
                    </button>
                </form>

            <?php endif; ?>

            <button
                type="button"
                class="btn btn-dark fw-bold"
                onclick="exportToPDF()"
            >
                <i class="fas fa-file-pdf me-1"></i>
                Export PDF
            </button>

            <button
                type="button"
                class="btn btn-outline-primary fw-bold"
                onclick="window.print()"
            >
                <i class="fas fa-print me-1"></i>
                Print
            </button>

        </div>

    </div>

    <!-- MESSAGES -->
    <?php if ($success): ?>

        <div class="alert alert-success border-0 shadow-sm">
            <i class="fas fa-check-circle me-1"></i>
            <?= expired_h($success) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-danger border-0 shadow-sm">
            <i class="fas fa-exclamation-circle me-1"></i>
            <?= expired_h($error) ?>
        </div>

    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3 mb-3">

        <div class="col-12 col-md-4">

            <div class="expired-card expired-kpi expired-kpi-red p-3">
                <div class="d-flex justify-content-between">

                    <div>
                        <div class="expired-kpi-label">
                            Expired Items
                        </div>

                        <div class="expired-kpi-value">
                            <?= number_format($total_expired) ?>
                        </div>

                        <small>
                            Current branch
                        </small>
                    </div>

                    <div class="expired-kpi-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-12 col-md-4">

            <div class="expired-card expired-kpi expired-kpi-dark p-3">
                <div class="d-flex justify-content-between">

                    <div>
                        <div class="expired-kpi-label">
                            Expired Quantity
                        </div>

                        <div class="expired-kpi-value">
                            <?= number_format($total_expired_qty) ?>
                        </div>

                        <small>
                            Units requiring disposal
                        </small>
                    </div>

                    <div class="expired-kpi-icon">
                        <i class="fas fa-box-open"></i>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-12 col-md-4">

            <div class="expired-card expired-kpi expired-kpi-orange p-3">
                <div class="d-flex justify-content-between">

                    <div>
                        <div class="expired-kpi-label">
                            Disposal Status
                        </div>

                        <div class="expired-kpi-value">
                            <?= $total_expired > 0 ? 'ACTION' : 'CLEAR' ?>
                        </div>

                        <small>
                            <?= $total_expired > 0
                                ? 'Expired stock needs attention'
                                : 'No expired stock found'
                            ?>
                        </small>
                    </div>

                    <div class="expired-kpi-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>

                </div>
            </div>

        </div>

    </div>

    <!-- SEARCH -->
    <div class="expired-card expired-filter mb-3 no-print">

        <form method="get" action="expired_products.php" id="expired-search-form">

            <label class="expired-label">
                Search Expired Products
            </label>

            <div class="input-group">

                <span class="input-group-text bg-white">
                    <i class="fas fa-search text-muted"></i>
                </span>

                <input
                    type="search"
                    name="search"
                    id="expired-search"
                    class="form-control"
                    value="<?= expired_h($search) ?>"
                    placeholder="Product name, strength, category, barcode or ID..."
                    autocomplete="off"
                >

                <?php if ($search !== ''): ?>

                    <a
                        href="expired_products.php"
                        class="btn btn-outline-secondary"
                    >
                        <i class="fas fa-times"></i>
                        Clear
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <!-- TABLE -->
    <div class="expired-table-wrap">

        <div class="p-3 border-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">

            <div>
                <h5 class="mb-1 fw-bold">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                    Expired Stock
                </h5>

                <div class="small text-muted">
                    <?= number_format($total_expired) ?> item(s) found
                </div>
            </div>

            <div class="small text-muted">
                Page <?= number_format($page) ?>
                of <?= number_format($total_pages) ?>
            </div>

        </div>

        <div class="table-responsive">

            <table class="table expired-table" id="expired-table">

                <thead>
                    <tr>
                        <th class="ps-3" width="60">#</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Barcode</th>
                        <th>Qty Left</th>
                        <th>Expiry Date</th>
                        <th>Sales History</th>
                        <th class="text-center no-print">Action</th>
                    </tr>
                </thead>

                <tbody id="expired-body">

                <?php if ($expired_products && $expired_products->num_rows > 0): ?>

                    <?php $c = $start_from + 1; ?>

                    <?php while ($row = $expired_products->fetch_assoc()): ?>

                        <?php
                        $has_sales = ((int)$row['has_sales'] === 1);
                        $qty = (int)$row['quantity'];
                        ?>

                        <tr>

                            <td class="ps-3 text-muted fw-bold">
                                <?= $c++ ?>
                            </td>

                            <td>

                                <div class="expired-product">
                                    <?= expired_h($row['item_name']) ?>
                                </div>

                                <div class="expired-sub">

                                    <?= expired_h(
                                        $row['strength'] ?: 'No strength specified'
                                    ) ?>

                                    <?php if (!empty($row['manufacturer'])): ?>

                                        <span class="mx-1">â€¢</span>

                                        <?= expired_h($row['manufacturer']) ?>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <td>

                                <span class="badge bg-light text-dark border">
                                    <?= expired_h(
                                        $row['category'] ?: 'General'
                                    ) ?>
                                </span>

                            </td>

                            <td>

                                <?php if (!empty($row['barcode'])): ?>

                                    <span class="expired-sub">
                                        <?= expired_h($row['barcode']) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        â€”
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <span class="expired-qty">
                                    <?= number_format($qty) ?>
                                </span>

                            </td>

                            <td>

                                <span class="text-danger fw-bold">
                                    <?= date(
                                        'd M Y',
                                        strtotime($row['expiry_date'])
                                    ) ?>
                                </span>

                            </td>

                            <td>

                                <?php if ($has_sales): ?>

                                    <span class="badge bg-success">
                                        <i class="fas fa-history me-1"></i>
                                        PRESERVED
                                    </span>

                                    <div class="sales-preserved">
                                        Sales record exists
                                    </div>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        No sales
                                    </span>

                                    <div class="sales-preserved text-muted">
                                        Safe to remove
                                    </div>

                                <?php endif; ?>

                            </td>

                            <td class="text-center no-print">

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirmDiscard(
                                        <?= $has_sales ? 'true' : 'false' ?>,
                                        <?= json_encode($row['item_name']) ?>
                                    );"
                                >

                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?= (int)$row['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="dispose_product"
                                        class="btn btn-sm btn-outline-danger discard-btn fw-bold"
                                        title="Discard expired stock"
                                    >
                                        <i class="fas fa-trash-alt me-1"></i>
                                        DISCARD
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="8" class="empty-expired">

                            <i class="fas fa-check-circle text-success"></i>

                            <strong class="d-block text-dark">
                                Excellent! No expired items found.
                            </strong>

                            <div class="small mt-1">
                                This branch currently has no expired stock
                                matching your search.
                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>

            <div class="p-3 border-top no-print">

                <nav aria-label="Expired products pagination">

                    <ul class="pagination pagination-sm mb-0 justify-content-center">

                        <?php
                        $query_base = [];

                        if ($search !== '') {
                            $query_base['search'] = $search;
                        }
                        ?>

                        <?php if ($page > 1): ?>

                            <?php
                            $query_base['page'] = $page - 1;
                            $prev_url = 'expired_products.php?' .
                                http_build_query($query_base);
                            ?>

                            <li class="page-item">
                                <a
                                    class="page-link"
                                    href="<?= expired_h($prev_url) ?>"
                                >
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>

                        <?php endif; ?>

                        <?php
                        $window_start = max(1, $page - 2);
                        $window_end = min($total_pages, $page + 2);

                        for ($pg = $window_start; $pg <= $window_end; $pg++):
                            $query_base['page'] = $pg;

                            $page_url =
                                'expired_products.php?' .
                                http_build_query($query_base);
                        ?>

                            <li class="page-item <?= $pg === $page ? 'active' : '' ?>">

                                <a
                                    class="page-link"
                                    href="<?= expired_h($page_url) ?>"
                                >
                                    <?= $pg ?>
                                </a>

                            </li>

                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>

                            <?php
                            $query_base['page'] = $page + 1;
                            $next_url = 'expired_products.php?' .
                                http_build_query($query_base);
                            ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?= expired_h($next_url) ?>"
                                >
                                    <i class="fas fa-chevron-right"></i>
                                </a>

                            </li>

                        <?php endif; ?>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </div>

    <div class="small text-muted mt-3 no-print">

        <i class="fas fa-info-circle me-1"></i>

        <strong>Disposal protection:</strong>
        products with sales history are never deleted from the database.
        Their expired quantity is cleared so the inventory disappears
        from this page while historical sales remain available.

    </div>

</div>
</main>

<?php
if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php";
}
?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
(function () {
    'use strict';

    /* =========================================================
       LIVE SEARCH
       ========================================================= */

    const searchInput = document.getElementById('expired-search');
    let searchTimer = null;

    if (searchInput) {

        searchInput.addEventListener('input', function () {

            clearTimeout(searchTimer);

            searchTimer = setTimeout(function () {

                const value = searchInput.value.trim();

                if (value.length === 0) {
                    window.location.href = 'expired_products.php';
                    return;
                }

                const params = new URLSearchParams();

                params.set('search', value);
                params.set('page', '1');

                window.location.href =
                    'expired_products.php?' +
                    params.toString();

            }, 450);

        });

        searchInput.addEventListener('keydown', function (event) {

            if (event.key === 'Enter') {

                event.preventDefault();

                clearTimeout(searchTimer);

                const value = searchInput.value.trim();

                const params = new URLSearchParams();

                if (value !== '') {
                    params.set('search', value);
                }

                params.set('page', '1');

                window.location.href =
                    'expired_products.php?' +
                    params.toString();
            }

        });

    }

    /* =========================================================
       SAFE DISCARD CONFIRMATION
       ========================================================= */

    window.confirmDiscard = function (hasSales, productName) {

        if (hasSales) {

            return confirm(
                'DISCARD EXPIRED STOCK?\\n\\n' +
                productName + '\\n\\n' +
                'This product has sales history.\\n' +
                'Its expired quantity will be set to 0, but the sales history will be preserved.\\n\\n' +
                'The product will disappear from the expired products page.\\n\\n' +
                'Continue?'
            );

        }

        return confirm(
            'PERMANENTLY REMOVE EXPIRED PRODUCT?\\n\\n' +
            productName + '\\n\\n' +
            'This product has no sales history.\\n' +
            'Its inventory record will be permanently deleted.\\n\\n' +
            'This cannot be undone.\\n\\n' +
            'Continue?'
        );
    };

    /* =========================================================
       PDF EXPORT
       ========================================================= */

    window.exportToPDF = function () {

        if (!window.jspdf) {
            alert('PDF library is still loading. Please try again.');
            return;
        }

        const { jsPDF } = window.jspdf;

        const doc = new jsPDF();

        const pharmacyName =
            <?= json_encode($display_pharm) ?>;

        const branchName =
            <?= json_encode($display_bran) ?>;

        const reportDate =
            <?= json_encode(date('d-M-Y H:i')) ?>;

        doc.setFontSize(16);

        doc.text(
            pharmacyName.toUpperCase() +
            ' - EXPIRED STOCK REPORT',
            14,
            20
        );

        doc.setFontSize(10);

        doc.text(
            'Branch: ' +
            branchName +
            ' | Date: ' +
            reportDate,
            14,
            28
        );

        doc.autoTable({

            html: '#expired-table',

            startY: 35,

            theme: 'grid',

            headStyles: {
                fillColor: [220, 53, 69],
                textColor: [255, 255, 255]
            },

            /*
             * Only export useful report columns.
             * Action buttons and sales-history controls are omitted.
             */
            columns: [
                { header: '#', dataKey: '0' },
                { header: 'Product Name', dataKey: '1' },
                { header: 'Category', dataKey: '2' },
                { header: 'Barcode', dataKey: '3' },
                { header: 'Qty Left', dataKey: '4' },
                { header: 'Expiry Date', dataKey: '5' }
            ]

        });

        doc.save(
            pharmacyName +
            '_Expired_Stock_' +
            reportDate.replace(/[: ]/g, '-') +
            '.pdf'
        );
    };

})();
</script>

</body>
</html>
