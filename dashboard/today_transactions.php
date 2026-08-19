<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)$_SESSION['pharmacy_id'];
$b_id = (int)$_SESSION['branch_id'];

// Capture Date from GET request, default to Today
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$display_date = date('d M Y', strtotime($filter_date));

// 1. Fetch Branding
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Pharmanova LSK';

// 2. Query sales data with prepared statement placeholders ("iisiisiis")
$sql = "SELECT s.*, u.username as issuer,
        (SELECT GROUP_CONCAT(st.item_name SEPARATOR ', ') 
         FROM sales_items si 
         JOIN store_items st ON si.product_id = st.id 
         WHERE si.sale_id = s.id) as items_sold,
        (SELECT SUM(total_amount) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_total,
        (SELECT COUNT(*) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_count
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.pharmacy_id = ? 
        AND s.branch_id = ? 
        AND DATE(s.created_at) = ? 
        ORDER BY s.id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iisiisiis", $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_revenue = 0; $total_invoices = 0; $sales_data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_revenue = $row['day_total'] ?? 0;
        $total_invoices = $row['day_count'] ?? 0;
    }
}

require_once "../includes/head.php";
?>

<style>
:root { 
    --neon-green: #00ffae; 
    --dark-bg: #0f0f0f; 
    --panel-bg: #1a1a1a; 
    --row-hover: #252525; 
}

.report-wrapper {
    background-color: var(--dark-bg) !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #ffffff;
}

.stat-card {
    background: #1e1e1e;
    border: 1px solid #333;
    border-radius: 12px;
    padding: 1.25rem;
}

.stat-card-cyan {
    border-left: 4px solid #00a8ff;
}

.stat-card-orange {
    border-left: 4px solid #fbc531;
}

.report-table-container {
    background-color: var(--panel-bg);
    border-radius: 15px;
    border: 1px solid #333;
    overflow: hidden;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead th {
    background: #222;
    padding: 14px;
    text-align: left;
    font-size: 11px;
    color: #ffffff !important;
    text-transform: uppercase;
    border-bottom: 1px solid #444;
}

.report-table tbody td {
    padding: 14px;
    color: #ffffff;
    border-bottom: 1px solid #282828;
    font-size: 13.5px;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: var(--row-hover);
}

@media print {
    .no-print { display: none !important; }
    .report-wrapper { background: white !important; color: black !important; padding: 0 !important; }
    .stat-card { border: 1px solid #ccc !important; color: black !important; }
    .report-table thead th { background: #eee !important; color: black !important; }
    .report-table tbody td { color: black !important; border-bottom: 1px solid #ddd !important; }
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper report-wrapper">
        <div class="container-fluid p-0">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-white mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                    <span class="small" style="color: #aaaaaa;">Report for: <b class="text-white"><?php echo $display_date; ?></b></span>
                </div>
                <div class="no-print">
                    <form method="GET" class="d-inline-flex gap-2">
                        <input type="date" name="filter_date" class="form-control form-control-sm bg-dark text-white border-secondary" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                        <button type="button" class="btn btn-outline-light btn-sm px-3" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-cyan">
                        <div class="small fw-bold text-uppercase" style="color: #aaaaaa;">Revenue (<?php echo $display_date; ?>)</div>
                        <div class="h2 mb-0 fw-bold text-info">K<?php echo number_format($total_revenue, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-card-orange">
                        <div class="small fw-bold text-uppercase" style="color: #aaaaaa;">Total Invoices</div>
                        <div class="h2 mb-0 fw-bold text-warning"><?php echo $total_invoices; ?></div>
                    </div>
                </div>
            </div>

            <div class="report-table-container">
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th class="ps-3">Invoice #</th>
                                <th>Medicines Sold</th>
                                <th>Time</th>
                                <th>Handled By</th>
                                <th class="text-end pe-3">Total (ZMW)</th>
                                <th class="text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($sales_data)): ?>
                                <?php foreach ($sales_data as $row): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-info">#<?php echo htmlspecialchars($row['invoice']); ?></td>
                                        <td><?php echo htmlspecialchars($row['items_sold'] ?: 'No items'); ?></td>
                                        <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['issuer'] ?? 'System'); ?></td>
                                        <td class="text-end pe-3 fw-bold text-success">K<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td class="text-center no-print">
                                            <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="text-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5" style="color: #aaaaaa;">No transactions recorded for this date.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
