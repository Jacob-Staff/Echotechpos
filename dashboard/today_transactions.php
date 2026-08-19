<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

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

// 2. Query sales data with 9 prepared statement placeholders ("iisiisiis")
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

// Import header layout template (includes <head>, header bar, and sidebar)
require_once "../includes/header.php";
?>

<div class="page-wrapper p-4">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-light mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                <span class="text-muted small">Report for: <b><?php echo $display_date; ?></b></span>
            </div>
            <div class="no-print">
                <form method="GET" class="d-inline-flex gap-2">
                    <input type="date" name="filter_date" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                    <button type="button" class="btn btn-secondary btn-sm px-3" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </form>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-3">
                <div class="p-3 rounded text-white" style="background-color: #00a8ff;">
                    <div class="small fw-bold text-uppercase opacity-75">Revenue (<?php echo $display_date; ?>)</div>
                    <div class="fs-2 fw-bold">K<?php echo number_format($total_revenue, 2); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 rounded text-dark" style="background-color: #fbc531;">
                    <div class="small fw-bold text-uppercase opacity-75">Total Invoices</div>
                    <div class="fs-2 fw-bold"><?php echo $total_invoices; ?></div>
                </div>
            </div>
        </div>

        <div class="card bg-dark text-light border-secondary mt-4">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
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
                                    <td class="text-end pe-3 fw-bold">K<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td class="text-center no-print">
                                        <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="text-info">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">No transactions recorded for this date.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php 
// Import footer template
require_once "../includes/footer.php"; 
?>
