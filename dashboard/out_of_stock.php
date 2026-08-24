<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * OUT OF STOCK â€” FINAL CONSOLIDATED PAGE
 * ============================================================
 *
 * Zambia/POS standard timezone:
 *     Africa/Lusaka
 *
 * Displays products whose aggregated branch quantity is <= 0.
 *
 * Features:
 *   - Pharmacy + branch isolation
 *   - Product search
 *   - Category filter
 *   - Stock status filter
 *   - Real-time filters (no Apply required)
 *   - 450ms live search
 *   - Restock button
 *   - Restock CSV export
 *   - Safe handling of NULL / 0000-00-00 expiry values
 *   - Existing EchoTech POS header / sidebar / footer
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

if (function_exists('require_login')) {
    require_login();
}

if (!isset($_SESSION['pharmacy_id'], $_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    die("<div class='alert alert-danger text-center mt-3'>Invalid pharmacy or branch session.</div>");
}

$today = date('Y-m-d');

/* ============================================================
   HELPERS
   ============================================================ */

function oos_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function oos_money($value): string
{
    return 'K ' . number_format((float)$value, 2);
}

/* ============================================================
   BRANDING
   ============================================================ */

$display_pharm = 'PHARMANOVA';
$display_bran  = 'Main Branch';

try {
    $info_stmt = mysqli_prepare(
        $conn,
        "SELECT p.name, b.branch_name
         FROM pharmacies p
         INNER JOIN branches b ON b.pharmacy_id = p.id
         WHERE p.id = ? AND b.id = ?
         LIMIT 1"
    );

    if ($info_stmt) {
        mysqli_stmt_bind_param($info_stmt, "ii", $pharmacy_id, $branch_id);
        mysqli_stmt_execute($info_stmt);
        $info_res = mysqli_stmt_get_result($info_stmt);

        if ($info_res && ($info = mysqli_fetch_assoc($info_res))) {
            $display_pharm = $info['name'] ?? $display_pharm;
            $display_bran  = $info['branch_name'] ?? $display_bran;
        }

        mysqli_stmt_close($info_stmt);
    }
} catch (Throwable $e) {
    error_log('OUT OF STOCK BRANDING: ' . $e->getMessage());
}

/* ============================================================
   FILTERS
   ============================================================ */

$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$stock_filter = trim((string)($_GET['stock'] ?? 'all'));

if (!in_array($stock_filter, ['all', 'zero', 'negative'], true)) {
    $stock_filter = 'all';
}

/* ============================================================
   CATEGORIES
   ============================================================ */

$categories = [];

try {
    $cat_stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT category
         FROM store_items
         WHERE pharmacy_id = ?
           AND branch_id = ?
           AND category IS NOT NULL
           AND category <> ''
         ORDER BY category ASC"
    );

    if ($cat_stmt) {
        mysqli_stmt_bind_param($cat_stmt, "ii", $pharmacy_id, $branch_id);
        mysqli_stmt_execute($cat_stmt);
        $cat_res = mysqli_stmt_get_result($cat_stmt);

        if ($cat_res) {
            while ($cat_row = mysqli_fetch_assoc($cat_res)) {
                $categories[] = (string)$cat_row['category'];
            }
        }

        mysqli_stmt_close($cat_stmt);
    }
} catch (Throwable $e) {
    error_log('OUT OF STOCK CATEGORIES: ' . $e->getMessage());
}

/* ============================================================
   BUILD FILTERED GROUP QUERY
   ============================================================
   Products are grouped by:
     item_name + strength + category + barcode

   This keeps multiple stock rows/batches for the same product
   together and calculates the real branch total quantity.
   ============================================================ */

$where = [
    "si.pharmacy_id = " . $pharmacy_id,
    "si.branch_id = " . $branch_id
];

if ($search !== '') {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $like = "'%" . $safe_search . "%'";

    $where[] = "(
        si.item_name LIKE {$like}
        OR si.barcode LIKE {$like}
        OR si.category LIKE {$like}
        OR si.strength LIKE {$like}
        OR si.manufacturer LIKE {$like}
    )";
}

