<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";
date_default_timezone_set('Africa/Lusaka');

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

// NEW: Capture Date from GET request, default to Today
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

// 2. UPDATED QUERY: Filter by $filter_date instead of hardcoded today (Using prepared statement with exact 'iis' match)
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

if($result){
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_revenue = $row['day_total'] ?? 0;
        $total_invoices = $row['day_count'] ?? 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Report | <?php echo $display_pharm; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="dist/css/style.min.css" rel="stylesheet">

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
        .page-wrapper { padding-top: 15px; }
        .stat-card { border: none; border-radius: 4px; color: white; margin-bottom: 20px; }
        .bg-matrix-cyan { background: #22a7f0; box-shadow: 0 4px 10px rgba(34, 167, 240, 0.2); }
        .bg-matrix-orange { background: #ffb848; box-shadow: 0 4px 10px rgba(255, 184, 72, 0.2); }
        .stat-card .card-body { padding: 18px 22px; }
        .stat-card h2 { font-size: 1.8rem; margin: 0; font-weight: 700; }
        .stat-card p { margin: 0; opacity: 0.85; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        .table-box { background: #fff; border-radius: 4px; border: 1px solid #e9ecef; }
        .table thead th { background-color: #1f262d; color: #fff; font-size: 12px; padding: 15px 12px; }
        .table tbody td { font-size: 13.5px; padding: 12px; vertical-align: middle; border-bottom: 1px solid #f8f9fa; }
        
        .item-list { color: #444; max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Filter styling */
        .filter-form { background: white; padding: 10px 15px; border-radius: 4px; border: 1px solid #ddd; }
        
        @media print {
            .no-print { display: none !important; }
            .page-wrapper { padding: 0; }
        }
    </style>
            
            <div class="row align-items-center mb-4">
                <div class="col-md-6">
                    <h4 class="fw-bold text-dark mb-0"><?php echo strtoupper($display_pharm); ?></h4>
                    <span class="text-muted small">Report for: <b><?php echo $display_date; ?></b></span>
                </div>
                <div class="col-md-6 text-end no-print">
                    <form method="GET" class="d-inline-flex align-items-center">
                        <input type="date" name="filter_date" class="form-control form-control-sm me-2" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                        <button type="button" class="btn btn-dark btn-sm px-3" onclick="window.print()">
                            <i class="mdi mdi-printer me-1"></i> Print
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="card stat-card bg-matrix-cyan">
                        <div class="card-body">
                            <p>Revenue (<?php echo $display_date; ?>)</p>
                            <h2>K<?php echo number_format($total_revenue, 2); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card bg-matrix-orange">
                        <div class="card-body">
                            <p>Total Invoices</p>
                            <h2><?php echo $total_invoices; ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-box shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
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
                                        <td class="ps-3 fw-bold text-info">#<?php echo $row['invoice']; ?></td>
                                        <td class="item-list"><?php echo $row['items_sold'] ?: 'No items'; ?></td>
                                        <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                        <td><?php echo $row['issuer'] ?? 'System'; ?></td>
                                        <td class="text-end pe-3 fw-bold text-dark">K<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td class="text-center no-print">
                                            <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="text-dark">
                                                <i class="mdi mdi-eye-outline text-primary"></i>
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
    </div>
</div>
<?php require "../includes/footer.php"; ?>
</div>

</script>
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>
