<?php
declare(strict_types=1);

/**
 * ============================================================
 * PHARMANOVA POS
 * PHARMACY STOCK â€” CONSOLIDATED VERSION
 * ============================================================
 *
 * ONE FILE ONLY:
 *   dashboard/pharmacy_stock.php
 *
 * This page intentionally displays ONLY stock that has value:
 *   - quantity > 0
 *   - selling price > 0
 *   - not expired
 *   - active products
 *
 * Expired and out-of-stock products remain on their dedicated
 * pages and are therefore NOT displayed here.
 *
 * The search, filtering, deletion and stock summary logic are
 * handled in this file. No fetch_products.php or
 * delete_product_inc.php dependency is required.
 * ============================================================
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    header("Location: ../index.php?error=session_expired");
    exit;
}

/* ============================================================
   HELPERS
============================================================ */

function stock_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function money(float $amount): string
{
    return 'K ' . number_format($amount, 2);
}

/* ============================================================
   CONSOLIDATED AJAX ACTIONS
============================================================ */

$action = trim(
    (string)($_POST['action'] ?? $_GET['action'] ?? '')
);

/*
 * DELETE PRODUCT
 *
 * This deliberately uses pharmacy + branch restrictions so one
 * tenant cannot delete another tenant's stock.
 */
if ($action === 'delete') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        stock_json([
            'status'  => 'error',
            'message' => 'Invalid request method.'
        ], 405);
    }

    $product_id = (int)($_POST['id'] ?? 0);

    if ($product_id <= 0) {
        stock_json([
            'status'  => 'error',
            'message' => 'Invalid product.'
        ], 422);
    }

    $stmt = $conn->prepare("
        SELECT id, item_name, quantity
        FROM store_items
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        stock_json([
            'status'  => 'error',
            'message' => 'Unable to prepare product lookup.'
        ], 500);
    }

    $stmt->bind_param(
        "iii",
        $product_id,
        $pharmacy_id,
        $branch_id
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    $stmt->close();

    if (!$product) {
        stock_json([
            'status'  => 'error',
            'message' => 'Product not found or access denied.'
        ], 404);
    }

    /*
     * Keep deletion intentionally explicit.
     * This is a hard delete, matching the existing page's
     * behaviour, but it remains tenant-scoped.
     */
    $stmt = $conn->prepare("
        DELETE FROM store_items
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        stock_json([
            'status'  => 'error',
            'message' => 'Unable to prepare product deletion.'
        ], 500);
    }

    $stmt->bind_param(
        "iii",
        $product_id,
        $pharmacy_id,
        $branch_id
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        error_log(
            "PHARMACY STOCK DELETE: " . $error
        );

        stock_json([
            'status'  => 'error',
            'message' => 'Unable to delete the product.'
        ], 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        stock_json([
            'status'  => 'error',
            'message' => 'Product was not deleted.'
        ], 500);
    }

    stock_json([
        'status'  => 'success',
        'message' => 'Product deleted successfully.',
        'id'      => $product_id
    ]);
}

/* ============================================================
   UPDATE STOCK QUANTITY
 *
 * Optional consolidated quick-adjust action.
 * It adjusts the current quantity without creating another file.
============================================================ */

if ($action === 'adjust_stock') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        stock_json([
            'status'  => 'error',
            'message' => 'Invalid request method.'
        ], 405);
    }

    $product_id = (int)($_POST['id'] ?? 0);
    $new_qty    = filter_var(
        $_POST['quantity'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $product_id <= 0 ||
        $new_qty === false ||
        $new_qty < 0
    ) {
        stock_json([
            'status'  => 'error',
            'message' => 'Enter a valid stock quantity.'
        ], 422);
    }

    $stmt = $conn->prepare("
        UPDATE store_items
        SET quantity = ?
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        stock_json([
            'status'  => 'error',
            'message' => 'Unable to prepare stock update.'
        ], 500);
    }

    $stmt->bind_param(
        "iiii",
        $new_qty,
        $product_id,
        $pharmacy_id,
        $branch_id
    );

    if (!$stmt->execute()) {
        $stmt->close();

        stock_json([
            'status'  => 'error',
            'message' => 'Unable to update stock quantity.'
        ], 500);
    }

    $stmt->close();

    stock_json([
        'status'   => 'success',
        'message'  => 'Stock quantity updated.',
        'quantity' => $new_qty
    ]);
}

/* ============================================================
   PAGE DATA
============================================================ */

$current_date = date('Y-m-d');

/*
 * Search/filter values.
 */
$search = trim(
    (string)($_GET['search'] ?? '')
);