if ($category !== '') {
    $safe_category = mysqli_real_escape_string($conn, $category);
    $where[] = "si.category = '{$safe_category}'";
}

$where_sql = implode(" AND ", $where);

/*
 * The HAVING clause is deliberately applied after GROUP BY.
 * This avoids relying on a quantity column from a single batch.
 */
$having_sql = "SUM(COALESCE(si.quantity, 0)) <= 0";

if ($stock_filter === 'zero') {
    $having_sql = "SUM(COALESCE(si.quantity, 0)) = 0";
} elseif ($stock_filter === 'negative') {
    $having_sql = "SUM(COALESCE(si.quantity, 0)) < 0";
}

/* ============================================================
   SUMMARY
   ============================================================ */

$summary = [
    'products' => 0,
    'zero_stock' => 0,
    'negative_stock' => 0,
    'restock_cost' => 0
];

try {
    $summary_sql = "
        SELECT
            COUNT(*) AS products,
            SUM(CASE
                WHEN total_qty = 0 THEN 1
                ELSE 0
            END) AS zero_stock,
            SUM(CASE
                WHEN total_qty < 0 THEN 1
                ELSE 0
            END) AS negative_stock,
            COALESCE(SUM(
                CASE
                    WHEN total_qty <= 0 THEN GREATEST(cost, 0)
                    ELSE 0
                END
            ), 0) AS restock_cost
        FROM (
            SELECT
                si.item_name,
                si.strength,
                si.category,
                si.barcode,
                SUM(COALESCE(si.quantity, 0)) AS total_qty,
                MAX(COALESCE(si.cost, 0)) AS cost
            FROM store_items si
            WHERE {$where_sql}
            GROUP BY si.item_name, si.strength, si.category, si.barcode
            HAVING {$having_sql}
        ) AS grouped_stock
    ";

    $summary_res = mysqli_query($conn, $summary_sql);

    if ($summary_res && ($summary_row = mysqli_fetch_assoc($summary_res))) {
        $summary = [
            'products' => (int)($summary_row['products'] ?? 0),
            'zero_stock' => (int)($summary_row['zero_stock'] ?? 0),
            'negative_stock' => (int)($summary_row['negative_stock'] ?? 0),
            'restock_cost' => (float)($summary_row['restock_cost'] ?? 0)
        ];
    }

    if ($summary_res) {
        mysqli_free_result($summary_res);
    }
} catch (Throwable $e) {
    error_log('OUT OF STOCK SUMMARY: ' . $e->getMessage());
}

/* ============================================================
   OUT OF STOCK ROWS
   ============================================================ */

$out_of_stock_rows = [];
$export_data = [];

try {
    $sql = "
        SELECT
            MAX(si.id) AS id,
            si.item_name,
            si.strength,
            si.category,
            SUM(COALESCE(si.quantity, 0)) AS total_qty,
            si.barcode,
            MAX(COALESCE(si.cost, 0)) AS cost,
            MAX(COALESCE(si.price, 0)) AS price,
            MAX(si.expiry_date) AS latest_expiry,
            MAX(si.manufacturer) AS manufacturer
        FROM store_items si
        WHERE {$where_sql}
        GROUP BY
            si.item_name,
            si.strength,
            si.category,
            si.barcode
        HAVING {$having_sql}
        ORDER BY
            CASE WHEN SUM(COALESCE(si.quantity, 0)) < 0 THEN 0 ELSE 1 END ASC,
            si.item_name ASC
    ";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $out_of_stock_rows[] = $row;

            $expiry = $row['latest_expiry'] ?? null;
            $expiry_display = 'N/A';

            if (
                !empty($expiry) &&
                substr((string)$expiry, 0, 4) !== '0000' &&
                strtotime((string)$expiry) !== false
            ) {
                $expiry_display = date('d/m/Y', strtotime($expiry));
            }

            $export_data[] = [
                $row['item_name'],
                $row['strength'] ?: 'N/A',
                $row['cost'],
                $row['price'],
                $row['total_qty'],
                $row['category'] ?: 'General',
                $row['barcode'] ?: '',
                $row['manufacturer'] ?: '',
                $expiry_display
            ];
        }

        mysqli_free_result($result);
    }
} catch (Throwable $e) {
    error_log('OUT OF STOCK QUERY: ' . $e->getMessage());
}

