<?php
/**
 * ============================================================
 * PHARMACY POS - DASHBOARD
 * ============================================================
 * Standard POS timezone: Africa/Lusaka
 * Zambia Standard Time: UTC+2
 *
 * Important:
 * - Never compares DATE fields to '0000-00-00'
 * - NULL expiry_date means no expiry date
 * - All branch statistics are tenant-safe
 * ============================================================
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| POS STANDARD TIME
|--------------------------------------------------------------------------
*/
date_default_timezone_set('Africa/Lusaka');

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/
if (file_exists("../includes/conn.php")) {
    require_once "../includes/conn.php";
}

if (file_exists("../includes/auth.php")) {
    require_once "../includes/auth.php";
}

/*
 * Dashboard links remain visible exactly as designed.
 * Access OFF only disables navigation; no visual styling is changed.
 */
function echotech_dashboard_href(string $route, string $page): string
{
    if (function_exists('has_page_access') && !has_page_access($page)) {
        return 'href="' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '" aria-disabled="true" onclick="return false;"';
    }
    return 'href="' . htmlspecialchars($route, ENT_QUOTES, 'UTF-8') . '"';
}

/*
|--------------------------------------------------------------------------
| Pharmacy / Branch Context
|--------------------------------------------------------------------------
*/
$pharmacy_id = isset($_SESSION['pharmacy_id'])
    ? (int) $_SESSION['pharmacy_id']
    : 10;

$branch_id = isset($_SESSION['branch_id'])
    ? (int) $_SESSION['branch_id']
    : 1;

/*
|--------------------------------------------------------------------------
| POS LOCAL DATE
|--------------------------------------------------------------------------
| Always use Zambia local date instead of relying on the
| MySQL server timezone.
|--------------------------------------------------------------------------
*/
$pos_today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Generate Invoice Number
|--------------------------------------------------------------------------
*/
function generateInvoiceNumber()
{
    return 'INV-' . mt_rand(1000000, 9797977);
}

$invoice_number = generateInvoiceNumber();

/*
|--------------------------------------------------------------------------
| Safe Query
|--------------------------------------------------------------------------
*/
function safe_query($conn, $query)
{
    if (!$conn) {
        return null;
    }

    $res = @mysqli_query($conn, $query);

    return ($res === false) ? null : $res;
}

/*
|--------------------------------------------------------------------------
| INITIAL DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| OUT OF STOCK
|--------------------------------------------------------------------------
*/
$out_of_stock_res = safe_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM store_items
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND quantity <= 0
    "
);

$out_of_stock_data = $out_of_stock_res
    ? mysqli_fetch_assoc($out_of_stock_res)
    : ['total' => 0];

/*
|--------------------------------------------------------------------------
| EXPIRED PRODUCTS
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Do NOT use:
|     expiry_date = '0000-00-00'
|
| NULL means no expiry date.
|--------------------------------------------------------------------------
*/
$expired_res = safe_query(
    $conn,
    "
    SELECT COUNT(*) AS expired
    FROM store_items
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND expiry_date IS NOT NULL
      AND expiry_date < '{$pos_today}'
    "
);
$expired_data = $expired_res
    ? mysqli_fetch_assoc($expired_res)
    : ['expired' => 0];

/*
|--------------------------------------------------------------------------
| TODAY'S TRANSACTIONS
|--------------------------------------------------------------------------
|
| Uses Zambia/POS local date.
|--------------------------------------------------------------------------
*/
$today_tx_res = safe_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM sales
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND DATE(sale_date) = '{$pos_today}'
    "
);

$today_tx_data = $today_tx_res
    ? mysqli_fetch_assoc($today_tx_res)
    : ['total' => 0];

/*
|--------------------------------------------------------------------------
| Pending Counts
|--------------------------------------------------------------------------
*/
function getPendingCount($conn, $table, $pharmacy_id, $branch_id)
{
    /*
     * Whitelist allowed tables.
     * This prevents arbitrary table names being injected.
     */
    $allowed_tables = [
        'prescriptions',
        'lab_results',
        'help_inquiries'
    ];

    if (!in_array($table, $allowed_tables, true)) {
        return 0;
    }

    $stmt = safe_query(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM {$table}
        WHERE pharmacy_id = {$pharmacy_id}
          AND branch_id = {$branch_id}
          AND status = 'Pending'
        "
    );

    if (!$stmt) {
        return 0;
    }

    $row = mysqli_fetch_assoc($stmt);

    return (int) ($row['total'] ?? 0);
}

$pending_rx = getPendingCount(
    $conn,
    "prescriptions",
    $pharmacy_id,
    $branch_id
);

$pending_labs = getPendingCount(
    $conn,
    "lab_results",
    $pharmacy_id,
    $branch_id
);