$category_filter = trim(
    (string)($_GET['category'] ?? '')
);

$stock_filter = trim(
    (string)($_GET['stock'] ?? 'all')
);

$expiry_filter = trim(
    (string)($_GET['expiry'] ?? 'all')
);

$page = max(
    1,
    (int)($_GET['page'] ?? 1)
);

$per_page = 20;

/*
 * Categories for the filter.
 */
$categories = [];

$stmt = $conn->prepare("
    SELECT DISTINCT category
    FROM store_items
    WHERE pharmacy_id = ?
      AND branch_id = ?
      AND category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
");

if ($stmt) {

    $stmt->bind_param(
        "ii",
        $pharmacy_id,
        $branch_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $categories[] = (string)$row['category'];
    }

    $stmt->close();
}

/* ============================================================
   BASE DISPLAY CONDITION
 *
 * Pharmacy Stock is intentionally ONLY:
 *   quantity > 0
 *   price > 0
 *   active
 *   not expired
============================================================ */

$where = [
    "si.pharmacy_id = ?",
    "si.branch_id = ?",
    "si.is_active = 1",
    "si.quantity > 0",
    "si.price > 0",
    "(
        si.expiry_date IS NULL
        OR si.expiry_date = '0000-00-00'
        OR si.expiry_date >= ?
    )"
];

$params = [
    $pharmacy_id,
    $branch_id,
    $current_date
];

$types = "iis";

/* ============================================================
   SEARCH
============================================================ */

if ($search !== '') {

    $where[] = "
        (
            si.item_name LIKE ?
            OR si.barcode LIKE ?
        )
    ";

    $search_like = '%' . $search . '%';

    $params[] = $search_like;
    $params[] = $search_like;

    $types .= "ss";
}

/* ============================================================
   CATEGORY
============================================================ */

if ($category_filter !== '') {

    $where[] = "si.category = ?";

    $params[] = $category_filter;

    $types .= "s";
}

/* ============================================================
   STOCK FILTER
 *
 * Low stock = 10 or fewer.
 * Healthy = more than 10.
============================================================ */

if ($stock_filter === 'low') {

    $where[] = "si.quantity BETWEEN 1 AND 10";

} elseif ($stock_filter === 'healthy') {

    $where[] = "si.quantity > 10";
}

/* ============================================================
   EXPIRY FILTER
============================================================ */

if ($expiry_filter === 'no_expiry') {

    $where[] = "
        (
            si.expiry_date IS NULL
            OR si.expiry_date = '0000-00-00'
        )
    ";

} elseif ($expiry_filter === 'soon') {

    $expiry_limit = date(
        'Y-m-d',
        strtotime('+90 days')
    );

    $where[] = "
        si.expiry_date IS NOT NULL
        AND si.expiry_date <> '0000-00-00'
        AND si.expiry_date BETWEEN ? AND ?
    ";

    $params[] = $current_date;
    $params[] = $expiry_limit;

    $types .= "ss";
}

/* ============================================================
   WHERE SQL
============================================================ */

$where_sql = implode(
    " AND ",
    $where
);

/* ============================================================
   GLOBAL STOCK SUMMARY
 *
 * These figures are for the same valid stock pool:
 * active + quantity > 0 + price > 0 + not expired.
============================================================ */

$summary_sql = "
    SELECT
        COUNT(*) AS product_count,
        COALESCE(SUM(quantity), 0) AS unit_count,
        COALESCE(SUM(price * quantity), 0) AS selling_value,
        COALESCE(SUM(COALESCE(cost, 0) * quantity), 0) AS cost_value,
        COALESCE(
            SUM(
                CASE
                    WHEN quantity BETWEEN 1 AND 10
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock_count,
        COALESCE(
            SUM(
                CASE
                    WHEN expiry_date IS NOT NULL
                     AND expiry_date <> '0000-00-00'
                     AND expiry_date <= DATE_ADD(?, INTERVAL 90 DAY)
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS expiring_soon_count
    FROM store_items
    WHERE pharmacy_id = ?
      AND branch_id = ?
      AND is_active = 1
      AND quantity > 0
      AND price > 0
      AND (
          expiry_date IS NULL
          OR expiry_date = '0000-00-00'
          OR expiry_date >= ?
      )
";

$summary = [
    'product_count'      => 0,
    'unit_count'         => 0,
    'selling_value'      => 0,
    'cost_value'         => 0,
    'low_stock_count'    => 0,
    'expiring_soon_count'=> 0
];

$stmt = $conn->prepare($summary_sql);

if ($stmt) {

    $stmt->bind_param(
        "siis",
        $current_date,
        $pharmacy_id,
        $branch_id,
        $current_date
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $summary = $row;
        }
    }

    $stmt->close();
}

/* ============================================================
   FILTERED COUNT
============================================================ */

$count_sql = "
    SELECT COUNT(*) AS total
    FROM store_items si
    WHERE {$where_sql}
";

$stmt = $conn->prepare($count_sql);

$total_records = 0;

if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $total_records = (int)$row['total'];
        }
    }

    $stmt->close();
}

