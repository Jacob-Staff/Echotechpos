<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * PHARMACY STOCK — FINAL CONSOLIDATED PAGE
 * ============================================================
 *
 * This page displays ONLY stock with current value:
 *   - quantity > 0
 *   - selling price > 0
 *   - not expired
 *
 * Expired and out-of-stock products are intentionally excluded
 * because they have their own dedicated pages.
 *
 * Uses the existing EchoTech POS:
 *   - includes/conn.php
 *   - includes/auth.php
 *   - includes/head.php
 *   - includes/header.php
 *   - includes/aside.php
 *   - includes/footer.php
 *
 * No fetch_products.php dependency.
 * No delete_product_inc.php dependency.
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

/* Require the normal POS session. */
if (function_exists('require_login')) {
    require_login();
}

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 10);
$branch_id   = (int)($_SESSION['branch_id'] ?? 13);

if ($pharmacy_id <= 0) {
    $pharmacy_id = 10;
}

if ($branch_id <= 0) {
    $branch_id = 13;
}

$today = date('Y-m-d'); // Zambia/POS standard date
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$stock_filter = trim((string)($_GET['stock'] ?? 'all'));
$expiry_filter = trim((string)($_GET['expiry'] ?? 'all'));

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

/* Small local helpers with unique names to avoid collisions. */
function ps_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ps_money($value): string
{
    return 'K ' . number_format((float)$value, 2);
}

function ps_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ============================================================
   CONSOLIDATED ACTIONS
   ============================================================ */

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

if ($action === 'delete') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ps_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
    }

    $product_id = (int)($_POST['id'] ?? 0);

    if ($product_id <= 0) {
        ps_json(['status' => 'error', 'message' => 'Invalid product.'], 422);
    }

    try {
        $stmt = $conn->prepare("
            DELETE FROM store_items
            WHERE id = ?
              AND pharmacy_id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("iii", $product_id, $pharmacy_id, $branch_id);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            ps_json([
                'status' => 'error',
                'message' => 'Product was not found in this branch.'
            ], 404);
        }

        $stmt->close();

        ps_json([
            'status' => 'success',
            'message' => 'Product deleted successfully.'
        ]);

    } catch (Throwable $e) {
        error_log('PHARMACY STOCK DELETE: ' . $e->getMessage());

        ps_json([
            'status' => 'error',
            'message' => 'Unable to delete the product.'
        ], 500);
    }
}

if ($action === 'adjust_stock') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ps_json(['status' => 'error', 'message' => 'Invalid request method.'], 405);
    }

    $product_id = (int)($_POST['id'] ?? 0);
    $new_qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);

    if ($product_id <= 0 || $new_qty === false || $new_qty < 0) {
        ps_json([
            'status' => 'error',
            'message' => 'Enter a valid stock quantity.'
        ], 422);
    }

    try {
        $stmt = $conn->prepare("
            UPDATE store_items
            SET quantity = ?
            WHERE id = ?
              AND pharmacy_id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "iiii",
            $new_qty,
            $product_id,
            $pharmacy_id,
            $branch_id
        );

        $stmt->execute();
        $stmt->close();

        ps_json([
            'status' => 'success',
            'message' => 'Stock quantity updated.',
            'quantity' => $new_qty
        ]);

    } catch (Throwable $e) {
        error_log('PHARMACY STOCK ADJUST: ' . $e->getMessage());

        ps_json([
            'status' => 'error',
            'message' => 'Unable to update stock quantity.'
        ], 500);
    }
}

/* ============================================================
   CATEGORIES
   ============================================================ */

$categories = [];

