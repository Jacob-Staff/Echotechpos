<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Connection & Auth Guard (Safe require)
if (file_exists("../includes/conn.php")) {
    require_once "../includes/conn.php";
}
if (file_exists("../includes/auth.php")) {
    require_once "../includes/auth.php";
}

// Branch and Pharmacy Session setup
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 1; 

// Generate Invoice Number
function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

// Safe execution helper
function safe_query($conn, $query) {
    if (!$conn) return null;
    $res = @mysqli_query($conn, $query);
    return ($res === false) ? null : $res;
}

// Executing Stats Queries with Fallbacks
$sales_res = safe_query($conn, "SELECT SUM(total) AS total_sales FROM sales WHERE branch_id = {$branch_id}");
$sales_data = $sales_res ? mysqli_fetch_assoc($sales_res) : ['total_sales' => 0];

$stock_res = safe_query($conn, "SELECT SUM(quantity) AS total_stock FROM store_items WHERE branch_id = {$branch_id}");
$stock_data = $stock_res ? mysqli_fetch_assoc($stock_res) : ['total_stock' => 0];

$out_of_stock_res = safe_query($conn, "SELECT COUNT(*) as total FROM store_items WHERE branch_id = '{$branch_id}' AND quantity <= 0");
$out_of_stock_data = $out_of_stock_res ? mysqli_fetch_assoc($out_of_stock_res) : ['total' => 0];

$expired_res = safe_query($conn, "SELECT COUNT(*) AS expired FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}");
$expired_data = $expired_res ? mysqli_fetch_assoc($expired_res) : ['expired' => 0];

$today_tx_res = safe_query($conn, "SELECT COUNT(*) AS total FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}");
$today_tx_data = $today_tx_res ? mysqli_fetch_assoc($today_tx_res) : ['total' => 0];

$patients_res = safe_query($conn, "SELECT COUNT(*) AS total FROM patients WHERE status = '0' AND branch_id = {$branch_id}");
$patients_data = $patients_res ? mysqli_fetch_assoc($patients_res) : ['total' => 0];

// Output HTML Head
require_once "../includes/head.php";
?>

<div id="main-wrapper">

    <?php 
    // Navigation Includes
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h4 class="page-title mb-0 fw-bold">Dashboard Overview</h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 p-0">
                            <li class="breadcrumb-item"><a href="#" class="text-decoration-none">Home</a></li>
                        </ol>
                    </nav>
                </div>
                <div class="col-md-8 d-flex justify-content-end align-items-center flex-wrap gap-2">
                    <a href="sell_now.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                    <a href="lay_by_sell.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By</a>
                    <a href="expenses.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                    <a href="sales_report.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-chart-line me-1"></i> Reports</a>
                    <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary btn-sm text-white shadow-sm">
                        <i class="fas fa-user-plus me-1"></i> Add Patient
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            <?php if (isset($_SESSION['status'])): ?>
                <div class="alert alert-<?php echo $_SESSION['status'] == 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['status'], $_SESSION['message']); ?>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-9">
                    <div class="row g-4 mb-4">
                        <div class="col-md-4 col-sm-6">
                            <a href="sell_now.php" class="card card-stats stat-card-sales text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Sell Now</h5>
                                    <h2 class="my-2 text-white fw-bold">All Items</h2>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <a href="today_transactions.php" class="card card-stats stat-card-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Today's Transactions</h5>
                                    <h3 class="my-2 text-warning fw-bold"><?php echo $today_tx_data['total'] ?? 0; ?></h3>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <a href="out_of_stock.php" class="card card-stats stat-card-out-of-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Out of Stock</h5>
                                    <h2 class="my-2 text-white fw-bold"><?php echo $out_of_stock_data['total'] ?? 0; ?></h2>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <a href="expired_products.php" class="card card-stats stat-card-expired text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Expired Products</h5>
                                    <h2 class="my-2 text-white fw-bold"><?php echo $expired_data['expired'] ?? 0; ?></h2>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <a href="customers.php" class="card card-stats stat-card-items text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Customer</h5>
                                    <h2 class="my-2 text-white fw-bold">Service</h2>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <a href="online_manager.php" class="card card-stats stat-card-out-of-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Prescription</h5>
                                    <h2 class="my-2 text-white fw-bold">Online</h2>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Alerts Sidebar -->
                <div class="col-lg-3">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="fw-bold mb-0 text-primary"><i class="mdi mdi-bell-ring-outline me-2"></i>Urgent Alerts</h5>
                        </div>
                        <div class="list-group list-group-flush">
                            <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($patients_data['total'] > 0) ? 'list-group-item-danger' : ''; ?>">
                                <span><strong>Patients Waiting</strong></span>
                                <span class="badge bg-danger rounded-pill"><?php echo $patients_data['total'] ?? 0; ?></span>
                            </a>
                            <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($out_of_stock_data['total'] > 0) ? 'list-group-item-warning' : ''; ?>">
                                <span><strong>Out of Stock</strong></span>
                                <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_data['total'] ?? 0; ?></span>
                            </a>
                            <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($expired_data['expired'] > 0) ? 'list-group-item-danger' : ''; ?>">
                                <span><strong>Expired Products</strong></span>
                                <span class="badge bg-danger rounded-pill"><?php echo $expired_data['expired'] ?? 0; ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