$total_pages = max(
    1,
    (int)ceil(
        $total_records / $per_page
    )
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = (
    $page - 1
) * $per_page;

/* ============================================================
   FILTERED STOCK QUERY
============================================================ */

$sql = "
    SELECT
        si.id,
        si.item_name,
        si.barcode,
        si.category,
        si.price,
        si.cost,
        si.quantity,
        si.expiry_date,
        si.image_path AS image,
        si.is_active
    FROM store_items si
    WHERE {$where_sql}
    ORDER BY
        si.item_name ASC,
        si.id DESC
    LIMIT ?, ?
";

$params_with_limit = $params;
$params_with_limit[] = $offset;
$params_with_limit[] = $per_page;

$types_with_limit = $types . "ii";

$stock_rows = [];

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        $types_with_limit,
        ...$params_with_limit
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $stock_rows[] = $row;
        }
    }

    $stmt->close();
}

/* ============================================================
   DISPLAY DETAILS
============================================================ */

$branch_name = "Our Branch";

$stmt = $conn->prepare("
    SELECT branch_name
    FROM branches
    WHERE id = ?
      AND pharmacy_id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "ii",
        $branch_id,
        $pharmacy_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $branch_name = $row['branch_name'];
    }

    $stmt->close();
}

$pharmacy_name = "PHARMACY";

$stmt = $conn->prepare("
    SELECT name
    FROM pharmacies
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $pharmacy_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $pharmacy_name = $row['name'];
    }

    $stmt->close();
}

require_once "../includes/head.php";
?>

<style>
/* ============================================================
   PHARMACY STOCK UI
============================================================ */

:root {
    --stock-blue: #1677ff;
    --stock-dark: #33475b;
    --stock-green: #198754;
    --stock-orange: #ff9800;
    --stock-red: #dc3545;
    --stock-bg: #f1f4f8;
    --stock-border: #e1e7ef;
}

.stock-page {
    min-height: calc(100vh - 70px);
    background: var(--stock-bg);
    padding: 18px;
}

.stock-shell {
    max-width: 1600px;
    margin: 0 auto;
}

.stock-card {
    background: #fff;
    border: 1px solid var(--stock-border);
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(25, 45, 70, .05);
}

.stock-heading {
    padding: 16px 18px;
}

.stock-heading-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #eaf3ff;
    color: var(--stock-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.stock-kpi {
    min-height: 118px;
    color: #fff;
    border: 0;
    position: relative;
    overflow: hidden;
}

.stock-kpi-blue {
    background: #4299cf;
}

.stock-kpi-dark {
    background: #3e4f60;
}

.stock-kpi-green {
    background: #198754;
}

.stock-kpi-orange {
    background: linear-gradient(
        135deg,
        #ff9800,
        #ef6c00
    );
}

.stock-kpi-red {
    background: #dc3545;
}

.stock-kpi-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .55px;
    opacity: .88;
}

.stock-kpi-value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 5px;
}

.stock-kpi-icon {
    width: 43px;
    height: 43px;
    border-radius: 11px;
    background: rgba(255, 255, 255, .18);
    display: flex;
    align-items: center;
    justify-content: center;
}

.stock-filter {
    padding: 16px;
}

.stock-label {
    font-size: 10px;
    font-weight: 800;
    color: #657487;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.form-control,
.form-select {
    min-height: 40px;
    border-color: #d7e0ea;
    border-radius: 7px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--stock-blue);
    box-shadow: 0 0 0 .18rem rgba(22, 119, 255, .10);
}

.stock-table {
    margin-bottom: 0;
}

.stock-table thead th {
    background: #f8fafc;
    color: #68778a;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .35px;
    font-weight: 800;
    white-space: nowrap;
    padding: 12px;
}

.stock-table tbody td {
    padding: 12px;
    vertical-align: middle;
}

.product-name {
    font-weight: 800;
    color: #26364a;
}

.product-barcode {
    font-size: 11px;
    color: #8a96a5;
}

.category-badge {
    background: #f4f7fa;
    border: 1px solid #e1e7ef;
    color: #526174;
    font-weight: 700;
}

