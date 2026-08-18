<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$active_branch_id = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$active_branch_id) {
    header("Location: ../login.php");
    exit;
}

// 2. --- FETCH DYNAMIC REGISTERED NAMES ---
$display_pharmacy_name = "Pharmacy POS"; 
$display_branch_name = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if($row = $pharm_res->fetch_assoc()){ $display_pharmacy_name = $row['name']; }

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_query->bind_param("ii", $active_branch_id, $pharmacy_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if($row = $branch_res->fetch_assoc()){ $display_branch_name = $row['branch_name']; }

// 3. --- FILTERS ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-6 months'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$trend_type = isset($_GET['trend_type']) ? $_GET['trend_type'] : 'weekly';

switch ($trend_type) {
    case 'monthly':
        $group_by = "YEAR(sale_date), MONTH(sale_date)";
        $label_format = "DATE_FORMAT(sale_date,'%b %Y')";
        break;
    case 'yearly':
        $group_by = "YEAR(sale_date)";
        $label_format = "YEAR(sale_date)";
        break;
    case 'weekly':
    default:
        $group_by = "YEAR(sale_date), WEEK(sale_date, 1)";
        $label_format = "CONCAT('Wk ', WEEK(sale_date, 1), ' ', YEAR(sale_date))";
        break;
}

// 4. --- FETCH TREND DATA ---
$sales_labels = [];
$sales_totals = [];
$sales_counts = [];

$sales_sql = "SELECT $label_format AS sale_label, 
                     SUM(total_amount) as total_sales, 
                     COUNT(*) as transactions
              FROM sales
              WHERE pharmacy_id = ? AND branch_id = ?
              AND DATE(sale_date) BETWEEN ? AND ?
              GROUP BY $group_by
              ORDER BY MIN(sale_date) ASC";

$stmt = $conn->prepare($sales_sql);
$stmt->bind_param("iiss", $pharmacy_id, $active_branch_id, $start_date, $end_date);
$stmt->execute();
$sales_result = $stmt->get_result();

while($row = $sales_result->fetch_assoc()){
    $sales_labels[] = $row['sale_label'];
    $sales_totals[] = (float)$row['total_sales'];
    $sales_counts[] = (int)$row['transactions'];
}

// 5. --- SUMMARY METRICS ---
$summary_sql = "SELECT SUM(total_amount) as total_sales, COUNT(*) as total_transactions
                FROM sales
                WHERE pharmacy_id = ? AND branch_id = ?
                AND DATE(sale_date) BETWEEN ? AND ?";
$s_stmt = $conn->prepare($summary_sql);
$s_stmt->bind_param("iiss", $pharmacy_id, $active_branch_id, $start_date, $end_date);
$s_stmt->execute();
$summary_result = $s_stmt->get_result()->fetch_assoc();

$total_sales_val = $summary_result['total_sales'] ?? 0;
$total_transactions = $summary_result['total_transactions'] ?? 0;
?>

<style>
    body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
    .stat-card { border: none; border-radius: 4px; color: white; margin-bottom: 20px; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-3px); }
    .bg-matrix-blue { background: #22a7f0; box-shadow: 0 4px 10px rgba(34, 167, 240, 0.2); }
    .bg-matrix-dark { background: #1f262d; box-shadow: 0 4px 10px rgba(31, 38, 45, 0.2); }
    .stat-card h2 { font-size: 1.8rem; margin: 0; font-weight: 700; }
    .stat-card p { margin: 0; opacity: 0.85; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
    .filter-card { background: #fff; border-radius: 4px; border: 1px solid #e9ecef; }
    .chart-container { background: #fff; border-radius: 4px; padding: 25px; border: 1px solid #e9ecef; }
    .btn-matrix { background-color: #1f262d; color: #fff; border: none; }
</style>

<div class="container-fluid">
    <div class="row align-items-center mb-4 pt-3">
        <div class="col-md-6">
            <h4 class="fw-bold text-dark mb-0">Sales Trend Analysis</h4>
            <span class="text-muted small"><i class="fas fa-store"></i> <?php echo strtoupper($display_pharmacy_name); ?> - <b><?php echo $display_branch_name; ?></b></span>
        </div>
        <div class="col-md-6 text-end">
            <span class="badge bg-white text-dark border px-3 py-2">
                <i class="far fa-clock me-1"></i> Zambia: <?php echo date('H:i'); ?>
            </span>
        </div>
    </div>

    <div class="card filter-card mb-4 shadow-sm">
        <div class="card-body p-3">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Grouping</label>
                    <select name="trend_type" class="form-select form-select-sm">
                        <option value="weekly" <?= $trend_type=='weekly'?'selected':'' ?>>Weekly Trend</option>
                        <option value="monthly" <?= $trend_type=='monthly'?'selected':'' ?>>Monthly Trend</option>
                        <option value="yearly" <?= $trend_type=='yearly'?'selected':'' ?>>Yearly Trend</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-matrix btn-sm w-100 py-2 shadow-sm" type="submit">
                        <i class="fas fa-sync-alt me-1"></i> Update Analysis
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-2">
        <div class="col-md-6">
            <div class="card stat-card bg-matrix-blue p-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p>Total Revenue</p>
                        <h2>K<?= number_format($total_sales_val, 2) ?></h2>
                    </div>
                    <i class="fas fa-money-bill-wave fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card bg-matrix-dark p-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p>Transactions Volume</p>
                        <h2><?= number_format($total_transactions) ?></h2>
                    </div>
                    <i class="fas fa-shopping-cart fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="chart-container shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold text-dark mb-0">Performance Overview</h5>
                    <span class="text-muted small"><i class="fas fa-chart-line text-success"></i> Sales vs Volume</span>
                </div>
                <canvas id="salesChart" height="100"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($sales_labels) ?>,
        datasets: [
            {
                label: 'Revenue (ZMW)',
                data: <?= json_encode($sales_totals) ?>,
                borderColor: '#22a7f0',
                backgroundColor: 'rgba(34, 167, 240, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#22a7f0',
                borderWidth: 3
            },
            {
                label: 'Volume (Sales Count)',
                data: <?= json_encode($sales_counts) ?>,
                borderColor: '#f39c12',
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.4,
                borderDash: [5, 5],
                pointRadius: 3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 6, font: { family: 'Poppins' } } }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                ticks: { font: { family: 'Poppins', size: 11 } }
            },
            x: {
                grid: { display: false },
                ticks: { font: { family: 'Poppins', size: 11 } }
            }
        }
    }
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>