$pending_help = getPendingCount(
    $conn,
    "help_inquiries",
    $pharmacy_id,
    $branch_id
);

$total_app_alerts =
    $pending_rx +
    $pending_labs +
    $pending_help;

/*
|--------------------------------------------------------------------------
| PENDING PURCHASE ORDERS
|--------------------------------------------------------------------------
*/
$po_res = safe_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM purchase_orders
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND status IN ('draft', 'ordered', 'partial')
    "
);

$pending_orders_count = $po_res
    ? (int) (mysqli_fetch_assoc($po_res)['total'] ?? 0)
    : 0;

/*
|--------------------------------------------------------------------------
| ONLINE ORDERS
|--------------------------------------------------------------------------
| Pending + Processing customer orders for this pharmacy/branch.
|--------------------------------------------------------------------------
*/
$online_orders_res = safe_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM clients_orders
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND status IN ('Pending', 'Processing')
    "
);

$online_orders_count = $online_orders_res
    ? (int) (mysqli_fetch_assoc($online_orders_res)['total'] ?? 0)
    : 0;

/*
|--------------------------------------------------------------------------
| Head
|--------------------------------------------------------------------------
*/
require_once "../includes/head.php";
?>

<div id="main-wrapper">

    <?php
    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }
    ?>

    <div class="page-wrapper">

        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <div class="page-breadcrumb">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                <div>

                    <h4
                        class="fw-bold mb-0 text-dark"
                        style="font-size: 1.1rem;"
                    >
                        Dashboard
                    </h4>

                    <div
                        class="text-primary small"
                        style="font-size: 0.78rem;"
                    >
                        Home
                    </div>

                </div>

                <div class="quick-actions-nav">

                    <a
                        <?= echotech_dashboard_href("sell_now.php", "Sale now"); ?>
                        class="text-dark text-decoration-none small fw-semibold me-2"
                    >
                        <i class="mdi mdi-cash-multiple me-1"></i>
                        Sell Now
                    </a>

                    <a
                        <?= echotech_dashboard_href("lay_by_sell.php", "Lay by sale"); ?>
                        class="text-dark text-decoration-none small fw-semibold me-2"
                    >
                        <i class="mdi mdi-credit-card-plus me-1"></i>
                        Lay-By Sell
                    </a>

                    <a
                        <?= echotech_dashboard_href("expenses.php", "Expenses sales trend"); ?>
                        class="text-dark text-decoration-none small fw-semibold me-2"
                    >
                        <i class="mdi mdi-chart-bar me-1"></i>
                        Expenses
                    </a>

                    <a
                        <?= echotech_dashboard_href("sales_report.php", "Sales report"); ?>
                        class="text-dark text-decoration-none small fw-semibold me-2"
                    >
                        <i class="mdi mdi-chart-line me-1"></i>
                        Sales Report
                    </a>

                    <a
                        <?= echotech_dashboard_href("sales_trend.php", "Expenses sales trend"); ?>
                        class="text-dark text-decoration-none small fw-semibold me-2"
                    >
                        <i class="mdi mdi-trending-up me-1"></i>
                        Sales Trend
                    </a>

                    <a
                        href="add_patients.php?invoice=<?php echo urlencode($invoice_number); ?>"
                        class="btn btn-purple btn-sm px-2 py-1 rounded-2 shadow-sm"
                    >
                        <i class="fas fa-plus me-1"></i>
                        Add Patient
                    </a>

                </div>

            </div>

        </div>

        <!-- =====================================================
             DASHBOARD CONTENT
        ====================================================== -->

        <div class="container-fluid p-0">

            <div class="row g-3">

                <!-- =================================================
                     MAIN TILES
                ================================================== -->

                <div class="col-lg-9">

                    <div class="mobile-tile-grid">

                        <!-- SELL NOW -->

                        <a
                            href="sell_now.php"
                            class="card card-dash bg-tile-sellnow"
                        >
                            <span class="card-title">
                                Sell Now
                            </span>

                            <span class="card-value">
                                All Items
                            </span>
                        </a>


                        <!-- TODAY'S TRANSACTIONS -->

                        <a
                            <?= echotech_dashboard_href("today_transactions.php", "Today transaction"); ?>
                            class="card card-dash bg-tile-tx"
                        >
                            <span class="card-title">
                                Today's Tx
                            </span>

                            <span
                                class="card-value"
                                style="color: #eec136 !important;"
                            >
                                <?php
                                echo (int) ($today_tx_data['total'] ?? 0);
                                ?>
                            </span>
                        </a>


                        <!-- OUT OF STOCK -->

                        <a
                            <?= echotech_dashboard_href("out_of_stock.php", "Out of stock"); ?>
                            class="card card-dash bg-tile-outstock"
                        >
                            <span class="card-title">
                                Out of Stock
                            </span>

                            <span
                                class="card-value"
                                id="tile-out-of-stock"
                            >
                                <?php
                                echo (int) ($out_of_stock_data['total'] ?? 0);
                                ?>
                            </span>
                        </a>


                        <!-- EXPIRED -->

                        <a
                            <?= echotech_dashboard_href("expired_products.php", "Expired products"); ?>
                            class="card card-dash bg-tile-expired"
                        >
                            <span class="card-title">
                                Expired Items
                            </span>

                            <span
                                class="card-value"
                                id="tile-expired"
                            >
                                <?php
                                echo (int) ($expired_data['expired'] ?? 0);
                                ?>
                            </span>
                        </a>


                        <!-- CUSTOMER -->

                        <a
                            <?= echotech_dashboard_href("customers.php", "Customer"); ?>
                            class="card card-dash bg-tile-customer"
                        >
                            <span class="card-title">
                                Customer
                            </span>

                            <span class="card-value">
                                Service
                            </span>
                        </a>


                        <!-- ONLINE PRESCRIPTION -->

                        <a
                            <?= echotech_dashboard_href("online_manager.php", "Online manager"); ?>
                            class="card card-dash bg-tile-online"
                        >
                            <span class="card-title">
                                Prescription
                            </span>

                            <span class="card-value">
                                Online
                            </span>
                        </a>

                    </div>

                </div>


                <!-- =================================================
                     URGENT ALERTS
                ================================================== -->

                <div class="col-lg-3">

                    <div class="card border-0 shadow-sm rounded-2">

                        <div
                            class="card-header bg-white py-2 border-bottom-0 d-flex justify-content-between align-items-center"
                        >

                            <span
                                class="fw-bold small"
                                style="color: #6c5ce7;"
                            >
                                <i class="mdi mdi-bell-ring-outline me-1"></i>
                                Urgent Alerts
                            </span>

                            <span
                                class="spinner-border spinner-border-sm text-muted d-none"
                                id="alert-spinner"
                                role="status"
                            ></span>

                        </div>


                        <div class="list-group list-group-flush small">

                            <!-- APP ALERTS -->

                            <a
                                href="online_manager.php"
                                class="list-group-item d-flex justify-content-between align-items-center py-2 border-0"
                                style="background-color: #f0f3ff;"
                            >

                                <span class="text-primary fw-semibold">
                                    <i class="fas fa-mobile-alt me-1"></i>
                                    App Alerts
                                </span>

                                <span
                                    class="badge bg-primary rounded-pill"
                                    id="badge-app-alerts"
                                >
                                    <?php
                                    echo $total_app_alerts;
                                    ?>
                                </span>

                            </a>


                            <!-- OUT OF STOCK -->

                            <a
                                href="out_of_stock.php"
                                class="list-group-item d-flex justify-content-between align-items-center py-2 border-0"
                                style="background-color: #fef5e7;"
                            >

                                <span class="text-dark fw-semibold">
                                    Out of Stock
                                </span>

                                <span
                                    class="badge bg-warning text-dark rounded-pill"
                                    id="badge-out-of-stock"
                                >
                                    <?php
                                    echo (int) ($out_of_stock_data['total'] ?? 0);
                                    ?>
                                </span>

                            </a>


                            <!-- EXPIRED -->

                            <a
                                href="expired_products.php"
                                class="list-group-item d-flex justify-content-between align-items-center py-2 border-0"
                                style="background-color: #fde8ea;"
                            >

                                <span class="text-danger fw-semibold">
                                    Expired Products
                                </span>

                                <span
                                    class="badge bg-danger rounded-pill"
                                    id="badge-expired"
                                >
                                    <?php
                                    echo (int) ($expired_data['expired'] ?? 0);
                                    ?>
                                </span>

                            </a>


                            <!-- PENDING ORDERS -->

                            <a
                                <?= echotech_dashboard_href("purchase_orders_list.php", "Purchases order list"); ?>
                                class="list-group-item d-flex justify-content-between align-items-center py-2 border-0"
                            >

                                <span class="text-secondary fw-semibold">
                                    Pending Orders
                                </span>

                                <span
                                    class="badge bg-info rounded-pill"
                                    id="badge-pending-orders"
                                >
                                    <?php
                                    echo $pending_orders_count;
                                    ?>
                                </span>

                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- =================================================
             ONLINE ORDERS QUICK LINK
             Pending + Processing incoming customer orders
        ================================================== -->
        <div class="online-orders-shortcut-wrap">
            <a
                <?= echotech_dashboard_href("online_orders.php", "Online orders"); ?>
                class="online-orders-shortcut"
                aria-label="Open Online Orders"
            >
                <span class="online-orders-icon">
                    <i class="mdi mdi-cart-arrow-down"></i>
                </span>

                <span class="online-orders-copy">
                    <span class="online-orders-title">
                        Online Orders
                    </span>
                    <span class="online-orders-subtitle">
                        Incoming customer orders
                    </span>
                </span>

                <?php if ($online_orders_count > 0): ?>
                    <span
                        class="online-orders-badge"
                        id="badge-online-orders"
                    >
                        <?php echo $online_orders_count; ?>
                    </span>
                <?php endif; ?>

                <span class="online-orders-arrow">
                    <i class="mdi mdi-chevron-right"></i>
                </span>
            </a>
        </div>

    </div>

