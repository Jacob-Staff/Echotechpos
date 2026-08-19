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

// 1. Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// 2. Query sales data joining store_items, sales_items, and users
$sql = "SELECT s.*, 
        COALESCE(u.username, u.full_name, s.issued_by, 'System') as issuer,
        (SELECT GROUP_CONCAT(CONCAT(st.item_name, ' (x', si.quantity, ')') SEPARATOR ', ') 
         FROM sales_items si 
         JOIN store_items st ON si.product_id = st.id 
         WHERE si.sale_id = s.id) as items_sold,
        (SELECT SUM(total) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_total,
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

$total_revenue = 0; 
$total_invoices = 0; 
$sales_data = [];

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
.report-wrapper {
    background-color: #ffffff !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.stat-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 1.25rem;
}

.stat-card-cyan {
    border-left: 4px solid #0d6efd;
}

.stat-card-orange {
    border-left: 4px solid #ffc107;
}

.report-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead th {
    background: #f1f3f5;
    padding: 14px;
    text-align: left;
    font-size: 12px;
    color: #495057 !important;
    text-transform: uppercase;
    border-bottom: 2px solid #dee2e6;
}

.report-table tbody td {
    padding: 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: #f8f9fa;
}

@media print {
    .no-print { display: none !important; }
    .report-wrapper { padding: 0 !important; }
    .stat-card { border: 1px solid #ccc !important; }
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
                    <h3 class="fw-bold text-dark mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                    <span class="text-muted small">Branch: <b><?php echo htmlspecialchars($display_bran); ?></b> | Date: <b class="text-dark"><?php echo $display_date; ?></b></span>
                </div>
                <div class="no-print">
                    <form method="GET" class="d-inline-flex gap-2">
                        <input type="date" name="filter_date" class="form-control form-control-sm bg-light text-dark border-secondary" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                        <button type="button" class="btn btn-outline-dark btn-sm px-3" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-card-cyan">
                        <div class="small fw-bold text-uppercase text-muted">Revenue (<?php echo $display_date; ?>)</div>
                        <div class="h2 mb-0 fw-bold text-primary">K<?php echo number_format($total_revenue, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-card-orange">
                        <div class="small fw-bold text-uppercase text-muted">Total Invoices</div>
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
                                <th>Method</th>
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
                                        <td class="ps-3 fw-bold text-primary">#<?php echo htmlspecialchars($row['invoice']); ?></td>
                                        <td><?php echo htmlspecialchars($row['items_sold'] ?: 'No items recorded'); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['payment_method'] ?: 'Cash'); ?></span></td>
                                        <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['issuer']); ?></td>
                                        <td class="text-end pe-3 fw-bold text-success">K<?php echo number_format($row['total'], 2); ?></td>
                                        <td class="text-center no-print">
                                            <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-light border text-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No transactions recorded for this date.</td>
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