.quantity-badge {
    min-width: 54px;
    display: inline-block;
    text-align: center;
    padding: 6px 10px;
    border-radius: 7px;
    font-weight: 800;
}

.quantity-good {
    background: #d1e7dd;
    color: #0f5132;
}

.quantity-low {
    background: #fff3cd;
    color: #856404;
}

.expiry-normal {
    color: #526174;
    font-size: 12px;
}

.expiry-soon {
    color: #c76a00;
    font-weight: 800;
}

.stock-empty-filter {
    padding: 55px 20px;
    text-align: center;
    color: #7b8795;
}

.stock-empty-filter i {
    font-size: 38px;
    opacity: .35;
    margin-bottom: 12px;
}

.pagination .page-link {
    border-radius: 6px;
    margin: 0 2px;
    border-color: #dbe3ed;
}

.pagination .page-item.active .page-link {
    background: var(--stock-blue);
    border-color: var(--stock-blue);
}

.modal-content {
    border: 0;
    border-radius: 14px;
}

.modal-confirm-icon {
    width: 62px;
    height: 62px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff0f1;
    color: var(--stock-red);
    font-size: 24px;
}

@media (max-width: 768px) {
    .stock-page {
        padding: 10px;
    }

    .stock-kpi-value {
        font-size: 21px;
    }

    .stock-heading {
        padding: 13px;
    }
}