</div>


<?php

if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php";
}

?>



<style>
/* Online Orders quick link */
.online-orders-shortcut-wrap {
    display: flex;
    justify-content: center;
    width: 100%;
    margin-top: 18px;
    padding: 0 12px 6px;
}

.online-orders-shortcut {
    display: flex;
    align-items: center;
    gap: 12px;
    width: min(100%, 285px);
    min-height: 54px;
    padding: 8px 10px;
    border: 1px solid #dce5ef;
    border-radius: 14px;
    background: #fff;
    color: #26384a;
    text-decoration: none;
    box-shadow: 0 5px 16px rgba(30, 50, 70, .07);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.online-orders-shortcut:hover {
    color: #26384a;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: #c9d6e4;
    box-shadow: 0 9px 22px rgba(30, 50, 70, .11);
}

.online-orders-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    border-radius: 11px;
    background: #eef5ff;
    color: #2878e8;
    font-size: 17px;
}

.online-orders-copy {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.15;
}

.online-orders-title {
    font-size: 13px;
    font-weight: 800;
    color: #1f3348;
}

.online-orders-subtitle {
    margin-top: 4px;
    font-size: 10px;
    color: #7a8795;
}

.online-orders-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 23px;
    height: 23px;
    padding: 0 7px;
    margin-left: auto;
    border-radius: 999px;
    background: #ff4d67;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    box-shadow: 0 2px 7px rgba(255, 77, 103, .24);
}

