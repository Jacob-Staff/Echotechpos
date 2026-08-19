<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";
date_default_timezone_set('Africa/Lusaka');

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

if (!$p_id || !$b_id) {
    die("<div class='alert alert-danger text-center mt-4'>Session expired. Please log in again.</div>");
}

// Capture Date from GET request, default to Today
$filter_date = isset($_GET['filter_date']) && !empty($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$display_date = date('d M Y', strtotime($filter_date));

// 1. Fetch Pharmacy & Branch Branding
$info_stmt = mysqli_prepare($conn, "SELECT p.name as corp_name, b.branch_name, b.location, b.phone FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info = mysqli_fetch_assoc(mysqli_stmt_get_result($info_stmt));

$display_pharm = $info['corp_name'] ?? 'Echo Prime Pharmacy';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// 2. Query Sales Transactions for Selected Date
$sql = "SELECT s.*, 
               COALESCE(u.username, s.issued_by, 'Staff') as issuer,
               (SELECT GROUP_CONCAT(CONCAT(st.item_name, ' (x', si.quantity, ')') SEPARATOR ', ') 
                FROM sales_items si 
                JOIN store_items st ON si.product_id = st.id 
                WHERE si.sale_id = s.id) as items_sold,
               (SELECT SUM(total_amount) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_total,
               (SELECT SUM(vat_amount) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_vat,
               (SELECT COUNT(*) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_count
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.pharmacy_id = ? 
        AND s.branch_id = ? 
        AND DATE(s.created_at) = ? 
        ORDER BY s.id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iisiisiisii", $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_revenue = 0; 
$total_vat = 0;
$total_invoices = 0; 
$sales_data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_revenue = $row['day_total'] ?? 0;
        $total_vat = $row['day_vat'] ?? 0;
        $total_invoices = $row['day_count'] ?? 0;
    }
}
?>

<style>
    body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
    .page-wrapper { padding-top: 15px; }
    .stat-card { border: none; border-radius: 8px; color: white; margin-bottom: 20px; }
    .bg-matrix-cyan { background: linear-gradient(135deg, #1e88e5, #1565c0); box-shadow: 0 4px 10px rgba(30, 136, 229, 0.25); }
    .bg-matrix-orange { background: linear-gradient(135deg, #ff9800, #f57c00); box-shadow: 0 4px 10px rgba(255, 152, 0, 0.25); }
    .bg-matrix-green { background: linear-gradient(135deg, #2e7d32, #1b5e20); box-shadow: 0 4px 10px rgba(46, 125, 50, 0.25); }
    .stat-card .card-body { padding: 18px 22px; }
    .stat-card h2 { font-size: 1.8rem; margin: 0; font-weight: 700; }
    .stat-card p { margin: 0; opacity: 0.9; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

    .table-box { background: #fff; border-radius: 8px; border: 1px solid #e9ecef; }
    .table thead th { background-color: #1f262d; color: #fff; font-size: 12px; padding: 14px 12px; font-weight: 600; }
    .table tbody td { font-size: 13.5px; padding: 12px; vertical-align: middle; border-bottom: 1px solid #f1f3f5; }
    
    .item-list { color: #333; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .badge-method { font-size: 11px; padding: 5px 10px; border-radius: 4px; font-weight: 600; text-transform: uppercase; }

    /* Thermal print preview inside iframe */
    #receiptPrintFrame { display: none; width: 0; height: 0; border: none; }

    @media print {
        .no-print { display: none !important; }
        .page-wrapper { padding: 0; }
    }
</style>

<div class="container-fluid page-wrapper">

    <!-- Header Section -->
    <div class="row align-items-center mb-4">
        <div class="col-md-6">
            <h4 class="fw-bold text-dark mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h4>
            <span class="text-muted small"><?php echo htmlspecialchars($display_bran); ?> &bull; Report Date: <b><?php echo $display_date; ?></b></span>
        </div>
        <div class="col-md-6 text-end no-print">
            <form method="GET" class="d-inline-flex align-items-center gap-2">
                <input type="date" name="filter_date" class="form-control form-control-sm" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                <button type="button" class="btn btn-dark btn-sm px-3" onclick="window.print()">
                    <i class="mdi mdi-printer me-1"></i> Print Summary
                </button>
            </form>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card bg-matrix-cyan">
                <div class="card-body">
                    <p>Total Sales Revenue</p>
                    <h2>K<?php echo number_format($total_revenue, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-matrix-green">
                <div class="card-body">
                    <p>VAT Collected (16%)</p>
                    <h2>K<?php echo number_format($total_vat, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-matrix-orange">
                <div class="card-body">
                    <p>Completed Transactions</p>
                    <h2><?php echo $total_invoices; ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="table-box shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Invoice #</th>
                        <th>Medicines Sold</th>
                        <th>Time</th>
                        <th>Method</th>
                        <th>Served By</th>
                        <th class="text-end pe-3">Total Amount</th>
                        <th class="text-center no-print">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sales_data)): ?>
                        <?php foreach ($sales_data as $row): ?>
                            <?php 
                                $method = strtoupper($row['payment_method'] ?? 'CASH');
                                $badge_bg = ($method === 'CARD') ? 'bg-info' : (($method === 'MOBILE MONEY' || $method === 'MOBILE') ? 'bg-warning text-dark' : 'bg-success');
                            ?>
                            <tr>
                                <td class="ps-3 fw-bold text-primary">#<?php echo htmlspecialchars($row['invoice']); ?></td>
                                <td class="item-list" title="<?php echo htmlspecialchars($row['items_sold'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($row['items_sold'] ?: 'N/A'); ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                <td><span class="badge <?php echo $badge_bg; ?> badge-method"><?php echo $method; ?></span></td>
                                <td><?php echo htmlspecialchars($row['issuer']); ?></td>
                                <td class="text-end pe-3 fw-bold text-dark">K<?php echo number_format($row['total_amount'], 2); ?></td>
                                <td class="text-center no-print">
                                    <button class="btn btn-sm btn-outline-dark border-0" onclick="printReceipt(<?php echo $row['id']; ?>)" title="Print Receipt">
                                        <i class="mdi mdi-receipt fs-5"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="mdi mdi-file-hidden fs-1 d-block mb-2"></i>
                                No transaction records found for <b><?php echo $display_date; ?></b>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Hidden iframe used to cleanly trigger thermal print for selected receipt -->
<iframe id="receiptPrintFrame"></iframe>

<script>
function printReceipt(saleId) {
    const frame = document.getElementById('receiptPrintFrame');
    frame.src = 'print_receipt.php?id=' + saleId;
    frame.onload = function() {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };
}
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>