@media print {
    #header,
    #aside,
    nav,
    footer,
    .no-print {
        display: none !important;
    }

    .stock-page {
        padding: 0 !important;
        background: #fff !important;
    }

    .stock-card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .stock-table {
        font-size: 10px;
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

<main class="page-wrapper stock-page">

<div class="stock-shell">

    <!-- ======================================================
         PAGE HEADER
    ======================================================= -->

    <div class="stock-card stock-heading mb-3
                d-flex flex-column flex-lg-row
                justify-content-between
                align-items-lg-center gap-3">

        <div class="d-flex align-items-center gap-3">

            <div class="stock-heading-icon">
                <i class="fas fa-boxes"></i>
            </div>

            <div>

                <h3 class="mb-1 fw-bold">
                    Pharmacy Stock
                </h3>

                <div class="small text-muted">

                    <strong>
                        <?= h(strtoupper($pharmacy_name)) ?>
                    </strong>

                    <span class="mx-1">â€¢</span>

                    <?= h($branch_name) ?>

                </div>

            </div>

        </div>

        <div class="no-print">

            <a
                href="update_items_stock.php"
                class="btn btn-outline-dark fw-bold me-1"
            >
                <i class="fas fa-truck-loading me-1"></i>
                Restock
            </a>

            <a
                href="add_product.php"
                class="btn btn-success fw-bold"
            >
                <i class="fas fa-plus me-1"></i>
                New Product
            </a>

            <button
                type="button"
                class="btn btn-outline-primary fw-bold ms-1"
                onclick="window.print()"
            >
                <i class="fas fa-print me-1"></i>
                Print
            </button>

        </div>

    </div>


    <!-- ======================================================
         KPI CARDS
    ======================================================= -->

    <div class="row g-3 mb-3">

        <div class="col-12 col-md-6 col-xl-3">

            <div class="stock-card stock-kpi stock-kpi-blue p-3">

                <div class="d-flex justify-content-between">

                    <div>

                        <div class="stock-kpi-label">
                            Products In Stock
                        </div>

                        <div class="stock-kpi-value">
                            <?= number_format((int)$summary['product_count']) ?>
                        </div>

                        <small>
                            Active products with value
                        </small>

                    </div>

                    <div class="stock-kpi-icon">
                        <i class="fas fa-pills"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stock-card stock-kpi stock-kpi-dark p-3">

                <div class="d-flex justify-content-between">

                    <div>

                        <div class="stock-kpi-label">
                            Units In Stock
                        </div>

                        <div class="stock-kpi-value">
                            <?= number_format((int)$summary['unit_count']) ?>
                        </div>

                        <small>
                            Available units
                        </small>

                    </div>

                    <div class="stock-kpi-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stock-card stock-kpi stock-kpi-green p-3">

                <div class="d-flex justify-content-between">

                    <div>

                        <div class="stock-kpi-label">
                            Selling Value
                        </div>

                        <div class="stock-kpi-value">
                            <?= money((float)$summary['selling_value']) ?>
                        </div>

                        <small>
                            Current branch stock
                        </small>

                    </div>

                    <div class="stock-kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stock-card stock-kpi stock-kpi-orange p-3">

                <div class="d-flex justify-content-between">

                    <div>

                        <div class="stock-kpi-label">
                            Cost Value
                        </div>

                        <div class="stock-kpi-value">
                            <?= money((float)$summary['cost_value']) ?>
                        </div>

                        <small>
                            Estimated acquisition value
                        </small>

                    </div>

                    <div class="stock-kpi-icon">
                        <i class="fas fa-calculator"></i>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ======================================================
         SECONDARY STOCK INDICATORS
    ======================================================= -->

    <div class="row g-3 mb-3">

        <div class="col-12 col-md-6">

            <div class="stock-card p-3">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="text-muted small">
                            Low Stock
                        </div>

                        <div class="fw-bold fs-5 text-warning">
                            <?= number_format((int)$summary['low_stock_count']) ?>
                        </div>
                    </div>

                    <div class="text-warning fs-4">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>

                </div>

                <div class="small text-muted mt-1">
                    Products with 10 or fewer units.
                </div>

            </div>

        </div>


        <div class="col-12 col-md-6">

            <div class="stock-card p-3">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <div class="text-muted small">
                            Expiring Within 90 Days
                        </div>

                        <div class="fw-bold fs-5 text-danger">
                            <?= number_format((int)$summary['expiring_soon_count']) ?>
                        </div>
                    </div>

                    <div class="text-danger fs-4">
                        <i class="fas fa-calendar-times"></i>
                    </div>

                </div>

                <div class="small text-muted mt-1">
                    Still displayed here because they have stock value.
                </div>

            </div>

        </div>

    </div>


    <!-- ======================================================
         FILTERS
    ======================================================= -->

    <div class="stock-card stock-filter mb-3 no-print">

        <form
            method="GET"
            action="pharmacy_stock.php"
            id="stock-filter-form"
        >

            <div class="row g-3 align-items-end">

                <div class="col-12 col-lg-4">

                    <label class="stock-label">
                        Search Product
                    </label>

                    <div class="input-group">

                        <span class="input-group-text bg-white">
                            <i class="fas fa-search text-muted"></i>
                        </span>

                        <input
                            type="text"
                            name="search"
                            id="stock-search"
                            class="form-control"
                            value="<?= h($search) ?>"
                            placeholder="Product name or barcode..."
                        >

                    </div>

                </div>


                <div class="col-12 col-md-4 col-lg-2">

                    <label class="stock-label">
                        Category
                    </label>

                    <select
                        name="category"
                        class="form-select"
                    >

                        <option value="">
                            All Categories
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?= h($category) ?>"
                                <?= $category_filter === $category ? 'selected' : '' ?>
                            >
                                <?= h($category) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-12 col-md-4 col-lg-2">

                    <label class="stock-label">
                        Stock Level
                    </label>

                    <select
                        name="stock"
                        class="form-select"
                    >

                        <option value="all"
                            <?= $stock_filter === 'all' ? 'selected' : '' ?>>
                            All Stock
                        </option>

                        <option value="low"
                            <?= $stock_filter === 'low' ? 'selected' : '' ?>>
                            Low Stock
                        </option>

                        <option value="healthy"
                            <?= $stock_filter === 'healthy' ? 'selected' : '' ?>>
                            Healthy Stock
                        </option>

                    </select>

                </div>


                <div class="col-12 col-md-4 col-lg-2">

                    <label class="stock-label">
                        Expiry
                    </label>

                    <select
                        name="expiry"
                        class="form-select"
                    >

                        <option value="all"
                            <?= $expiry_filter === 'all' ? 'selected' : '' ?>>
                            All
                        </option>

                        <option value="soon"
                            <?= $expiry_filter === 'soon' ? 'selected' : '' ?>>
                            Within 90 Days
                        </option>

                        <option value="no_expiry"
                            <?= $expiry_filter === 'no_expiry' ? 'selected' : '' ?>>
                            No Expiry
                        </option>

                    </select>

                </div>


                <div class="col-12 col-lg-2 d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary fw-bold flex-fill"
                    >
                        <i class="fas fa-filter me-1"></i>
                        Filter
                    </button>

                    <a
                        href="pharmacy_stock.php"
                        class="btn btn-light border fw-bold"
                        title="Reset"
                    >
                        <i class="fas fa-redo"></i>
                    </a>

                </div>

            </div>

        </form>

    </div>


    <!-- ======================================================
         TABLE
    ======================================================= -->

    <div class="stock-card">

        <div class="p-3 border-bottom">

            <div class="d-flex flex-column flex-md-row
                        justify-content-between
                        align-items-md-center gap-2">

                <div>

                    <h5 class="fw-bold mb-1">
                        Live Stock
                    </h5>

                    <small class="text-muted">
                        Showing only active, non-expired products
                        with quantity and selling value.
                    </small>

                </div>

                <span class="badge bg-light text-primary border">
                    <?= number_format($total_records) ?> product(s)
                </span>

            </div>

        </div>


        <div class="table-responsive">

            <table class="table stock-table align-middle">

                <thead>

                    <tr>

                        <th>#</th>

                        <th>
                            Product
                        </th>

                        <th>
                            Barcode
                        </th>

                        <th>
                            Category
                        </th>

                        <th>
                            Cost
                        </th>

                        <th>
                            Selling Price
                        </th>

                        <th>
                            Quantity
                        </th>

                        <th>
                            Stock Value
                        </th>

                        <th>
                            Expiry
                        </th>

                        <th class="text-end no-print">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody id="stock-table-body">

                <?php if (count($stock_rows) > 0): ?>

                    <?php

                    $sn = $offset + 1;

                    foreach ($stock_rows as $row):

                        $price = (float)$row['price'];
                        $cost  = (float)($row['cost'] ?? 0);
                        $qty   = (int)$row['quantity'];

                        $stock_value =
                            $price * $qty;

                        $expiry = $row['expiry_date'];

                        $has_expiry =
                            !empty($expiry) &&
                            $expiry !== '0000-00-00';

                        $expiry_timestamp =
                            $has_expiry
                                ? strtotime($expiry)
                                : false;

                        $days_to_expiry =
                            $expiry_timestamp !== false
                                ? (int)floor(
                                    (
                                        $expiry_timestamp -
                                        strtotime($current_date)
                                    ) / 86400
                                )
                                : null;

                        $low_stock =
                            $qty <= 10;

                        $expiry_soon =
                            $days_to_expiry !== null &&
                            $days_to_expiry >= 0 &&
                            $days_to_expiry <= 90;

                    ?>

                    <tr
                        data-product-id="<?= (int)$row['id'] ?>"
                    >

                        <td class="text-muted">
                            <?= $sn++ ?>
                        </td>


                        <td>

                            <div class="product-name">
                                <?= h($row['item_name']) ?>
                            </div>

                            <?php if (!empty($row['image'])): ?>

                                <small class="text-muted">
                                    <i class="fas fa-image me-1"></i>
                                    Image available
                                </small>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php if (!empty($row['barcode'])): ?>

                                <span class="product-barcode">
                                    <?= h($row['barcode']) ?>
                                </span>

                            <?php else: ?>

                                <span class="text-muted">
                                    â€”
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <span class="badge category-badge">
                                <?= h($row['category'] ?: 'Medicine') ?>
                            </span>

                        </td>


                        <td class="fw-semibold">

                            <?= money($cost) ?>

                        </td>


                        <td class="fw-bold">

                            <?= money($price) ?>

                        </td>


                        <td>

                            <span class="quantity-badge
                                <?= $low_stock
                                    ? 'quantity-low'
                                    : 'quantity-good'
                                ?>"
                            >
                                <?= number_format($qty) ?>
                            </span>

                        </td>


                        <td class="fw-bold text-success">

                            <?= money($stock_value) ?>

                        </td>


                        <td>

                            <?php if ($has_expiry): ?>

                                <div class="
                                    <?= $expiry_soon
                                        ? 'expiry-soon'
                                        : 'expiry-normal'
                                    ?>
                                ">

                                    <?= h(
                                        date(
                                            'd M Y',
                                            $expiry_timestamp
                                        )
                                    ) ?>

                                </div>

                                <?php if ($expiry_soon): ?>

                                    <small class="text-warning fw-bold">
                                        <i class="fas fa-clock me-1"></i>
                                        <?= $days_to_expiry ?> days
                                    </small>

                                <?php endif; ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    No expiry
                                </span>

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
                                data-name="<?= h($row['item_name']) ?>"
                                data-qty="<?= $qty ?>"
                                title="Adjust Quantity"
                            >
                                <i class="fas fa-boxes"></i>
                            </button>


                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger delete-stock-btn"
                                data-id="<?= (int)$row['id'] ?>"
                                data-name="<?= h($row['item_name']) ?>"
                                title="Delete Product"
                            >
                                <i class="fas fa-trash"></i>
                            </button>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="10"
                            class="stock-empty-filter"
                        >

                            <i class="fas fa-box-open d-block"></i>

                            <strong>
                                No stock products found
                            </strong>

                            <div class="small mt-1">
                                Expired and out-of-stock products
                                are intentionally excluded from this page.
                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- ==================================================
             PAGINATION
        =================================================== -->

        <?php if ($total_pages > 1): ?>

            <div class="p-3 border-top no-print">

                <nav>

                    <ul class="pagination
                               justify-content-center
                               mb-0">

                        <?php

                        $query_base = [
                            'search'   => $search,
                            'category' => $category_filter,
                            'stock'    => $stock_filter,
                            'expiry'   => $expiry_filter
                        ];

                        ?>

                        <?php if ($page > 1): ?>

                            <?php

                            $query_base['page'] =
                                $page - 1;

                            ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="?<?= h(http_build_query($query_base)) ?>"
                                >
                                    <i class="fas fa-chevron-left"></i>
                                </a>

                            </li>

                        <?php endif; ?>


                        <?php

                        $start_page =
                            max(
                                1,
                                $page - 2
                            );

                        $end_page =
                            min(
                                $total_pages,
                                $page + 2
                            );

                        for (
                            $i = $start_page;
                            $i <= $end_page;
                            $i++
                        ):

                            $query_base['page'] = $i;

                        ?>

                            <li
                                class="page-item
                                    <?= $i === $page
                                        ? 'active'
                                        : ''
                                    ?>"
                            >

                                <a
                                    class="page-link"
                                    href="?<?= h(http_build_query($query_base)) ?>"
                                >
                                    <?= $i ?>
                                </a>

                            </li>

                        <?php endfor; ?>


                        <?php if ($page < $total_pages): ?>

                            <?php

                            $query_base['page'] =
                                $page + 1;

                            ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="?<?= h(http_build_query($query_base)) ?>"
                                >
                                    <i class="fas fa-chevron-right"></i>
                                </a>

                            </li>

                        <?php endif; ?>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             FOOTER VALUE
        =================================================== -->

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
                        <?= money(
                            (float)$summary['selling_value']
                        ) ?>
                    </strong>

                    <span class="text-muted small ms-3">
                        Cost Value:
                    </span>

                    <strong class="text-dark fs-5">
                        <?= money(
                            (float)$summary['cost_value']
                        ) ?>
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


<!-- ============================================================
     DELETE CONFIRMATION MODAL
============================================================ -->

<div
    class="modal fade"
    id="deleteStockModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-body text-center p-4">

                <div class="modal-confirm-icon mb-3">

                    <i class="fas fa-trash-alt"></i>

                </div>

                <h5 class="fw-bold">
                    Delete Product?
                </h5>

                <p class="text-muted mb-1">
                    You are about to permanently remove:
                </p>

                <div
                    id="delete-product-name"
                    class="fw-bold text-dark mb-3"
                >
                </div>

                <div class="alert alert-warning small text-start">

                    <i class="fas fa-exclamation-triangle me-1"></i>

                    This removes the product from this
                    pharmacy branch. This action cannot be undone.

                </div>

                <input
                    type="hidden"
                    id="delete-product-id"
                >

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


<!-- ============================================================
     STOCK ADJUSTMENT MODAL
============================================================ -->

<div
    class="modal fade"
    id="adjustStockModal"
    tabindex="-1"
    aria-hidden="true"
>

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

                    <input
                        type="hidden"
                        id="adjust-product-id"
                    >

                    <div class="mb-3">

                        <label class="stock-label">
                            Product
                        </label>

                        <div
                            id="adjust-product-name"
                            class="fw-bold text-dark"
                        >
                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="stock-label">
                            New Quantity
                        </label>

                        <input
                            type="number"
                            id="adjust-quantity"
                            class="form-control"
                            min="0"
                            step="1"
                            required
                        >

                    </div>


                    <div
                        id="adjust-error"
                        class="alert alert-danger d-none"
                    >
                    </div>

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
(() => {

    'use strict';

    const deleteModal =
        new bootstrap.Modal(
            document.getElementById(
                'deleteStockModal'
            )
        );

    const adjustModal =
        new bootstrap.Modal(
            document.getElementById(
                'adjustStockModal'
            )
        );


    function escapeHtml(value) {

        return String(value ?? '')
            .replace(
                /[&<>"']/g,
                function (character) {

                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    }[character];

                }
            );

    }


    async function sendAction(
        action,
        values = {}
    ) {

        const formData =
            new FormData();

        formData.append(
            'action',
            action
        );

        Object.entries(values)
            .forEach(
                ([key, value]) => {

                    formData.append(
                        key,
                        value
                    );

                }
            );

        const response =
            await fetch(
                'pharmacy_stock.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',
                        'Accept':
                            'application/json'
                    }
                }
            );

        const text =
            await response.text();

        let data;

        try {

            data =
                JSON.parse(text);

        } catch (error) {

            console.error(
                'Invalid server response:',
                text
            );

            throw new Error(
                'The server returned an invalid response.'
            );

        }

        if (
            !response.ok ||
            data.status !== 'success'
        ) {

            throw new Error(
                data.message ||
                'The operation failed.'
            );

        }

        return data;

    }


    /* ========================================================
       DELETE
    ======================================================== */

    document
        .querySelectorAll(
            '.delete-stock-btn'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        document
                            .getElementById(
                                'delete-product-id'
                            )
                            .value =
                            this.dataset.id;

                        document
                            .getElementById(
                                'delete-product-name'
                            )
                            .textContent =
                            this.dataset.name;

                        deleteModal.show();

                    }
                );

            }
        );


    document
        .getElementById(
            'confirm-delete-stock'
        )
        .addEventListener(
            'click',
            async function () {

                const button = this;

                const id =
                    document
                        .getElementById(
                            'delete-product-id'
                        )
                        .value;

                if (!id) {
                    return;
                }

                button.disabled = true;

                button.innerHTML =
                    '<i class="fas fa-spinner fa-spin me-1"></i>' +
                    'Deleting...';

                try {

                    await sendAction(
                        'delete',
                        {
                            id: id
                        }
                    );

                    deleteModal.hide();

                    const row =
                        document.querySelector(
                            'tr[data-product-id="' +
                            CSS.escape(String(id)) +
                            '"]'
                        );

                    if (row) {

                        row.style.transition =
                            'opacity .25s ease';

                        row.style.opacity = '0';

                        setTimeout(
                            function () {

                                window.location.reload();

                            },
                            250
                        );

                    } else {

                        window.location.reload();

                    }

                } catch (error) {

                    alert(
                        error.message ||
                        'Unable to delete product.'
                    );

                } finally {

                    button.disabled = false;

                    button.innerHTML =
                        '<i class="fas fa-trash-alt me-1"></i>' +
                        'Delete Product';

                }

            }
        );


    /* ========================================================
       STOCK ADJUSTMENT
    ======================================================== */

    document
        .querySelectorAll(
            '.adjust-stock-btn'
        )
        .forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        document
                            .getElementById(
                                'adjust-product-id'
                            )
                            .value =
                            this.dataset.id;

                        document
                            .getElementById(
                                'adjust-product-name'
                            )
                            .textContent =
                            this.dataset.name;

                        document
                            .getElementById(
                                'adjust-quantity'
                            )
                            .value =
                            this.dataset.qty;

                        document
                            .getElementById(
                                'adjust-error'
                            )
                            .classList.add(
                                'd-none'
                            );

                        adjustModal.show();

                    }
                );

            }
        );


    document
        .getElementById(
            'adjust-stock-form'
        )
        .addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();

                const button =
                    document.getElementById(
                        'confirm-adjust-stock'
                    );

                const errorBox =
                    document.getElementById(
                        'adjust-error'
                    );

                const id =
                    document.getElementById(
                        'adjust-product-id'
                    ).value;

                const quantity =
                    document.getElementById(
                        'adjust-quantity'
                    ).value;

                errorBox.classList.add(
                    'd-none'
                );

                button.disabled = true;

                button.innerHTML =
                    '<i class="fas fa-spinner fa-spin me-1"></i>' +
                    'Saving...';

                try {

                    await sendAction(
                        'adjust_stock',
                        {
                            id: id,
                            quantity: quantity
                        }
                    );

                    adjustModal.hide();

                    window.location.reload();

                } catch (error) {

                    errorBox.textContent =
                        error.message ||
                        'Unable to update quantity.';

                    errorBox.classList.remove(
                        'd-none'
                    );

                } finally {

                    button.disabled = false;

                    button.innerHTML =
                        '<i class="fas fa-save me-1"></i>' +
                        'Save Quantity';

                }

            }
        );


    /* ========================================================
       AUTO SUBMIT SEARCH
    ======================================================== */

    const search =
        document.getElementById(
            'stock-search'
        );

    let searchTimer = null;

    if (search) {

        search.addEventListener(
            'input',
            function () {

                clearTimeout(
                    searchTimer
                );

                /*
                 * Server-side search is used instead of the old
                 * fetch_products.php AJAX replacement.
                 */

                searchTimer =
                    setTimeout(
                        function () {

                            if (
                                search.value.trim().length === 0 ||
                                search.value.trim().length >= 2
                            ) {

                                document
                                    .getElementById(
                                        'stock-filter-form'
                                    )
                                    .submit();

                            }

                        },
                        550
                    );

            }
        );

    }

})();
</script>

</body>
</html>
