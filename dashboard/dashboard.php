<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists("../includes/conn.php")) require_once "../includes/conn.php";
if (file_exists("../includes/auth.php")) require_once "../includes/auth.php";

$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 

function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

function safe_query($conn, $query) {
    if (!$conn) return null;
    $res = @mysqli_query($conn, $query);
    return ($res === false) ? null : $res;
}

$out_of_stock_res = safe_query($conn, "SELECT COUNT(*) as total FROM store_items WHERE branch_id = '{$branch_id}' AND quantity <= 0");
$out_of_stock_data = $out_of_stock_res ? mysqli_fetch_assoc($out_of_stock_res) : ['total' => 2];

$expired_res = safe_query($conn, "SELECT COUNT(*) AS expired FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}");
$expired_data = $expired_res ? mysqli_fetch_assoc($expired_res) : ['expired' => 2];

$today_tx_res = safe_query($conn, "SELECT COUNT(*) AS total FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}");
$today_tx_data = $today_tx_res ? mysqli_fetch_assoc($today_tx_res) : ['total' => 0];

$patients_res = safe_query($conn, "SELECT COUNT(*) AS total FROM patients WHERE status = '0' AND branch_id = {$branch_id}");
$patients_data = $patients_res ? mysqli_fetch_assoc($patients_res) : ['total' => 0];

require_once "../includes/head.php";
?>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        
        <!-- Breadcrumb Header -->
        <div class="page-breadcrumb">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.2rem;">Dashboard</h4>
                    <div class="text-primary small" style="font-size: 0.8rem;">Home</div>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-3">
                    <a href="sell_now.php" class="text-dark text-decoration-none small fw-semibold"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                    <a href="lay_by_sell.php" class="text-dark text-decoration-none small fw-semibold"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                    <a href="expenses.php" class="text-dark text-decoration-none small fw-semibold"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                    <a href="sales_report.php" class="text-dark text-decoration-none small fw-semibold"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                    <a href="sales_trend.php" class="text-dark text-decoration-none small fw-semibold"><i class="mdi mdi-trending-up me-1"></i> Sales Trend</a>
                    <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-purple btn-sm px-3 rounded-2 shadow-sm">
                        <i class="fas fa-plus me-1"></i> Add Patient
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            <div class="row g-3">
                <!-- 6 Main Dash Tiles -->
                <div class="col-lg-9">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <a href="sell_now.php" class="card card-dash bg-tile-sellnow">
                                <span class="card-title">Sell Now</span>
                                <span class="card-value">All Items</span>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="today_transactions.php" class="card card-dash bg-tile-tx">
                                <span class="card-title">Today's Transactions</span>
                                <span class="card-value" style="color: #eec136 !important;"><?php echo $today_tx_data['total'] ?? 0; ?></span>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="out_of_stock.php" class="card card-dash bg-tile-outstock">
                                <span class="card-title">Out of Stock</span>
                                <span class="card-value"><?php echo $out_of_stock_data['total'] ?? 2; ?></span>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="expired_products.php" class="card card-dash bg-tile-expired">
                                <span class="card-title">Expired Products</span>
                                <span class="card-value"><?php echo $expired_data['expired'] ?? 2; ?></span>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="customers.php" class="card card-dash bg-tile-customer">
                                <span class="card-title">Customer</span>
                                <span class="card-value">Service</span>
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="online_manager.php" class="card card-dash bg-tile-online">
                                <span class="card-title">Prescription</span>
                                <span class="card-value">Online</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Urgent Alerts Sidebar Widget -->
                <div class="col-lg-3">
                    <div class="card border-0 shadow-sm rounded-2">
                        <div class="card-header bg-white py-2 border-bottom-0">
                            <span class="fw-bold small" style="color: #6c5ce7;"><i class="mdi mdi-bell-ring-outline me-1"></i> Urgent Alerts</span>
                        </div>
                        <div class="list-group list-group-flush small">
                            <a href="waiting_patients.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0">
                                <span class="text-secondary fw-semibold">Patients Waiting</span>
                                <span class="badge bg-danger rounded-pill"><?php echo $patients_data['total'] ?? 0; ?></span>
                            </a>
                            <a href="out_of_stock.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0" style="background-color: #fef5e7;">
                                <span class="text-dark fw-semibold">Out of Stock</span>
                                <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_data['total'] ?? 2; ?></span>
                            </a>
                            <a href="expired_products.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0" style="background-color: #fde8ea;">
                                <span class="text-danger fw-semibold">Expired Products</span>
                                <span class="badge bg-danger rounded-pill"><?php echo $expired_data['expired'] ?? 2; ?></span>
                            </a>
                            <a href="pending_orders.php" class="list-group-item d-flex justify-content-between align-items-center py-2 border-0">
                                <span class="text-secondary fw-semibold">Pending orders</span>
                                <span class="badge bg-secondary rounded-pill">0</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php 
                    // Add your footer component here
    if (file_exists("../includes/footer.php")) {
        require_once "../includes/footer.php"; 
    }
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