require_once "../includes/head.php";
?>

<style>
:root {
    --oos-red: #dc3545;
    --oos-red-dark: #b02a37;
    --oos-blue: #4299cf;
    --oos-green: #198754;
    --oos-orange: #f17808;
    --oos-dark: #3e4f60;
    --oos-bg: #f4f6f9;
    --oos-border: #dfe6ee;
}

.out-stock-page {
    background: var(--oos-bg);
    min-height: calc(100vh - 60px);
    padding: 15px;
}

.oos-shell {
    max-width: 1600px;
    margin: 0 auto;
}

.oos-card {
    background: #fff;
    border: 1px solid var(--oos-border);
    border-radius: 10px;
    box-shadow: 0 2px 7px rgba(31, 48, 67, .05);
}

.oos-header {
    padding: 18px 20px;
    border-left: 5px solid var(--oos-red);
}

.oos-title-icon {
    width: 48px;
    height: 48px;
    border-radius: 11px;
    background: #ffeded;
    color: var(--oos-red);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex: 0 0 auto;
}

.oos-kpi {
    min-height: 112px;
    color: #fff;
    overflow: hidden;
    position: relative;
}

.oos-red {
    background: linear-gradient(180deg, #dc3545, #b02a37);
}

.oos-dark {
    background: #3f4d5b;
}

.oos-orange {
    background: linear-gradient(180deg, #f17808, #ca1818);
}

.oos-green {
    background: #198754;
}

.oos-kpi-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
    opacity: .9;
}

.oos-kpi-value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 4px;
}

.oos-kpi small {
    opacity: .9;
}

.oos-kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.oos-filter {
    padding: 15px;
}

