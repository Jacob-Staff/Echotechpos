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

// Queries
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
$waiting_data = $waiting_res ? mysqli_fetch_assoc($waiting_res) : ['waiting_patients_count' => 0];
?>

<div class="page-wrapper">
    <!-- Breadcrumb Header -->
    <div class="page-breadcrumb bg-white p-3 mb-4 rounded shadow-sm">
        <div class="row align-items-center">
            <div class="col">
                <h4 class="page-title text-dark fw-bold mb-0">Dashboard</h4>
                <small class="text-muted"><a href="#" class="text-decoration-none">Home</a></small>
            </div>
            <div class="col-auto d-flex gap-3 align-items-center">
                <a href="sell_now.php" class="text-dark text-decoration-none small"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                <a href="lay_by_sell.php" class="text-dark text-decoration-none small"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                <a href="expenses.php" class="text-dark text-decoration-none small"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                <a href="sales_report.php" class="text-dark text-decoration-none small"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                <a href="sales_trend.php" class="text-dark text-decoration-none small"><i class="fas fa-chart-line me-1"></i> Sales Trend</a>
                <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary btn-sm text-white shadow-sm">
                    <i class="fas fa-user-plus me-1"></i> Add Patient
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content Body -->
    <div class="container-fluid p-0">
        <div class="row g-4">
            <!-- Left Cards Grid -->
            <div class="col-lg-9">
                <div class="row g-3">
                    <div class="col-md-4">
                        <a href="sell_now.php" class="card text-white stat-card-sales text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Sell Now</h5>
                                <h3 class="my-2 text-white fw-bold">All Items</h3>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="today_transactions.php" class="card text-white stat-card-stock text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Today's Transactions</h5>
                                <h2 class="my-2 text-warning fw-bold"><?php echo $today_data['total_transactions'] ?? 0; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="out_of_stock.php" class="card text-white stat-card-out-of-stock text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Out of Stock</h5>
                                <h2 class="my-2 text-white fw-bold"><?php echo $out_of_stock_data['total'] ?? 0; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="expired_products.php" class="card text-white stat-card-expired text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Expired Products</h5>
                                <h2 class="my-2 text-white fw-bold"><?php echo $expired_data['expired_products'] ?? 0; ?></h2>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="customers.php" class="card text-white stat-card-items text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Customer</h5>
                                <h3 class="my-2 text-white fw-bold">Service</h3>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="online_manager.php" class="card text-white stat-card-out-of-stock text-center text-decoration-none hover-card border-0 rounded-3 shadow-sm">
                            <div class="card-body py-4">
                                <h5 class="mb-1 text-white">Prescription</h5>
                                <h3 class="my-2 text-white fw-bold">Online</h3>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar Alerts -->
            <div class="col-lg-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold mb-0 text-primary"><i class="mdi mdi-bell-ring-outline me-2"></i>Urgent Alerts</h6>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                            <span>Patients Waiting</span>
                            <span class="badge bg-danger rounded-pill"><?php echo $waiting_data['waiting_patients_count'] ?? 0; ?></span>
                        </a>
                        <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 bg-light-warning">
                            <span>Out of Stock</span>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_data['total'] ?? 0; ?></span>
                        </a>
                        <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 bg-light-danger">
                            <span>Expired Products</span>
                            <span class="badge bg-danger rounded-pill"><?php echo $expired_data['expired_products'] ?? 0; ?></span>
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
</div>

<?php require_once "../includes/footer.php"; ?>