.online-orders-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #a1adba;
    font-size: 19px;
}

@media (max-width: 575.98px) {
    .online-orders-shortcut-wrap {
        margin-top: 16px;
        padding-left: 8px;
        padding-right: 8px;
    }

    .online-orders-shortcut {
        width: 100%;
    }
}
</style>


<!-- ============================================================
     JAVASCRIPT
============================================================= -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

$(document).ready(function () {

    function pollDashboardAlerts() {

        $('#alert-spinner').removeClass('d-none');

        $.ajax({

            url: 'fetch_alert_counts.php',

            type: 'GET',

            dataType: 'json',

            success: function (data) {

                if (!data) {
                    return;
                }

                $('#badge-app-alerts')
                    .text(data.app_alerts ?? 0);

                $('#badge-out-of-stock')
                    .text(data.out_of_stock ?? 0);

                $('#tile-out-of-stock')
                    .text(data.out_of_stock ?? 0);

                $('#badge-expired')
                    .text(data.expired_items ?? 0);

                $('#tile-expired')
                    .text(data.expired_items ?? 0);

                $('#badge-pending-orders')
                    .text(data.pending_orders ?? 0);

            },

            error: function (err) {

                console.warn(
                    'Dashboard poll failed:',
                    err
                );

            },

            complete: function () {

                $('#alert-spinner')
                    .addClass('d-none');

            }

        });

    }



    /* ============================================================
       ONLINE ORDERS LIVE BADGE
       Checks the current branch every 3 seconds.
       No page refresh is required.
       ============================================================ */
    function pollOnlineOrdersBadge() {

        $.ajax({
            url: 'fetch_online_order_count.php?_=' + Date.now(),
            type: 'GET',
            dataType: 'json',
            cache: false,

            success: function (data) {

                if (!data || data.status !== 'success') {
                    return;
                }

                const count = parseInt(data.count || 0, 10);
                const shortcut = $('.online-orders-shortcut');

                if (!shortcut.length) {
                    return;
                }

                let badge = $('#badge-online-orders');

                if (count > 0) {

                    if (!badge.length) {
                        badge = $(
                            '<span class="online-orders-badge" id="badge-online-orders"></span>'
                        );

                        shortcut.append(badge);
                    }

                    badge.text(count);

                } else {

                    if (badge.length) {
                        badge.remove();
                    }
                }
            },

            error: function (xhr) {
                console.warn(
                    'Online Orders live check failed:',
                    xhr.status
                );
            }
        });
    }

    // Run immediately when the dashboard opens.
    pollOnlineOrdersBadge();

    // Keep listening while the dashboard remains open.
    setInterval(
        pollOnlineOrdersBadge,
        3000
    );

    /*
    |--------------------------------------------------------------------------
    | Poll every 30 seconds
    |--------------------------------------------------------------------------
    */

    setInterval(
        pollDashboardAlerts,
        30000
    );

});

</script>


</body>
</html>
