<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

// Include database and authentication setup
require_once "../includes/conn.php";
require_once "../includes/auth.php";

// START BRANCH FILTER SETUP
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? ''; 
// END BRANCH FILTER SETUP

// Generate unique invoice number
function generateInvoiceNumber() {
    $number = mt_rand(1000000, 9797977);
    return 'INV-' . $number;
}
$invoice_number = generateInvoiceNumber();

// Safe query execution function
function safe_mysqli_query($conn, $query) {
    $result = @mysqli_query($conn, $query);
    if ($result === false) {
        return null;
    }
    return $result;
}

// --- Dashboard Data Queries ---
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
$payable_query = "SELECT SUM(price) AS total_payable FROM purchase_order WHERE status = '0' AND branch_id = {$branch_id}";
$items_list_query = "SELECT COUNT(*) AS total_items FROM store_items WHERE branch_id = {$branch_id}"; 
$today_transactions_query = "SELECT COUNT(*) AS total_transactions FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}"; 
$waiting_patients_query = "SELECT COUNT(*) AS waiting_patients_count FROM patients WHERE status = '0' AND branch_id = {$branch_id}";

// --- Execute Queries and Fetch Data ---
$sales_result = safe_mysqli_query($conn, $sales_query);
$sales_data = $sales_result ? mysqli_fetch_assoc($sales_result) : ['total_sales' => 0];

$stock_result = safe_mysqli_query($conn, $stock_query);
$stock_data = $stock_result ? mysqli_fetch_assoc($stock_result) : ['total_stock' => 0];

$out_of_stock_result = safe_mysqli_query($conn, $out_of_stock_query);
$out_of_stock_data = $out_of_stock_result ? mysqli_fetch_assoc($out_of_stock_result) : ['total' => 0];
$out_of_stock_count = $out_of_stock_data['total'] ?? 0;

$expired_products_result = safe_mysqli_query($conn, $expired_products_query);
$expired_products_data = $expired_products_result ? mysqli_fetch_assoc($expired_products_result) : ['expired_products' => 0];

$today_transactions_result = safe_mysqli_query($conn, $today_transactions_query);
$today_transactions_data = $today_transactions_result ? mysqli_fetch_assoc($today_transactions_result) : ['total_transactions' => 0];

