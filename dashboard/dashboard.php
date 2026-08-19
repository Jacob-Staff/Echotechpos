<?php
// Force maximum error visibility
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Wrap includes in try-catch to reveal fatal path issues
try {
    require_once "../includes/conn.php";
    require_once "../includes/auth.php";
} catch (Throwable $e) {
    die("<div style='color:red; padding:20px; font-weight:bold;'>Database/Auth Include Error: " . $e->getMessage() . "</div>");
}

// Set fallbacks for missing session variables
$branch_id = isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 1; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 1; 

function safe_mysqli_query($conn, $query) {
    if (!$conn) return null;
    $result = @mysqli_query($conn, $query);
    return ($result === false) ? null : $result;
}

// Query Execution
$sales_result = safe_mysqli_query($conn, "SELECT SUM(total) AS total_sales FROM sales WHERE branch_id = {$branch_id}");
$sales_data = $sales_result ? mysqli_fetch_assoc($sales_result) : ['total_sales' => 0];

$out_of_stock_result = safe_mysqli_query($conn, "SELECT COUNT(*) as total FROM store_items WHERE branch_id = '{$branch_id}' AND quantity <= 0");
$out_of_stock_data = $out_of_stock_result ? mysqli_fetch_assoc($out_of_stock_result) : ['total' => 0];
$out_of_stock_count = $out_of_stock_data['total'] ?? 0;

$expired_result = safe_mysqli_query($conn, "SELECT COUNT(*) AS expired FROM store_items WHERE expiry_date < CURDATE() AND branch_id = {$branch_id}");
$expired_data = $expired_result ? mysqli_fetch_assoc($expired_result) : ['expired' => 0];

$today_transactions_result = safe_mysqli_query($conn, "SELECT COUNT(*) AS total FROM sales WHERE DATE(sale_date) = CURDATE() AND branch_id = {$branch_id}");
$today_transactions_data = $today_transactions_result ? mysqli_fetch_assoc($today_transactions_result) : ['total' => 0];

$waiting_patients_result = safe_mysqli_query($conn, "SELECT COUNT(*) AS total FROM patients WHERE status = '0' AND branch_id = {$branch_id}");
$waiting_patients_data = $waiting_patients_result ? mysqli_fetch_assoc($waiting_patients_result) : ['total' => 0];

// Output Document Head
require_once "../includes/head.php";
?>

<div id="main-wrapper">
    <?php 
    require_once "../includes/header.php"; 
    
    // Test if aside.php is breaking execution
    if (file_exists("../includes/aside.php")) {
        include_once "../includes/aside.php";
    } else {
        echo "<div class='alert alert-warning m-3'>Warning: includes/aside.php not found</div>";
    }
    ?>

    <!-- Main Content Wrapper with inline layout fallbacks -->
    <div class="page-wrapper" style="margin-left: 250px; padding: 90px 20px 20px 20px; display: block !important;">
        <div class="container-fluid">
            
            <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
                <h4 class="mb-0 text-dark fw-bold">Dashboard</h4>
                <div>
                    <a href="sell_now.php" class="btn btn-primary btn-sm me-2"><i class="fas fa-shopping-cart me-1"></i> Sell Now</a>
                    <a href="sales_report.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chart-line me-1"></i> Sales Report</a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-4 bg-primary text-white rounded-3 shadow-sm">
                        <h5>Today's Sales</h5>
                        <h2 class="fw-bold mb-0">$<?php echo number_format($sales_data['total_sales'] ?? 0, 2); ?></h2>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-4 bg-warning text-dark rounded-3 shadow-sm">
                        <h5>Out of Stock</h5>
                        <h2 class="fw-bold mb-0"><?php echo $out_of_stock_count; ?></h2>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-4 bg-danger text-white rounded-3 shadow-sm">
                        <h5>Expired Items</h5>
                        <h2 class="fw-bold mb-0"><?php echo $expired_data['expired'] ?? 0; ?></h2>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="p-4 bg-white rounded-3 shadow-sm">
                        <h5>Transactions Today</h5>
                        <h3 class="text-secondary fw-bold"><?php echo $today_transactions_data['total'] ?? 0; ?></h3>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-4 bg-white rounded-3 shadow-sm">
                        <h5>Waiting Patients</h5>
                        <h3 class="text-danger fw-bold"><?php echo $waiting_patients_data['total'] ?? 0; ?></h3>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
