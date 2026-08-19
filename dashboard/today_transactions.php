<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";
require_once "../includes/header.php";

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

// Capture Date from GET request, default to Today
$filter_date = isset($_GET['filter_date']) && !empty($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$display_date = date('d M Y', strtotime($filter_date));

// 1. Fetch Branding
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Pharmanova LSK';

// 2. Fetch Sales Transactions for Filter Date
$sql = "SELECT s.*, 
               COALESCE(u.username, s.issued_by, 'System') as issuer,
               (SELECT GROUP_CONCAT(st.item_name SEPARATOR ', ') 
                FROM sales_items si 
                JOIN store_items st ON si.product_id = st.id 
                WHERE si.sale_id = s.id) as items_sold
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.pharmacy_id = ? 
        AND s.branch_id = ? 
        AND DATE(s.created_at) = ? 
        ORDER BY s.id DESC";

$stmt = mysqli_prepare($conn, $sql);

// Exactly 3 bound parameters matching 'iis'
mysqli_stmt_bind_param($stmt, "iis", $p_id, $b_id, $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_revenue = 0; 
$total_invoices = 0; 
$sales_data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_revenue += (float)($row['total_amount'] ?? $row['total'] ?? 0);
        $total_invoices++;
    }
}
?>

<div class="page-wrapper" style="padding: 20px;">
    <div class="row align-items-center mb-4">
        <div class="col-md-6">
            <h4 class="fw-bold text-dark mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h4>
            <span class="text-muted small">Report for: <b><?php echo $display_date; ?></b></span>
        </div>
        <div class="col-md-6 text-end no-print">
            <form method="GET" class="d-inline-flex align-items-center justify-content-end">
                <input type="date" name="filter_date" class="form-control form-control-sm me-2" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                <button type="button" class="btn btn-dark btn-sm px-3" onclick="window.print()">
                    <i class="mdi mdi-printer me-1"></i> Print
                </button>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="card stat-card" style="background-color: #22a7f0; color: white; border: none; border-radius: 4px;">
                <div class="card-body" style="padding: 18px 22px;">
                    <p style="margin:0; opacity:0.9; font-size:0.75rem; font-weight:600; text-transform:uppercase;">REVENUE (<?php echo strtoupper(date('d M Y', strtotime($filter_date))); ?>)</p>
                    <h2 style="font-size: 1.8rem; margin:0; font-weight:700; color:#fff;">K<?php echo number_format($total_revenue, 2); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="background-color: #ffb848; color: white; border: none; border-radius: 4px;">
                <div class="card-body" style="padding: 18px 22px;">
                    <p style="margin:0; opacity:0.9; font-size:0.75rem; font-weight:600; text-transform:uppercase;">TOTAL INVOICES</p>
                    <h2 style="font-size: 1.8rem; margin:0; font-weight:700; color:#fff;"><?php echo $total_invoices; ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="table-box shadow-sm mt-4" style="background: #fff; border-radius: 4px; border: 1px solid #e9ecef;">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #1f262d; color: #fff;">
                        <th class="ps-3" style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Invoice #</th>
                        <th style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Medicines Sold</th>
                        <th style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Time</th>
                        <th style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Handled By</th>
                        <th class="text-end pe-3" style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Total (ZMW)</th>
                        <th class="text-center no-print" style="background-color: #1f262d; color: #fff; padding: 15px 12px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sales_data)): ?>
                        <?php foreach ($sales_data as $row): ?>
                            <tr>
                                <td class="ps-3 fw-bold text-info">#<?php echo htmlspecialchars($row['invoice']); ?></td>
                                <td style="max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($row['items_sold'] ?: 'No items'); ?></td>
                                <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($row['issuer']); ?></td>
                                <td class="text-end pe-3 fw-bold text-dark">K<?php echo number_format($row['total_amount'] ?? $row['total'], 2); ?></td>
                                <td class="text-center no-print">
                                    <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="text-dark">
                                        <i class="mdi mdi-eye-outline text-primary fs-5"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No transactions recorded for this date.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php"; 
}
?>
