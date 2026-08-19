<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// 🔹 1. INCLUDE CORE SYSTEM HEADERS (Order Matters)
require_once "../includes/head.php";
require_once "../includes/auth.php";
require_once "../includes/header.php"; // Load header containing navbar & offcanvas
require_once "../includes/aside.php";  // Load sidebar navigation

// Branch and Pharmacy Session setup
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? ''; 

// Helper function to generate unique invoice
function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

// Safe Database Query Handler
function safe_mysqli_query($conn, $query) {
    $result = @mysqli_query($conn, $query);
    return ($result === false) ? null : $result;
}

// 🔹 2. FETCH DASHBOARD METRICS
$sales_query = "SELECT SUM(total) AS total_sales FROM sales WHERE branch_id = {$branch_id}"; 
$stock_query = "SELECT SUM(quantity) AS total_stock FROM store_items WHERE branch_id = {$branch_id}"; 
$out_of_stock_query = "SELECT COUNT(*) as total FROM (
    SELECT item_name 
    FROM store_items 
    WHERE pharmacy_id = '$pharmacy_id' AND branch_id = '$branch_id'
    GROUP BY item_name, strength
    HAVING SUM(quantity) <= 0
) as out_of_stock_table";
$expired_products_query = "SELECT COUNT(*) AS expired_products FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}";
$today_transactions_query = "SELECT COUNT(*) AS total_transactions FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}"; 
$waiting_patients_query = "SELECT COUNT(*) AS waiting_patients_count FROM patients WHERE status = '0' AND branch_id = {$branch_id}";

// Execute Queries
$sales_res = safe_mysqli_query($conn, $sales_query);
$sales_data = $sales_res ? mysqli_fetch_assoc($sales_res) : ['total_sales' => 0];

$stock_res = safe_mysqli_query($conn, $stock_query);
$stock_data = $stock_res ? mysqli_fetch_assoc($stock_res) : ['total_stock' => 0];

$out_of_stock_res = safe_mysqli_query($conn, $out_of_stock_query);
$out_of_stock_data = $out_of_stock_res ? mysqli_fetch_assoc($out_of_stock_res) : ['total' => 0];
$out_of_stock_count = $out_of_stock_data['total'] ?? 0;

$expired_res = safe_mysqli_query($conn, $expired_products_query);
$expired_data = $expired_res ? mysqli_fetch_assoc($expired_res) : ['expired_products' => 0];

$today_transactions_res = safe_mysqli_query($conn, $today_transactions_query);
$today_transactions_data = $today_transactions_res ? mysqli_fetch_assoc($today_transactions_res) : ['total_transactions' => 0];

$waiting_patients_res = safe_mysqli_query($conn, $waiting_patients_query);
$waiting_patients_data = $waiting_patients_res ? mysqli_fetch_assoc($waiting_patients_res) : ['waiting_patients_count' => 0];
$waiting_patients_count = $waiting_patients_data['waiting_patients_count'] ?? 0;
?>

<!-- 🔹 3. MAIN CONTENT WRAPPER -->
<div class="page-wrapper">
    <div class="page-breadcrumb bg-white p-3 mb-4 rounded shadow-sm">
        <div class="row align-items-center">
            <div class="col">
                <h3 class="page-title text-dark fw-bold mb-0">Dashboard</h3>
                <small class="text-muted">Branch System Management Overview</small>
            </div>
            <div class="col-auto d-flex align-items-center gap-3">
                <a href="sell_now.php" class="btn btn-outline-primary btn-sm"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                <a href="lay_by_sell.php" class="btn btn-outline-secondary btn-sm"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                <a href="expenses.php" class="btn btn-outline-info btn-sm"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                <a href="sales_report.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary btn-sm text-white shadow-sm">
                    <i class="fas fa-user-plus me-1"></i> Add Patient
                </a>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <?php if (isset($_SESSION['status'])): ?>
            <div class="alert alert-<?php echo $_SESSION['status'] == 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show shadow-sm" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['status'], $_SESSION['message']); ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-9">
                <div class="row g-3">
                    <!-- Quick Actions & Stats -->
                    <div class="col-md-4">
                        <a href="sell_now.php" class="text-decoration-none">
                            <div class="card bg-primary text-white shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <i class="mdi mdi-cart-outline mdi-36px"></i>
                                    <h4 class="text-white mt-2 mb-0">Sell Now</h4>
                                    <small class="text-white-50">Point of Sale Terminal</small>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="today_transactions.php" class="text-decoration-none">
                            <div class="card bg-info text-white shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <h5 class="text-white mb-1">Today's Sales</h5>
                                    <h2 class="text-white font-weight-bold mb-0"><?php echo $today_transactions_data['total_transactions']; ?></h2>
                                    <small class="text-white-50">Transactions Completed</small>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="out_of_stock.php" class="text-decoration-none">
                            <div class="card bg-warning text-dark shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <h5 class="text-dark mb-1">Out of Stock</h5>
                                    <h2 class="text-dark font-weight-bold mb-0"><?php echo $out_of_stock_count; ?></h2>
                                    <small class="text-dark-50">Items Requiring Reorder</small>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="expired_products.php" class="text-decoration-none">
                            <div class="card bg-danger text-white shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <h5 class="text-white mb-1">Expired Stock</h5>
                                    <h2 class="text-white font-weight-bold mb-0"><?php echo $expired_data['expired_products']; ?></h2>
                                    <small class="text-white-50">Action Required</small>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="customers.php" class="text-decoration-none">
                            <div class="card bg-dark text-white shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <i class="mdi mdi-account-group mdi-36px"></i>
                                    <h4 class="text-white mt-2 mb-0">Customers</h4>
                                    <small class="text-white-50">Manage Patient Accounts</small>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="online_manager.php" class="text-decoration-none">
                            <div class="card bg-secondary text-white shadow-sm rounded-3 hover-shadow">
                                <div class="card-body p-4 text-center">
                                    <i class="mdi mdi-file-document-edit mdi-36px"></i>
                                    <h4 class="text-white mt-2 mb-0">Prescriptions</h4>
                                    <small class="text-white-50">Online Orders Portal</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Urgent Side Alerts -->
            <div class="col-lg-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold mb-0 text-primary d-flex align-items-center">
                            <i class="mdi mdi-bell-ring-outline me-2 font-20"></i>Live System Alerts
                        </h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($waiting_patients_count > 0) ? 'bg-light-danger text-danger' : ''; ?>">
                            <span><i class="mdi mdi-human-male-height text-danger me-2"></i> Waiting Patients</span>
                            <span class="badge bg-danger rounded-pill"><?php echo $waiting_patients_count; ?></span>
                        </a>
                        <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($out_of_stock_count > 0) ? 'bg-light-warning text-warning' : ''; ?>">
                            <span><i class="mdi mdi-alert-circle text-warning me-2"></i> Low Stock Alerts</span>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_count; ?></span>
                        </a>
                        <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($expired_data['expired_products'] > 0) ? 'bg-light-danger text-danger' : ''; ?>">
                            <span><i class="mdi mdi-calendar-remove text-danger me-2"></i> Expired Items</span>
                            <span class="badge bg-danger rounded-pill"><?php echo $expired_data['expired_products']; ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// 🔹 4. CLOSE MAIN WRAPPERS & FOOTER
require_once "../includes/footer.php"; 
?>