try {
    $stmt = $conn->prepare("
        SELECT DISTINCT category
        FROM store_items
        WHERE pharmacy_id = ?
          AND branch_id = ?
          AND category IS NOT NULL
          AND category <> ''
        ORDER BY category ASC
    ");

    $stmt->bind_param("ii", $pharmacy_id, $branch_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $categories[] = (string)$row['category'];
    }

    $stmt->close();

} catch (Throwable $e) {
    error_log('PHARMACY STOCK CATEGORIES: ' . $e->getMessage());
}

/* ============================================================
   FILTERS + STOCK QUERY
   ------------------------------------------------------------
   Keep this query deliberately simple and compatible with the
   existing EchoTech POS database.

   IMPORTANT:
   Pharmacy Stock shows ONLY products that have:
     - quantity > 0
     - price > 0
     - not expired

   Expired and zero-stock products belong to their own pages.
   ============================================================ */

$where = [
    "si.pharmacy_id = {$pharmacy_id}",
    "si.branch_id = {$branch_id}",
    "si.quantity > 0",
    "si.price > 0",
    "(
        si.expiry_date IS NULL
        OR YEAR(si.expiry_date) = 0
        OR si.expiry_date >= '{$today}'
    )"
];

if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $like = "'%" . $safe_search . "%'";

    $where[] = "(
        si.item_name LIKE {$like}
        OR si.barcode LIKE {$like}
        OR si.category LIKE {$like}
        OR si.strength LIKE {$like}
    )";
}

if ($category !== '') {
    $safe_category = $conn->real_escape_string($category);
    $where[] = "si.category = '{$safe_category}'";
}

if ($stock_filter === 'low') {
    $where[] = "si.quantity BETWEEN 1 AND 10";
} elseif ($stock_filter === 'healthy') {
    $where[] = "si.quantity > 10";
}

/*
 * Expiry filters:
 *   all        = all valid stock
 *   30         = expires within 30 days
 *   60         = expires within 60 days
 *   90         = expires within 90 days
 *   no_expiry  = no expiry date
 */
switch ($expiry_filter) {

    case '30':
        $where[] = "
            si.expiry_date IS NOT NULL
            AND YEAR(si.expiry_date) <> 0
            AND si.expiry_date BETWEEN '{$today}'
            AND DATE_ADD('{$today}', INTERVAL 30 DAY)
        ";
        break;

    case '60':
        $where[] = "
            si.expiry_date IS NOT NULL
            AND YEAR(si.expiry_date) <> 0
            AND si.expiry_date BETWEEN '{$today}'
            AND DATE_ADD('{$today}', INTERVAL 60 DAY)
        ";
        break;

    case '90':
        $where[] = "
            si.expiry_date IS NOT NULL
            AND YEAR(si.expiry_date) <> 0
            AND si.expiry_date BETWEEN '{$today}'
            AND DATE_ADD('{$today}', INTERVAL 90 DAY)
        ";
        break;

    case 'no_expiry':
        $where[] = "(
            si.expiry_date IS NULL
            OR YEAR(si.expiry_date) = 0
        )";
        break;
}

$where_sql = implode(" AND ", $where);

/* ============================================================
   SUMMARY
   ============================================================ */

$summary = [
    'product_count' => 0,
    'unit_count' => 0,
    'selling_value' => 0,
    'low_stock_count' => 0,
    'expiring_soon_count' => 0
];

try {

    $summary_sql = "
        SELECT
            COUNT(*) AS product_count,
            COALESCE(SUM(quantity), 0) AS unit_count,
            COALESCE(SUM(price * quantity), 0) AS selling_value,
            COALESCE(
                SUM(
                    CASE
                        WHEN quantity BETWEEN 1 AND 10 THEN 1
                        ELSE 0
                    END
                ), 0
            ) AS low_stock_count,
            COALESCE(
                SUM(
                    CASE
                        WHEN expiry_date IS NOT NULL
                         AND YEAR(expiry_date) <> 0
                         AND expiry_date BETWEEN '{$today}'
                         AND DATE_ADD('{$today}', INTERVAL 90 DAY)
                        THEN 1
                        ELSE 0
                    END
                ), 0
            ) AS expiring_soon_count
        FROM store_items
        WHERE pharmacy_id = {$pharmacy_id}
          AND branch_id = {$branch_id}
          AND quantity > 0
          AND price > 0
          AND (
              expiry_date IS NULL
              OR YEAR(expiry_date) = 0
              OR expiry_date >= '{$today}'
          )
    ";

    $summary_result = $conn->query($summary_sql);

    if ($summary_result && ($summary_row = $summary_result->fetch_assoc())) {
        $summary = $summary_row;
    }

    if ($summary_result) {
        $summary_result->free();
    }

} catch (Throwable $e) {
    error_log('PHARMACY STOCK SUMMARY: ' . $e->getMessage());
}

