<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include core backend files
require_once "../includes/conn.php";
require_once "../includes/auth.php";

// START BRANCH FILTER SETUP
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? ''; 

// Generate unique invoice number
function generateInvoiceNumber() {
    return 'INV-' . mt_rand(1000000, 9797977);
}
$invoice_number = generateInvoiceNumber();

// Safe query execution function
function safe_mysqli_query($conn, $query) {
    $result = @mysqli_query($conn, $query);
    return ($result === false) ? null : $result;
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

// --- Execute Queries ---
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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHARMANOVA - Dashboard</title>

    <!-- Core CSS (Bootstrap + Icons) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Theme CSS (Root relative paths) -->
    <link href="/assets/libs/flot/css/float-chart.css" rel="stylesheet">
    <link href="/dist/css/style.min.css" rel="stylesheet">

    <style>
        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: #f0f2f5; 
        }
        .page-wrapper { 
            min-height: 100vh; 
            padding: 20px; 
        }
        .card-stats { 
            border-radius: 0.75rem; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            transition: transform 0.3s ease; 
            color: #fff; 
            border: none;
        }
        .card-stats:hover { 
            transform: translateY(-5px); 
        }
        .stat-card-sales { background: linear-gradient(135deg, #4a90e2, #50b0f0); }
        .stat-card-stock { background: linear-gradient(135deg, #6b7a8f, #4d5e7a); }
        .stat-card-out-of-stock { background: linear-gradient(135deg, #f5a623, #d0021b); }
        .stat-card-expired { background: linear-gradient(135deg, #d0021b, #9b1e22); }
        .stat-card-items { background: linear-gradient(135deg, #34495e, #2c3e50); }
        .page-breadcrumb { 
            background-color: #fff; 
            padding: 1rem; 
            border-radius: 0.75rem; 
            margin-bottom: 2rem; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
        }
    </style>
</head>
<body>

<div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5">

    <?php 
    // Re-include top header and sidebar navigation
    require_once "../includes/header.php"; 
    require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h4 class="page-title mb-0">Dashboard</h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 p-0">
                            <li class="breadcrumb-item"><a href="#" class="text-decoration-none">Home</a></li>
                        </ol>
                    </nav>
                </div>
                <div class="col-md-8 d-flex justify-content-end align-items-center flex-wrap gap-2">
                    <a href="sell_now.php" class="text-dark me-2 text-decoration-none"><i class="mdi mdi-cash-multiple me-1"></i> Sell Now</a>
                    <a href="lay_by_sell.php" class="text-dark me-2 text-decoration-none"><i class="mdi mdi-credit-card-plus me-1"></i> Lay-By Sell</a>
                    <a href="expenses.php" class="text-dark me-2 text-decoration-none"><i class="mdi mdi-chart-bar me-1"></i> Expenses</a>
                    <a href="sales_report.php" class="text-dark me-2 text-decoration-none"><i class="mdi mdi-chart-line me-1"></i> Sales Report</a>
                    <a class="text-dark me-2 text-decoration-none" href="sales_trend.php"><i class="fas fa-chart-line me-1"></i> Sales Trend</a>
                    <a href="add_patients.php?invoice=<?php echo $invoice_number; ?>" class="btn btn-primary btn-sm text-white shadow-sm ms-2">
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
                        <!-- Sell Now -->
                        <div class="col-md-4 col-sm-6">
                            <a href="sell_now.php" class="card card-stats stat-card-sales text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Sell Now</h4>
                                    <h2 class="my-2 text-white fw-bold">All Items</h2>
                                </div>
                            </a>
                        </div>
                        <!-- Today's Transactions -->
                        <div class="col-md-4 col-sm-6">
                            <a href="today_transactions.php" class="card card-stats stat-card-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h5 class="mb-0 text-white">Today's Transactions</h5>
                                    <h3 class="my-2 text-warning fw-bold"><?php echo $today_transactions_data['total_transactions'] ?? 0; ?></h3>
                                </div>
                            </a>
                        </div>
                        <!-- Out of Stock -->
                        <div class="col-md-4 col-sm-6">
                            <a href="out_of_stock.php" class="card card-stats stat-card-out-of-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Out of Stock</h4>
                                    <h2 class="my-2 text-white fw-bold"><?php echo $out_of_stock_count; ?></h2>
                                </div>
                            </a>
                        </div>
                        <!-- Expired Products -->
                        <div class="col-md-4 col-sm-6">
                            <a href="expired_products.php" class="card card-stats stat-card-expired text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Expired Products</h4>
                                    <h2 class="my-2 text-white fw-bold"><?php echo $expired_products_data['expired_products'] ?? 0; ?></h2>
                                </div>
                            </a>
                        </div>
                        <!-- Customer Service -->
                        <div class="col-md-4 col-sm-6">
                            <a href="customers.php" class="card card-stats stat-card-items text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Customer</h4>
                                    <h2 class="my-2 text-white fw-bold">Service</h2>
                                </div>
                            </a>
                        </div>
                        <!-- Prescription Online -->
                        <div class="col-md-4 col-sm-6">
                            <a href="online_manager.php" class="card card-stats stat-card-out-of-stock text-center text-decoration-none h-100">
                                <div class="card-body py-4">
                                    <h4 class="mb-0 text-white">Prescription</h4>
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
                            <a href="waiting_patients.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($waiting_patients_count > 0) ? 'list-group-item-danger' : ''; ?>">
                                <span><strong>Patients Waiting</strong></span>
                                <span class="badge bg-danger rounded-pill"><?php echo $waiting_patients_count; ?></span>
                            </a>
                            <a href="out_of_stock.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($out_of_stock_count > 0) ? 'list-group-item-warning' : ''; ?>">
                                <span><strong>Out of Stock</strong></span>
                                <span class="badge bg-warning text-dark rounded-pill"><?php echo $out_of_stock_count; ?></span>
                            </a>
                            <a href="expired_products.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 <?php echo ($expired_products_data['expired_products'] > 0) ? 'list-group-item-danger' : ''; ?>">
                                <span><strong>Expired Products</strong></span>
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
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