.oos-label {
    font-size: 10px;
    font-weight: 800;
    color: #637286;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.oos-filter .form-select,
.oos-filter .form-control {
    transition: border-color .15s ease, box-shadow .15s ease;
}

.oos-filter.is-filtering {
    opacity: .72;
    pointer-events: none;
}

.oos-filter-loading {
    display: none;
    font-size: 12px;
    font-weight: 700;
    color: var(--oos-red);
    margin-top: 9px;
}

.oos-filter.is-filtering .oos-filter-loading {
    display: block;
}

.oos-table-wrap {
    background: #fff;
    border-radius: 10px;
    border: 1px solid var(--oos-border);
    overflow: hidden;
}

.oos-table {
    width: 100%;
    margin-bottom: 0;
}

.oos-table thead th {
    background: var(--oos-red);
    color: #fff !important;
    padding: 13px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .35px;
    white-space: nowrap;
    border-bottom: 2px solid var(--oos-red-dark);
}

.oos-table tbody td {
    padding: 13px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.oos-table tbody tr:hover {
    background: #fff8f8;
}

.oos-product {
    font-weight: 800;
    color: #26364a;
}

.oos-sub {
    color: #8793a2;
    font-size: 11px;
}

.oos-qty {
    display: inline-block;
    min-width: 58px;
    padding: 5px 10px;
    border-radius: 6px;
    text-align: center;
    font-weight: 800;
}

.oos-qty-zero {
    background: #ffeded;
    color: var(--oos-red);
    border: 1px solid #ffcccc;
}

.oos-qty-negative {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffe69c;
}

.oos-empty {
    padding: 60px 20px !important;
    text-align: center;
    color: #7c8998 !important;
}

.oos-empty i {
    display: block;
    font-size: 42px;
    opacity: .28;
    margin-bottom: 12px;
}

.oos-restock-btn {
    min-width: 105px;
}

@media (max-width: 768px) {
    .out-stock-page {
        padding: 10px;
    }

    .oos-kpi-value {
        font-size: 21px;
    }

    .oos-header {
        padding: 15px;
    }
}

@media print {
    .no-print,
    .topbar,
    .left-sidebar,
    footer {
        display: none !important;
    }

    .out-stock-page {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .oos-card,
    .oos-table-wrap {
        box-shadow: none !important;
    }

    .oos-table thead th {
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

<main class="page-wrapper out-stock-page">
<div class="oos-shell">

    <!-- HEADER -->
    <div class="oos-card oos-header mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="oos-title-icon">
                <i class="fas fa-box-open"></i>
            </div>

            <div>
                <h3 class="fw-bold text-dark mb-1">
                    Out of Stock
                </h3>

                <div class="small text-muted">
                    <strong><?= oos_h(strtoupper($display_pharm)) ?></strong>
                    <span class="mx-1">â€¢</span>
                    Branch:
                    <strong><?= oos_h($display_bran) ?></strong>
                    <span class="mx-1">â€¢</span>
                    Zambia POS Date:
                    <strong><?= oos_h(date('d F Y')) ?></strong>
                </div>

                <div class="small text-muted mt-1">
                    Products whose total quantity for this branch is zero or below.
                </div>
            </div>
        </div>

        <div class="no-print d-flex flex-wrap gap-2">
            <button type="button" onclick="downloadCSV()" class="btn btn-dark fw-bold">
                <i class="fas fa-file-download me-1"></i>
                Download Restock List
            </button>

            <button type="button" onclick="window.print()" class="btn btn-outline-primary fw-bold">
                <i class="fas fa-print me-1"></i>
                Print
            </button>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-3">

        <div class="col-12 col-md-6 col-xl-3">
            <div class="oos-card oos-kpi oos-red p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="oos-kpi-label">Out of Stock Products</div>
                        <div class="oos-kpi-value">
                            <?= number_format($summary['products']) ?>
                        </div>
                        <small>Zero or negative quantity</small>
                    </div>

                    <div class="oos-kpi-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="oos-card oos-kpi oos-dark p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="oos-kpi-label">Exactly Zero</div>
                        <div class="oos-kpi-value">
                            <?= number_format($summary['zero_stock']) ?>
                        </div>
                        <small>Requires restocking</small>
                    </div>

                    <div class="oos-kpi-icon">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="oos-card oos-kpi oos-orange p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="oos-kpi-label">Negative Stock</div>
                        <div class="oos-kpi-value">
                            <?= number_format($summary['negative_stock']) ?>
                        </div>
                        <small>Needs reconciliation</small>
                    </div>

                    <div class="oos-kpi-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="oos-card oos-kpi oos-green p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="oos-kpi-label">Listed Restock Cost</div>
                        <div class="oos-kpi-value">
                            <?= oos_money($summary['restock_cost']) ?>
                        </div>
                        <small>Approx. unit cost</small>
                    </div>

                    <div class="oos-kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- FILTERS -->
    <div class="oos-card oos-filter mb-3 no-print" id="oos-filter-card">
        <form method="GET" action="out_of_stock.php" id="oos-filter-form">

            <div class="row g-3 align-items-end">

                <div class="col-12 col-lg-5">
                    <label class="oos-label">Search Product</label>

                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="fas fa-search text-muted"></i>
                        </span>

                        <input
                            type="search"
                            class="form-control"
                            name="search"
                            id="oos-search"
                            value="<?= oos_h($search) ?>"
                            placeholder="Name, barcode, category, strength..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3">
                    <label class="oos-label">Category</label>

                    <select name="category" class="form-select" id="oos-category">
                        <option value="">All Categories</option>

                        <?php foreach ($categories as $cat): ?>
                            <option
                                value="<?= oos_h($cat) ?>"
                                <?= $category === $cat ? 'selected' : '' ?>
                            >
                                <?= oos_h($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-2">
                    <label class="oos-label">Stock Status</label>

                    <select name="stock" class="form-select" id="oos-stock">
                        <option value="all" <?= $stock_filter === 'all' ? 'selected' : '' ?>>
                            All Out of Stock
                        </option>

                        <option value="zero" <?= $stock_filter === 'zero' ? 'selected' : '' ?>>
                            Exactly Zero
                        </option>

                        <option value="negative" <?= $stock_filter === 'negative' ? 'selected' : '' ?>>
                            Negative Stock
                        </option>
                    </select>
                </div>

                <div class="col-12 col-lg-2">
                    <button type="submit" class="btn btn-danger w-100 fw-bold">
                        <i class="fas fa-filter me-1"></i>
                        Apply
                    </button>
                </div>

            </div>

            <div class="oos-filter-loading" aria-live="polite">
                <i class="fas fa-spinner fa-spin me-1"></i>
                Updating out-of-stock list...
            </div>

            <?php if ($search !== '' || $category !== '' || $stock_filter !== 'all'): ?>
                <div class="mt-2">
                    <a href="out_of_stock.php" class="small text-decoration-none">
                        <i class="fas fa-times me-1"></i>
                        Clear filters
                    </a>
                </div>
            <?php endif; ?>

        </form>
    </div>

    <!-- TABLE -->
    <div class="oos-table-wrap">

        <div class="p-3 border-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h5 class="mb-1 fw-bold">
                    <i class="fas fa-clipboard-list text-danger me-2"></i>
                    Restock Required
                </h5>

                <div class="small text-muted">
                    <?= number_format(count($out_of_stock_rows)) ?> product(s) found
                </div>
            </div>

            <div class="small text-muted">
                Branch:
                <strong><?= oos_h($display_bran) ?></strong>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table oos-table align-middle">

                <thead>
                    <tr>
                        <th class="ps-3" width="60">#</th>
                        <th>Product Description</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Unit Cost</th>
                        <th>Expiry</th>
                        <th class="text-center no-print">Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!empty($out_of_stock_rows)): ?>

                    <?php $i = 1; ?>

                    <?php foreach ($out_of_stock_rows as $row): ?>

                        <?php
                        $qty = (int)$row['total_qty'];
                        $expiry = $row['latest_expiry'] ?? null;

                        $has_expiry = (
                            !empty($expiry) &&
                            substr((string)$expiry, 0, 4) !== '0000' &&
                            strtotime((string)$expiry) !== false
                        );

                        $expiry_days = null;

                        if ($has_expiry) {
                            $expiry_days = (int)floor(
                                (strtotime((string)$expiry) - strtotime($today)) / 86400
                            );
                        }
                        ?>

                        <tr>

                            <td class="ps-3 fw-bold text-muted">
                                <?= $i++ ?>
                            </td>

                            <td>
                                <div class="oos-product">
                                    <?= oos_h($row['item_name']) ?>
                                </div>

                                <div class="oos-sub">
                                    <?= oos_h($row['strength'] ?: 'No strength specified') ?>

                                    <?php if (!empty($row['manufacturer'])): ?>
                                        <span class="mx-1">â€¢</span>
                                        <?= oos_h($row['manufacturer']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($row['barcode'])): ?>
                                    <span class="oos-sub">
                                        <?= oos_h($row['barcode']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">â€”</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= oos_h($row['category'] ?: 'General') ?>
                                </span>
                            </td>

                            <td>
                                <span class="oos-qty <?= $qty < 0 ? 'oos-qty-negative' : 'oos-qty-zero' ?>">
                                    <?= number_format($qty) ?>
                                </span>

                                <?php if ($qty < 0): ?>
                                    <div class="small text-warning fw-bold mt-1">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Reconcile
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="fw-bold">
                                <?= oos_money($row['cost']) ?>
                            </td>

                            <td>
                                <?php if ($has_expiry): ?>

                                    <div class="<?= ($expiry_days !== null && $expiry_days <= 90) ? 'text-warning fw-bold' : 'text-muted' ?>">
                                        <?= oos_h(date('d M Y', strtotime((string)$expiry))) ?>
                                    </div>

                                <?php else: ?>

                                    <span class="text-muted">No expiry</span>

                                <?php endif; ?>
                            </td>

                            <td class="text-center no-print">
                                <a
                                    href="update_items_stock.php?id=<?= (int)$row['id'] ?>"
                                    class="btn btn-success btn-sm px-3 fw-bold oos-restock-btn"
                                    title="Restock Product"
                                >
                                    <i class="fas fa-plus-circle me-1"></i>
                                    RESTOCK
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="oos-empty">

                            <i class="fas fa-check-circle text-success"></i>

                            <strong class="d-block text-dark">
                                No out-of-stock products found
                            </strong>

                            <div class="small mt-1">
                                All matching products currently have stock available.
                            </div>

                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>
        </div>

        <div class="p-3 border-top">
            <div class="row align-items-center">

                <div class="col-md-6">
                    <small class="text-muted">
                        Out-of-stock products are calculated from the current
                        pharmacy and branch only.
                    </small>
                </div>

                <div class="col-md-6 text-md-end">
                    <span class="text-muted small">
                        Products requiring restock:
                    </span>

                    <strong class="text-danger fs-5">
                        <?= number_format(count($out_of_stock_rows)) ?>
                    </strong>
                </div>

            </div>
        </div>

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

<script>
(function () {
    'use strict';

    /* =========================================================
       REAL-TIME FILTERS
       ========================================================= */

    const filterForm = document.getElementById('oos-filter-form');
    const filterCard = document.getElementById('oos-filter-card');
    const searchInput = document.getElementById('oos-search');

    let searchTimer = null;
    let submitting = false;

    function submitFilters() {
        if (!filterForm || submitting) {
            return;
        }

        submitting = true;

        if (filterCard) {
            filterCard.classList.add('is-filtering');
        }

        if (typeof filterForm.requestSubmit === 'function') {
            filterForm.requestSubmit();
        } else {
            filterForm.submit();
        }
    }

    if (filterForm) {

        filterForm.querySelectorAll(
            'select[name="category"], select[name="stock"]'
        ).forEach(function (select) {

            select.addEventListener('change', function () {
                submitFilters();
            });

        });

        if (searchInput) {

            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);

                searchTimer = setTimeout(function () {
                    submitFilters();
                }, 450);
            });

            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(searchTimer);
                    submitFilters();
                }
            });
        }

        filterForm.addEventListener('submit', function () {
            submitting = true;

            if (filterCard) {
                filterCard.classList.add('is-filtering');
            }
        });
    }

    /* =========================================================
       CSV EXPORT
       ========================================================= */

    window.downloadCSV = function () {

        const data = <?= json_encode(
            $export_data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>;

        if (!data || data.length === 0) {
            alert("No out-of-stock items to export.");
            return;
        }

        const headers = [
            "Product Name",
            "Strength",
            "Cost Price",
            "Selling Price",
            "Quantity",
            "Category",
            "Barcode",
            "Manufacturer",
            "Expiry Date (DD/MM/YYYY)"
        ];

        const escapeCSV = function (value) {
            return '"' + String(value ?? '')
                .replace(/"/g, '""') + '"';
        };

        let csvContent = headers.map(escapeCSV).join(",") + "\n";

        data.forEach(function (row) {
            csvContent += row.map(escapeCSV).join(",") + "\n";
        });

        const blob = new Blob(
            [csvContent],
            { type: 'text/csv;charset=utf-8;' }
        );

        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download =
            'RESTOCK_<?= date('d_m_Y') ?>.csv';

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        URL.revokeObjectURL(url);
    };

})();
</script>

</body>
</html>