$waiting_patients_result = safe_mysqli_query($conn, $waiting_patients_query);
$waiting_patients_data = $waiting_patients_result ? mysqli_fetch_assoc($waiting_patients_result) : ['waiting_patients_count' => 0];
$waiting_patients_count = $waiting_patients_data['waiting_patients_count'] ?? 0;
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; }
        .page-wrapper { flex-grow: 1; padding: 1rem; min-height: 100vh; display: flex; flex-direction: column; }
        .card-stats { background-color: #fff; border-radius: 0.75rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease; color: #fff; }
        .card-stats:hover { transform: translateY(-5px); }
        .card-stats .card-body { background-color: rgba(0, 0, 0, 0.1); border-radius: 0.75rem; }
        .stat-card-sales { background-image: linear-gradient(to right, #4a90e2, #50b0f0); }
        .stat-card-stock { background-image: linear-gradient(to right, #6b7a8f, #4d5e7a); }
        .stat-card-out-of-stock { background-image: linear-gradient(to right, #f5a623, #d0021b); }
        .stat-card-expired { background-image: linear-gradient(to right, #d0021b, #9b1e22); }
        .stat-card-items { background-image: linear-gradient(to right, #34495e, #2c3e50); }
        .page-breadcrumb { background-color: #fff; padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }

        /* Responsive Mobile Adjustments */
        @media (max-width: 768px) {
            .page-breadcrumb .row { flex-direction: column; gap: 0.75rem; text-align: center; }
            .action-links { flex-wrap: wrap; justify-content: center !important; gap: 0.5rem; }
            .action-links a { margin: 0.2rem !important; font-size: 0.875rem; }
            .btn-add-patient { width: 100%; margin-top: 0.5rem; }
            .page-wrapper { padding: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="page-breadcrumb">
        <div class="row align-items-center">
            <div class="col-12 col-md-3 text-center text-md-start mb-2 mb-md-0">
                <h4 class="page-title mb-0">Dashboard</h4>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 p-0 justify-content-center justify-content-md-start">
                        <li class="breadcrumb-item"><a href="#">Home</a></li>
                    </ol>
                </nav>
            </div>
            <div class="col-12 col-md-6 d-flex justify-content-center align-items-center action-links mb-2 mb-md-0">
                <a href="sell_now.php" class="text-dark mx-2"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                <a href="lay_by_sell.php" class="text-dark mx-2"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                <a href="expenses.php" class="text-dark mx-2"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                <a href="sales_report.php" class="text-dark mx-2"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                <a class="text-dark mx-2" href="sales_trend.php"><i class="fas fa-chart-line me-1"></i> Sales Trend</a>
            </div>
            <div class="col-12 col-md-3 text-center text-md-end">
                <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary text-white shadow-sm btn-add-patient">
                    <i class="fas fa-user-plus me-2"></i> Add Patient
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

        <div class="row g-3">
            <div class="col-12 col-lg-9">
                <div class="row g-3 mb-3">
                    <!-- Dashboard Cards -->
                    <!-- Sell Now -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="sell_now.php" class="card card-stats stat-card-sales text-center text-decoration-none h-100">
                            <div class="card-body py-4">
                                <h4 class="mb-0 text-white">Sell Now</h4>
                                <h2 class="my-2 text-white">All Items</h2>
                            </div>
                        </a>
                    </div>
                    <!-- Today's Transactions -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="today_transactions.php" class="card card-stats stat-card-stock text-center text-decoration-none h-100">
                            <div class="card-body py-4">
                                <h5 class="mb-0 text-white">Today's Transactions</h5>
                                <h3 class="my-2 text-warning fw-bold"><?php echo $today_transactions_data['total_transactions']; ?></h3>
                            </div>
                        </a>
                    </div>
                    <!-- Out of Stock -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="out_of_stock.php" class="card card-stats stat-card-out-of-stock text-center text-decoration-none h-100">
                            <div class="card-body py-4">
                                <h4 class="mb-0 text-white">Out of Stock</h4>
                                <h2 class="my-2 text-white"><?php echo $out_of_stock_count; ?></h2>
                            </div>
                        </a>
                    </div>
                    <!-- Expired Products -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="expired_products.php" class="card card-stats stat-card-expired text-center text-decoration-none h-100">
                            <div class="card-body py-4">
                                <h4 class="mb-0 text-white">Expired Products</h4>
                                <h2 class="my-2 text-white"><?php echo $expired_products_data['expired_products']; ?></h2>
                            </div>
                        </a>
                    </div>
                    <!-- Customer Service -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="customers.php" class="card card-stats stat-card-items text-center text-decoration-none h-100">
                            <div class="card-body py-4">
                                <h4 class="mb-0 text-white">Customer</h4>
                                <h2 class="my-2 text-white">Service</h2>
                            </div>
                        </a>
                    </div>
                    <!-- Prescription Online -->
                    <div class="col-12 col-sm-6 col-md-4">
                        <a href="online_manager.php" class="text-decoration-none">
                            <div class="card card-stats stat-card-out-of-stock text-center h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Prescription</h4>
                                    <h2 class="my-2 text-white">Online</h2>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Alerts Sidebar -->
            <div class="col-12 col-lg-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="mdi mdi-bell-ring-outline me-2"></i>Urgent Alerts</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($waiting_patients_count > 0) ? 'list-group-item-danger' : ''; ?>">
                            <strong>Patients Waiting</strong>
                            <span class="badge bg-danger rounded-pill"><?php echo $waiting_patients_count; ?></span>
                        </a>
                        <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($out_of_stock_count > 0) ? 'list-group-item-warning' : ''; ?>">
                            <strong>Out of Stock</strong>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_count; ?></span>
                        </a>
                        <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($expired_products_data['expired_products'] > 0) ? 'list-group-item-danger' : ''; ?>">
                            <strong>Expired Products</strong>
                            <span class="badge bg-danger rounded-pill"><?php echo $expired_products_data['expired_products']; ?></span>
                        </a>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <span>Pending orders</span>
                            <span class="badge bg-secondary rounded-pill">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>
