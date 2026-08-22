<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists("../includes/conn.php")) require_once "../includes/conn.php";
if (file_exists("../includes/auth.php")) require_once "../includes/auth.php";

$pharmacy_id = isset($_SESSION['pharmacy_id']) ? intval($_SESSION['pharmacy_id']) : 10;
$branch_id   = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 

function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

function safe_query($conn, $query) {
    if (!$conn) return null;
    $res = @mysqli_query($conn, $query);
    return ($res === false) ? null : $res;
}

// Initial Counts
$out_of_stock_res = safe_query($conn, "SELECT COUNT(*) as total FROM store_items WHERE branch_id = '{$branch_id}' AND quantity <= 0");
$out_of_stock_data = $out_of_stock_res ? mysqli_fetch_assoc($out_of_stock_res) : ['total' => 0];

$expired_res = safe_query($conn, "SELECT COUNT(*) AS expired FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}");
$expired_data = $expired_res ? mysqli_fetch_assoc($expired_res) : ['expired' => 0];

$today_tx_res = safe_query($conn, "SELECT COUNT(*) AS total FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}");
$today_tx_data = $today_tx_res ? mysqli_fetch_assoc($today_tx_res) : ['total' => 0];

function getPendingCount($conn, $table, $pharmacy_id, $branch_id) {
    $stmt = safe_query($conn, "SELECT COUNT(*) as total FROM {$table} WHERE pharmacy_id = '{$pharmacy_id}' AND branch_id = '{$branch_id}' AND status = 'Pending'");
    if (!$stmt) return 0;
    $row = mysqli_fetch_assoc($stmt);
    return (int)($row['total'] ?? 0);
}

$pending_rx   = getPendingCount($conn, "prescriptions", $pharmacy_id, $branch_id);
$pending_labs = getPendingCount($conn, "lab_results", $pharmacy_id, $branch_id);
$pending_help = getPendingCount($conn, "help_inquiries", $pharmacy_id, $branch_id);
$total_app_alerts = $pending_rx + $pending_labs + $pending_help;

$po_res = safe_query($conn, "SELECT COUNT(*) AS total FROM purchase_orders WHERE pharmacy_id = '{$pharmacy_id}' AND branch_id = '{$branch_id}' AND status IN ('draft', 'ordered', 'partial')");
$pending_orders_count = $po_res ? (int)(mysqli_fetch_assoc($po_res)['total'] ?? 0) : 0;

// Fetch initial 5 items expiring within 60 days
$expiring_soon_items = [];
$exp_query = "SELECT item_name, batch_number, quantity, expiry_date, DATEDIFF(expiry_date, CURDATE()) as days_left 
              FROM store_items 
              WHERE branch_id = '{$branch_id}' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) 
              ORDER BY expiry_date ASC LIMIT 5";
$exp_res = safe_query($conn, $exp_query);
if ($exp_res) {
    while ($row = mysqli_fetch_assoc($exp_res)) {
        $expiring_soon_items[] = $row;
    }
}

require_once "../includes/head.php";
?>

