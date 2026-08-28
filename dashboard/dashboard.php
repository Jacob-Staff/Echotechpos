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
 * Dashboard links stay visible in their original positions.
 * If page access is OFF, the link becomes dormant only; the tile/list item
 * is not hidden or visually redesigned. Direct URL access is enforced by auth.php.
 */
function echotech_dashboard_href(string $route, string $page): string
{
    $safeRoute = htmlspecialchars($route, ENT_QUOTES, 'UTF-8');

    if (function_exists('has_page_access') && !has_page_access($page)) {
        return 'href="' . $safeRoute . '" aria-disabled="true" tabindex="-1" onclick="return false;"';
    }

    return 'href="' . $safeRoute . '"';
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
                            <?= echotech_dashboard_href("sell_now.php", "Sale now"); ?>
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
                                <?= echotech_dashboard_href("online_manager.php", "Online manager"); ?>
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
                                <?= echotech_dashboard_href("out_of_stock.php", "Out of stock"); ?>
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
                                <?= echotech_dashboard_href("expired_products.php", "Expired products"); ?>
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

    </div>

</div>


<?php

if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php";
}

?>



<style>
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
