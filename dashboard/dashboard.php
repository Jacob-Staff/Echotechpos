<?php  
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Order of includes
require_once "../includes/head.php";
require_once "../includes/auth.php";
require_once "../includes/header.php";
require_once "../includes/aside.php";

$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? ''; 

function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

function safe_mysqli_query($conn, $query) {
    $result = @mysqli_query($conn, $query);
    return ($result === false) ? null : $result;
}

// Database Queries
$out_of_stock_query = "SELECT COUNT(*) as total FROM (
    SELECT item_name FROM store_items 
    WHERE pharmacy_id = '$pharmacy_id' AND branch_id = '$branch_id'
    GROUP BY item_name, strength HAVING SUM(quantity) <= 0
) as out_of_stock_table";

$expired_products_query = "SELECT COUNT(*) AS expired_products FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}";
$today_transactions_query = "SELECT COUNT(*) AS total_transactions FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}"; 
$waiting_patients_query = "SELECT COUNT(*) AS waiting_patients_count FROM patients WHERE status = '0' AND branch_id = {$branch_id}";

$out_of_stock_res = safe_mysqli_query($conn, $out_of_stock_query);
$out_of_stock_data = $out_of_stock_res ? mysqli_fetch_assoc($out_of_stock_res) : ['total' => 0];

$expired_res = safe_mysqli_query($conn, $expired_products_query);
$expired_data = $expired_res ? mysqli_fetch_assoc($expired_res) : ['expired_products' => 0];

$today_res = safe_mysqli_query($conn, $today_transactions_query);
$today_data = $today_res ? mysqli_fetch_assoc($today_res) : ['total_transactions' => 0];

$waiting_res = safe_mysqli_query($conn, $waiting_patients_query);
$waiting_data = $waiting_res ? mysqli_fetch_assoc($waiting_data) : ['waiting_patients_count' => 0];
?>

<div class="page-wrapper" style="margin-left: 250px; padding: 20px; background-color: #f4f6f9; min-height: 100vh;">
    
    <!-- Top Action Bar -->
    <div class="card border-0 shadow-sm mb-4 rounded-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="fw-bold mb-0 text-dark">Dashboard</h4>
                <small class="text-muted">Branch System Management Overview</small>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <a href="sell_now.php" class="btn btn-outline-primary btn-sm"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                <a href="lay_by_sell.php" class="btn btn-outline-secondary btn-sm"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                <a href="expenses.php" class="btn btn-outline-info btn-sm"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                <a href="sales_report.php" class="btn btn-outline-dark btn-sm"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary btn-sm text-white"><i class="fas fa-user-plus me-1"></i> Add Patient</a>
            </div>
        </div>
    </div>

    <!-- Dashboard Content Grid -->
    <div class="container-fluid p-0">
        <div class="row g-4">
            
            <!-- Left Side: Main Action Cards (2 Columns per row) -->
            <div class="col-xl-9 col-lg-8">
                <div class="row g-3">
                    
                    <div class="col-md-6">
                        <a href="sell_now.php" class="card text-white text-decoration-none shadow-sm border-0 rounded-3" style="background: linear-gradient(135deg, #007bff, #0056b3);">
                            <div class="card-body p-4 text-center">
                                <h5 class="card-title text-white mb-2 fs-4 fw-bold">Sell Now</h5>
                                <p class="card-text text-white-50 mb-0 fs-6">Point of Sale Terminal</p>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-6">
                        <a href="today_transactions.php" class="card text-white text-decoration-none shadow-sm border-0 rounded-3" style="background: linear-gradient(135deg, #17a2b8, #117a8b);">
                            <div class="card-body p-4 text-center">
                                <h5 class="card-title text-white mb-2 fs-5">Today's Sales</h5>
                                <h2 class="display-5 fw-bold text-white my-1"><?php echo $today_data['total_transactions'] ?? 0; ?></h2>
                                <span class="badge bg-light text-dark">Transactions Completed</span>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-6">
                        <a href="out_of_stock.php" class="card text-white text-decoration-none shadow-sm border-0 rounded-3" style="background: linear-gradient(135deg, #ffc107, #d39e00);">
                            <div class="card-body p-4 text-center">
                                <h5 class="card-title text-dark mb-2 fs-5">Out of Stock</h5>
                                <h2 class="display-5 fw-bold text-dark my-1"><?php echo $out_of_stock_data['total'] ?? 0; ?></h2>
                                <span class="badge bg-dark text-white">Items Requiring Reorder</span>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-6">
                        <a href="expired_products.php" class="card text-white text-decoration-none shadow-sm border-0 rounded-3" style="background: linear-gradient(135deg, #dc3545, #bd2130);">
                            <div class="card-body p-4 text-center">
                                <h5 class="card-title text-white mb-2 fs-5">Expired Stock</h5>
                                <h2 class="display-5 fw-bold text-white my-1"><?php echo $expired_data['expired_products'] ?? 0; ?></h2>
                                <span class="badge bg-light text-danger">Action Required</span>
                            </div>
                        </a>
                    </div>

                </div>
            </div>

            <!-- Right Side: System Alerts -->
            <div class="col-xl-3 col-lg-4">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold mb-0 text-primary"><i class="mdi mdi-bell-ring-outline me-2"></i>Live System Alerts</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                            <span>Waiting Patients</span>
                            <span class="badge bg-primary rounded-pill"><?php echo $waiting_data['waiting_patients_count'] ?? 0; ?></span>
                        </a>
                        <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                            <span>Low Stock Alerts</span>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_data['total'] ?? 0; ?></span>
                        </a>
                        <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                            <span>Expired Items</span>
                            <span class="badge bg-danger rounded-pill"><?php echo $expired_data['expired_products'] ?? 0; ?></span>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