/* ============================================================
   TOTAL FILTERED RECORDS
   ============================================================ */

$total_records = 0;

try {

    $count_sql = "
        SELECT COUNT(*) AS total
        FROM store_items si
        WHERE {$where_sql}
    ";

    $count_result = $conn->query($count_sql);

    if ($count_result && ($count_row = $count_result->fetch_assoc())) {
        $total_records = (int)$count_row['total'];
    }

    if ($count_result) {
        $count_result->free();
    }

} catch (Throwable $e) {
    error_log('PHARMACY STOCK COUNT: ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($total_records / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/* ============================================================
   STOCK ROWS
   ============================================================ */

$stock_rows = [];

try {

    $stock_sql = "
        SELECT
            si.id,
            si.item_name,
            si.barcode,
            si.category,
            si.strength,
            si.price,
            si.quantity,
            si.expiry_date
        FROM store_items si
        WHERE {$where_sql}
        ORDER BY
            CASE
                WHEN si.expiry_date IS NULL
                  OR YEAR(si.expiry_date) = 0
                THEN 1
                ELSE 0
            END ASC,
            si.expiry_date ASC,
            si.item_name ASC,
            si.id DESC
        LIMIT {$offset}, {$per_page}
    ";

    $stock_result = $conn->query($stock_sql);

    if ($stock_result) {

        while ($row = $stock_result->fetch_assoc()) {
            $stock_rows[] = $row;
        }

        $stock_result->free();
    }

} catch (Throwable $e) {
    error_log('PHARMACY STOCK ROWS: ' . $e->getMessage());
}

/* Existing header already knows the current pharmacy/branch. */
$pharmacy_name = (string)(
    $_SESSION['pharmacy_name']
    ?? $_SESSION['pharmacyName']
    ?? 'PHARMACY'
);

$branch_name = (string)(
    $_SESSION['branch_name']
    ?? $_SESSION['branchName']
    ?? 'Main Branch'
);

require_once "../includes/head.php";
?>

<style>
:root {
    --ps-blue: #4299cf;
    --ps-dark: #3e4f60;
    --ps-green: #198754;
    --ps-orange: #f17808;
    --ps-red: #c90d1b;
    --ps-bg: #f4f6f9;
    --ps-border: #dfe6ee;
}

.pharmacy-stock-page {
    background: var(--ps-bg);
    min-height: calc(100vh - 60px);
    padding: 15px;
}

.ps-shell {
    max-width: 1600px;
    margin: 0 auto;
}

.ps-card {
    background: #fff;
    border: 1px solid var(--ps-border);
    border-radius: 10px;
    box-shadow: 0 2px 7px rgba(31, 48, 67, .05);
}

.ps-title-icon {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    background: #e8f3fb;
    color: var(--ps-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.ps-kpi {
    min-height: 112px;
    color: #fff;
    border: 0;
    overflow: hidden;
    position: relative;
}

.ps-blue { background: linear-gradient(180deg, #449cd5, #3586ba); }
.ps-dark { background: #3f4d5b; }
.ps-green { background: #198754; }
.ps-orange { background: linear-gradient(180deg, #f17808, #ca1818); }

.ps-kpi-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
    opacity: .9;
}

.ps-kpi-value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 4px;
}

.ps-kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.ps-filter {
    padding: 15px;
}

.ps-label {
    font-size: 10px;
    font-weight: 800;
    color: #637286;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.ps-table {
    margin-bottom: 0;
}

.ps-table thead th {
    background: #f8fafc;
    color: #637286;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .35px;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 12px;
    border-bottom: 1px solid var(--ps-border);
}

.ps-table tbody td {
    padding: 12px;
    vertical-align: middle;
}

.ps-product {
    font-weight: 800;
    color: #26364a;
}

.ps-sub {
    color: #8793a2;
    font-size: 11px;
}

.ps-qty {
    min-width: 50px;
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    text-align: center;
    font-weight: 800;
}

.ps-qty-low {
    background: #fff3cd;
    color: #856404;
}

.ps-qty-good {
    background: #d1e7dd;
    color: #0f5132;
}

.ps-empty {
    padding: 55px 20px;
    text-align: center;
    color: #7c8998;
}

.ps-empty i {
    display: block;
    font-size: 38px;
    opacity: .3;
    margin-bottom: 10px;
}

.ps-modal-icon {
    width: 62px;
    height: 62px;
    border-radius: 50%;
    background: #fff0f1;
    color: var(--ps-red);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

@media (max-width: 768px) {
    .pharmacy-stock-page {
        padding: 10px;
    }

    .ps-kpi-value {
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

    .pharmacy-stock-page {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .ps-card {
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

<main class="page-wrapper pharmacy-stock-page">
<div class="ps-shell">

    <!-- PAGE HEADER -->
    <div class="ps-card p-3 mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

        <div class="d-flex align-items-center gap-3">
            <div class="ps-title-icon">
                <i class="fas fa-boxes"></i>
            </div>

            <div>
                <h3 class="mb-1 fw-bold">Pharmacy Stock</h3>

                <div class="small text-muted">
                    <strong><?= ps_h(strtoupper($pharmacy_name)) ?></strong>
                    <span class="mx-1">•</span>
                    <?= ps_h($branch_name) ?>
                </div>

                <div class="small text-muted mt-1">
                    Showing only products with available stock value. Expired and out-of-stock items are excluded.
                </div>
            </div>
        </div>

        <div class="no-print">
            <a href="update_items_stock.php" class="btn btn-outline-dark fw-bold me-1">
                <i class="fas fa-truck-loading me-1"></i>
                Restock
            </a>

            <a href="add_product.php" class="btn btn-success fw-bold me-1">
                <i class="fas fa-plus me-1"></i>
                New Product
            </a>

            <button type="button" class="btn btn-outline-primary fw-bold" onclick="window.print()">
                <i class="fas fa-print me-1"></i>
                Print
            </button>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-3">

        <div class="col-12 col-md-6 col-xl-3">
            <div class="ps-card ps-kpi ps-blue p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="ps-kpi-label">Products In Stock</div>
                        <div class="ps-kpi-value">
                            <?= number_format((int)$summary['product_count']) ?>
                        </div>
                        <small>Products with value</small>
                    </div>

                    <div class="ps-kpi-icon">
                        <i class="fas fa-pills"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="ps-card ps-kpi ps-dark p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="ps-kpi-label">Units In Stock</div>
                        <div class="ps-kpi-value">
                            <?= number_format((int)$summary['unit_count']) ?>
                        </div>
                        <small>Available units</small>
                    </div>

                    <div class="ps-kpi-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="ps-card ps-kpi ps-green p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="ps-kpi-label">Selling Value</div>
                        <div class="ps-kpi-value">
                            <?= ps_money($summary['selling_value']) ?>
                        </div>
                        <small>Current branch stock</small>
                    </div>

                    <div class="ps-kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="ps-card ps-kpi ps-orange p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="ps-kpi-label">Low Stock</div>
                        <div class="ps-kpi-value">
                            <?= number_format((int)$summary['low_stock_count']) ?>
                        </div>
                        <small>10 or fewer units</small>
                    </div>

                    <div class="ps-kpi-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- FILTERS -->
    <div class="ps-card ps-filter mb-3 no-print">
        <form method="GET" action="pharmacy_stock.php" id="stock-filter-form">

            <div class="row g-3 align-items-end">

                <div class="col-12 col-lg-4">
                    <label class="ps-label">Search Product</label>

                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="fas fa-search text-muted"></i>
                        </span>

                        <input
                            type="search"
                            class="form-control"
                            name="search"
                            id="stock-search"
                            value="<?= ps_h($search) ?>"
                            placeholder="Name, barcode, category or strength..."
                        >
                    </div>
                </div>

                <div class="col-12 col-md-4 col-lg-2">
                    <label class="ps-label">Category</label>

                    <select name="category" class="form-select">
                        <option value="">All Categories</option>

                        <?php foreach ($categories as $cat): ?>
                            <option
                                value="<?= ps_h($cat) ?>"
                                <?= $category === $cat ? 'selected' : '' ?>
                            >
                                <?= ps_h($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4 col-lg-2">
                    <label class="ps-label">Stock Level</label>

                    <select name="stock" class="form-select">
                        <option value="all" <?= $stock_filter === 'all' ? 'selected' : '' ?>>
                            All Stock
                        </option>

                        <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>
                            Low Stock
                        </option>

                        <option value="healthy" <?= $stock_filter === 'healthy' ? 'selected' : '' ?>>
                            Healthy Stock
                        </option>
                    </select>
                </div>

                <div class="col-12 col-md-4 col-lg-2">
                    <label class="ps-label">Expiry</label>

                    <select name="expiry" class="form-select">
                        <option value="all" <?= $expiry_filter === 'all' ? 'selected' : '' ?>>
                            All Valid
                        </option>

                        <option value="30" <?= $expiry_filter === '30' ? 'selected' : '' ?>>
                            Within 30 Days
                        </option>

                        <option value="60" <?= $expiry_filter === '60' ? 'selected' : '' ?>>
                            Within 60 Days
                        </option>

                        <option value="90" <?= $expiry_filter === '90' ? 'selected' : '' ?>>
                            Within 90 Days
                        </option>

                        <option value="no_expiry" <?= $expiry_filter === 'no_expiry' ? 'selected' : '' ?>>
                            No Expiry
                        </option>
                    </select>
                </div>

                <div class="col-12 col-lg-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-filter me-1"></i>
                        Apply
                    </button>
                </div>

            </div>

            <?php if ($search !== '' || $category !== '' || $stock_filter !== 'all' || $expiry_filter !== 'all'): ?>
                <div class="mt-2">
                    <a href="pharmacy_stock.php" class="small text-decoration-none">
                        <i class="fas fa-times me-1"></i>
                        Clear filters
                    </a>
                </div>
            <?php endif; ?>

        </form>
    </div>

    <!-- STOCK TABLE -->
    <div class="ps-card overflow-hidden">

        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1 fw-bold">
                    <i class="fas fa-clipboard-list text-primary me-2"></i>
                    Available Stock
                </h5>

                <div class="small text-muted">
                    <?= number_format($total_records) ?> product(s) found
                </div>
            </div>

            <div class="small text-muted">
                Page <?= $page ?> of <?= $total_pages ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table ps-table align-middle">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Stock Value</th>
                        <th>Expiry</th>
                        <th class="text-end no-print">Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!empty($stock_rows)): ?>

                    <?php $sn = $offset + 1; ?>

                    <?php foreach ($stock_rows as $row): ?>

                        <?php
                        $qty = (int)$row['quantity'];
                        $price = (float)$row['price'];
                        $stock_value = $qty * $price;

                        $expiry = $row['expiry_date'] ?? null;
                        $has_expiry = !empty($expiry) && substr((string)$expiry, 0, 4) !== '0000';

                        $days_to_expiry = null;
                        $expiry_soon = false;

                        if ($has_expiry) {
                            $expiry_timestamp = strtotime($expiry);

                            if ($expiry_timestamp !== false) {
                                $days_to_expiry = (int)floor(
                                    ($expiry_timestamp - strtotime($today)) / 86400
                                );

                                $expiry_soon =
                                    $days_to_expiry >= 0 &&
                                    $days_to_expiry <= 90;
                            }
                        }
                        ?>

                        <tr data-product-id="<?= (int)$row['id'] ?>">

                            <td class="text-muted">
                                <?= $sn++ ?>
                            </td>

                            <td>
                                <div class="ps-product">
                                    <?= ps_h($row['item_name']) ?>

                                    <?php if (!empty($row['strength'])): ?>
                                        <span class="ps-sub">
                                            (<?= ps_h($row['strength']) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($row['barcode'])): ?>
                                    <span class="ps-sub">
                                        <?= ps_h($row['barcode']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= ps_h($row['category'] ?: 'Medicine') ?>
                                </span>
                            </td>

                            <td class="fw-bold">
                                <?= ps_money($price) ?>
                            </td>

                            <td>
                                <span class="ps-qty <?= $qty <= 10 ? 'ps-qty-low' : 'ps-qty-good' ?>">
                                    <?= number_format($qty) ?>
                                </span>
                            </td>

                            <td class="fw-bold text-success">
                                <?= ps_money($stock_value) ?>
                            </td>

                            <td>
                                <?php if ($has_expiry): ?>

                                    <div class="<?= $expiry_soon ? 'text-warning fw-bold' : 'text-muted' ?>">
                                        <?= ps_h(date('d M Y', strtotime($expiry))) ?>
                                    </div>

                                    <?php if ($expiry_soon && $days_to_expiry !== null): ?>
                                        <small class="text-warning fw-bold">
                                            <i class="fas fa-clock me-1"></i>
                                            <?= $days_to_expiry ?> days
                                        </small>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="text-muted">No expiry</span>

                                <?php endif; ?>
                            </td>

                            <td class="text-end no-print">

                                <a
                                    href="update_product.php?id=<?= (int)$row['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                    title="Edit Product"
                                >
                                    <i class="fas fa-pen"></i>
                                </a>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-success adjust-stock-btn"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= ps_h($row['item_name']) ?>"
                                    data-qty="<?= $qty ?>"
                                    title="Adjust Quantity"
                                >
                                    <i class="fas fa-boxes"></i>
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger delete-stock-btn"
                                    data-id="<?= (int)$row['id'] ?>"
                                    data-name="<?= ps_h($row['item_name']) ?>"
                                    title="Delete Product"
                                >
                                    <i class="fas fa-trash"></i>
                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="ps-empty">

                            <i class="fas fa-box-open"></i>

                            <strong class="d-block">
                                No stock products found
                            </strong>

                            <div class="small mt-1">
                                Expired and out-of-stock products are intentionally
                                excluded from Pharmacy Stock.
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

                <nav>
                    <ul class="pagination justify-content-center mb-0">

                        <?php
                        $base_query = [
                            'search' => $search,
                            'category' => $category,
                            'stock' => $stock_filter,
                            'expiry' => $expiry_filter
                        ];
                        ?>

                        <?php if ($page > 1): ?>

                            <?php
                            $base_query['page'] = $page - 1;
                            ?>

                            <li class="page-item">
                                <a class="page-link" href="?<?= ps_h(http_build_query($base_query)) ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>

                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>

                            <?php
                            $base_query['page'] = $i;
                            ?>

                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= ps_h(http_build_query($base_query)) ?>">
                                    <?= $i ?>
                                </a>
                            </li>

                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>

                            <?php
                            $base_query['page'] = $page + 1;
                            ?>

                            <li class="page-item">
                                <a class="page-link" href="?<?= ps_h(http_build_query($base_query)) ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>

                        <?php endif; ?>

                    </ul>
                </nav>

            </div>

        <?php endif; ?>

        <div class="p-3 border-top">
            <div class="row align-items-center">

                <div class="col-md-6">
                    <small class="text-muted">
                        Current branch stock valuation
                    </small>
                </div>

                <div class="col-md-6 text-md-end">
                    <span class="text-muted small">
                        Selling Value:
                    </span>

                    <strong class="text-success fs-5">
                        <?= ps_money($summary['selling_value']) ?>
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

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteStockModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-body text-center p-4">

                <div class="ps-modal-icon mb-3">
                    <i class="fas fa-trash-alt"></i>
                </div>

                <h5 class="fw-bold">Delete Product?</h5>

                <p class="text-muted mb-1">
                    You are about to permanently remove:
                </p>

                <div id="delete-product-name" class="fw-bold mb-3"></div>

                <div class="alert alert-warning text-start small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    This action permanently removes the product from this branch.
                </div>

                <input type="hidden" id="delete-product-id">

                <button
                    type="button"
                    class="btn btn-light border fw-bold me-2"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-danger fw-bold"
                    id="confirm-delete-stock"
                >
                    <i class="fas fa-trash-alt me-1"></i>
                    Delete Product
                </button>

            </div>

        </div>

    </div>
</div>

<!-- ADJUST STOCK MODAL -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form id="adjust-stock-form">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-boxes text-success me-2"></i>
                        Adjust Stock
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" id="adjust-product-id">

                    <label class="ps-label">Product</label>
                    <div id="adjust-product-name" class="fw-bold mb-3"></div>

                    <label class="ps-label">New Quantity</label>

                    <input
                        type="number"
                        id="adjust-quantity"
                        class="form-control"
                        min="0"
                        step="1"
                        required
                    >

                    <div id="adjust-error" class="alert alert-danger d-none mt-3"></div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light border fw-bold"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-success fw-bold"
                        id="confirm-adjust-stock"
                    >
                        <i class="fas fa-save me-1"></i>
                        Save Quantity
                    </button>

                </div>

            </form>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    'use strict';

    const deleteModalEl = document.getElementById('deleteStockModal');
    const adjustModalEl = document.getElementById('adjustStockModal');

    const deleteModal = deleteModalEl
        ? new bootstrap.Modal(deleteModalEl)
        : null;

    const adjustModal = adjustModalEl
        ? new bootstrap.Modal(adjustModalEl)
        : null;

    async function sendStockAction(action, values) {

        const formData = new FormData();
        formData.append('action', action);

        Object.keys(values || {}).forEach(function (key) {
            formData.append(key, values[key]);
        });

        const response = await fetch('pharmacy_stock.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const text = await response.text();

        let data;

        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Server response:', text);
            throw new Error('The server returned an invalid response.');
        }

        if (!response.ok || data.status !== 'success') {
            throw new Error(data.message || 'The operation failed.');
        }

        return data;
    }

    document.querySelectorAll('.delete-stock-btn').forEach(function (button) {

        button.addEventListener('click', function () {

            document.getElementById('delete-product-id').value =
                this.dataset.id;

            document.getElementById('delete-product-name').textContent =
                this.dataset.name;

            if (deleteModal) {
                deleteModal.show();
            }
        });
    });

    const confirmDelete = document.getElementById('confirm-delete-stock');

    if (confirmDelete) {

        confirmDelete.addEventListener('click', async function () {

            const id =
                document.getElementById('delete-product-id').value;

            if (!id) return;

            const button = this;

            button.disabled = true;
            button.innerHTML =
                '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';

            try {

                await sendStockAction('delete', { id: id });

                if (deleteModal) {
                    deleteModal.hide();
                }

                window.location.reload();

            } catch (error) {

                alert(error.message || 'Unable to delete product.');

            } finally {

                button.disabled = false;
                button.innerHTML =
                    '<i class="fas fa-trash-alt me-1"></i>Delete Product';
            }
        });
    }

    document.querySelectorAll('.adjust-stock-btn').forEach(function (button) {

        button.addEventListener('click', function () {

            document.getElementById('adjust-product-id').value =
                this.dataset.id;

            document.getElementById('adjust-product-name').textContent =
                this.dataset.name;

            document.getElementById('adjust-quantity').value =
                this.dataset.qty;

            document.getElementById('adjust-error').classList.add('d-none');

            if (adjustModal) {
                adjustModal.show();
            }
        });
    });

    const adjustForm = document.getElementById('adjust-stock-form');

    if (adjustForm) {

        adjustForm.addEventListener('submit', async function (event) {

            event.preventDefault();

            const button =
                document.getElementById('confirm-adjust-stock');

            const errorBox =
                document.getElementById('adjust-error');

            const id =
                document.getElementById('adjust-product-id').value;

            const quantity =
                document.getElementById('adjust-quantity').value;

            errorBox.classList.add('d-none');

            button.disabled = true;
            button.innerHTML =
                '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

            try {

                await sendStockAction('adjust_stock', {
                    id: id,
                    quantity: quantity
                });

                if (adjustModal) {
                    adjustModal.hide();
                }

                window.location.reload();

            } catch (error) {

                errorBox.textContent =
                    error.message || 'Unable to update stock.';

                errorBox.classList.remove('d-none');

            } finally {

                button.disabled = false;
                button.innerHTML =
                    '<i class="fas fa-save me-1"></i>Save Quantity';
            }
        });
    }

})();
</script>

</body>
</html>