<style>
.table-custom-clean th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #8c98a4;
    font-weight: 600;
    border-bottom: 1px solid #edf2f9;
}
.table-custom-clean td {
    font-size: 0.82rem;
    vertical-align: middle;
    border-bottom: 1px solid #edf2f9;
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="page-breadcrumb">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.1rem;">Dashboard</h4>
                    <div class="text-primary small" style="font-size: 0.78rem;">Home</div>
                </div>
                <div class="quick-actions-nav">
                    <a href="sell_now.php" class="text-dark text-decoration-none small fw-semibold me-2"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                    <a href="lay_by_sell.php" class="text-dark text-decoration-none small fw-semibold me-2"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                    <a href="expenses.php" class="text-dark text-decoration-none small fw-semibold me-2"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                    <a href="sales_report.php" class="text-dark text-decoration-none small fw-semibold me-2"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                    <a href="sales_trend.php" class="text-dark text-decoration-none small fw-semibold me-2"><i class="mdi mdi-trending-up me-1"></i> Sales Trend</a>
                    <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-purple btn-sm px-2 py-1 rounded-2 shadow-sm">
                        <i class="fas fa-plus me-1"></i> Add Patient
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            <div class="row g-3">
                <!-- Main Tiles -->
                <div class="col-lg-9">
                    <div class="mobile-tile-grid">
                        <a href="sell_now.php" class="card card-dash bg-tile-sellnow">
                            <span class="card-title">Sell Now</span>
                            <span class="card-value">All Items</span>
                        </a>

                        <a href="today_transactions.php" class="card card-dash bg-tile-tx">
                            <span class="card-title">Today's Tx</span>
                            <span class="card-value" style="color: #eec136 !important;"><?php echo $today_tx_data['total'] ?? 0; ?></span>
                        </a>

                        <a href="out_of_stock.php" class="card card-dash bg-tile-outstock">
                            <span class="card-title">Out of Stock</span>
                            <span class="card-value" id="tile-out-of-stock"><?php echo $out_of_stock_data['total'] ?? 0; ?></span>
                        </a>

                        <a href="expired_products.php" class="card card-dash bg-tile-expired">
                            <span class="card-title">Expired Items</span>
                            <span class="card-value" id="tile-expired"><?php echo $expired_data['expired'] ?? 0; ?></span>
                        </a>

                        <a href="customers.php" class="card card-dash bg-tile-customer">
                            <span class="card-title">Customer</span>
                            <span class="card-value">Service</span>
                        </a>

                        <a href="online_manager.php" class="card card-dash bg-tile-online">
                            <span class="card-title">Prescription</span>
                            <span class="card-value">Online</span>
                        </a>
                    </div>
                </div>

                <!-- Urgent Alerts Widget -->
                <div class="col-lg-3">
                    <div class="card border-0 shadow-sm rounded-2">
                        <div class="card-header bg-white py-2 border-bottom-0 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small" style="color: #6c5ce7;"><i class="mdi mdi-bell-ring-outline me-1"></i> Urgent Alerts</span>
                            <span class="spinner-border spinner-border-sm text-muted d-none" id="alert-spinner" role="status"></span>
                        </div>
                        <div class="list-group list-group-flush small">
                            <a href="online_manager.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0" style="background-color: #f0f3ff;">
                                <span class="text-primary fw-semibold"><i class="fas fa-mobile-alt me-1"></i> App Alerts</span>
                                <span class="badge bg-primary rounded-pill" id="badge-app-alerts"><?php echo $total_app_alerts; ?></span>
                            </a>
                            <a href="out_of_stock.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0" style="background-color: #fef5e7;">
                                <span class="text-dark fw-semibold">Out of Stock</span>
                                <span class="badge bg-warning text-dark rounded-pill" id="badge-out-of-stock"><?php echo $out_of_stock_data['total'] ?? 0; ?></span>
                            </a>
                            <a href="expired_products.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0" style="background-color: #fde8ea;">
                                <span class="text-danger fw-semibold">Expired Products</span>
                                <span class="badge bg-danger rounded-pill" id="badge-expired"><?php echo $expired_data['expired'] ?? 0; ?></span>
                            </a>
                            <a href="purchase_orders_list.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0">
                                <span class="text-secondary fw-semibold">Pending Orders</span>
                                <span class="badge bg-info rounded-pill" id="badge-pending-orders"><?php echo $pending_orders_count; ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ----------------------------------------------------------------- -->
            <!-- NEW CLEAN COMPONENT: EXPIRING SOON WATCHLIST (FEFO MANAGEMENT)     -->
            <!-- ----------------------------------------------------------------- -->
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-2">
                        <div class="card-header bg-white py-2.5 border-bottom d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-soft-danger text-danger p-1.5 rounded-2">
                                    <i class="mdi mdi-clock-alert-outline fs-6"></i>
                                </span>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.9rem;">Expiring Soon Watchlist (Next 60 Days)</h6>
                                    <small class="text-muted" style="font-size: 0.72rem;">Apply FEFO protocol to discount or clear stock before batch expiration</small>
                                </div>
                            </div>
                            <a href="expired_products.php" class="btn btn-light btn-sm fw-semibold text-secondary px-2.5 py-1 rounded-2" style="font-size: 0.78rem;">
                                View All Batches <i class="fas fa-chevron-right ms-1" style="font-size: 0.7rem;"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-custom-clean table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-3 py-2">Item Name</th>
                                            <th class="py-2">Batch #</th>
                                            <th class="py-2 text-center">Stock Left</th>
                                            <th class="py-2">Expiry Date</th>
                                            <th class="py-2 text-end pe-3">Days Remaining</th>
                                        </tr>
                                    </thead>
                                    <tbody id="expiring-soon-tbody">
                                        <?php if (empty($expiring_soon_items)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3 small">
                                                    <i class="mdi mdi-check-circle-outline text-success me-1"></i> No items expiring within the next 60 days.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($expiring_soon_items as $item): 
                                                $days = (int)$item['days_left'];
                                                $badge_class = $days <= 30 ? 'bg-danger text-white' : 'bg-warning text-dark';
                                            ?>
                                                <tr>
                                                    <td class="ps-3 fw-semibold text-dark"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                    <td><code class="text-muted"><?php echo htmlspecialchars($item['batch_number'] ?: 'N/A'); ?></code></td>
                                                    <td class="text-center fw-bold"><?php echo (int)$item['quantity']; ?></td>
                                                    <td><?php echo date('d M Y', strtotime($item['expiry_date'])); ?></td>
                                                    <td class="text-end pe-3">
                                                        <span class="badge <?php echo $badge_class; ?> rounded-pill px-2 py-1">
                                                            <?php echo $days; ?> Days
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    function pollDashboardAlerts() {
        $('#alert-spinner').removeClass('d-none');
        $.ajax({
            url: 'fetch_alert_counts.php',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Update Badge & Tile Counters
                $('#badge-app-alerts').text(data.app_alerts);
                $('#badge-out-of-stock').text(data.out_of_stock);
                $('#tile-out-of-stock').text(data.out_of_stock);
                $('#badge-expired').text(data.expired_items);
                $('#tile-expired').text(data.expired_items);
                $('#badge-pending-orders').text(data.pending_orders);

                // Dynamically Render Expiring Table Rows
                var tbody = $('#expiring-soon-tbody');
                tbody.empty();
                
                if (!data.expiring_soon || data.expiring_soon.length === 0) {
                    tbody.html('<tr><td colspan="5" class="text-center text-muted py-3 small"><i class="mdi mdi-check-circle-outline text-success me-1"></i> No items expiring within the next 60 days.</td></tr>');
                } else {
                    $.each(data.expiring_soon, function(idx, item) {
                        var days = parseInt(item.days_left);
                        var badgeClass = days <= 30 ? 'bg-danger text-white' : 'bg-warning text-dark';
                        var expDate = new Date(item.expiry_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                        
                        var row = '<tr>' +
                            '<td class="ps-3 fw-semibold text-dark">' + item.item_name + '</td>' +
                            '<td><code class="text-muted">' + (item.batch_number ? item.batch_number : 'N/A') + '</code></td>' +
                            '<td class="text-center fw-bold">' + item.quantity + '</td>' +
                            '<td>' + expDate + '</td>' +
                            '<td class="text-end pe-3"><span class="badge ' + badgeClass + ' rounded-pill px-2 py-1">' + days + ' Days</span></td>' +
                        '</tr>';
                        tbody.append(row);
                    });
                }
            },
            error: function(err) {
                console.warn('Dashboard poll failed:', err);
            },
            complete: function() {
                $('#alert-spinner').addClass('d-none');
            }
        });
    }

    // Poll every 30 seconds
    setInterval(pollDashboardAlerts, 30000);
});
</script>

</body>
</html>
