<?php  
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Default fallbacks
$display_pharmacy_name = "PHARMANOVA"; 
$display_branch_name   = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_query->close();

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
$branch_query->bind_param("i", $branch_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_query->close();

require_once "../includes/head.php";
?>

<style>
.sales-report-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1.25rem;
    color: #212529;
}

.kpi-card {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    transition: transform 0.2s ease-in-out;
}

.kpi-card:hover {
    transform: translateY(-2px);
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.table-custom {
    color: #334155;
    margin-bottom: 0;
}

.table-custom thead th {
    background-color: #f1f5f9;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
}

.table-custom tbody td {
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    padding: 12px;
    font-size: 0.9rem;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper sales-report-wrapper">
        <div class="container-fluid p-0">

            <!-- Title & Subtitle -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">
                        <i class="fas fa-chart-line me-2 text-primary"></i>Sales & Analytics Report
                    </h3>
                    <span class="text-secondary small">
                        <b><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></b> | <?= htmlspecialchars($display_branch_name) ?>
                    </span>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-success text-white p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Total Sales Revenue</span>
                                <h2 id="total-sales" class="fw-bold mb-0 mt-1">K 0.00</h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-wallet fa-2x text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-primary text-white p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Units Sold</span>
                                <h2 id="total-items" class="fw-bold mb-0 mt-1">0</h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-box-open fa-2x text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-warning text-dark p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-dark-50 small fw-bold text-uppercase">Total Invoices</span>
                                <h2 id="total-invoices" class="fw-bold mb-0 mt-1">0</h2>
                            </div>
                            <div class="bg-dark bg-opacity-10 rounded-circle p-3">
                                <i class="fas fa-file-invoice-dollar fa-2x text-dark"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="card card-custom p-3 mb-4">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">Search Keywords</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="search" class="form-control border-start-0" placeholder="Search Invoice No or Product...">
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Start Date</label>
                        <input type="date" id="startDate" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">End Date</label>
                        <input type="date" id="endDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary fw-bold py-2" id="filter-btn">
                            <i class="fas fa-sync-alt me-1"></i> UPDATE
                        </button>
                    </div>
                </div>
            </div>

            <!-- Analytics Charts -->
            <div class="row g-4 mb-4">
                <div class="col-12 col-lg-8">
                    <div class="card card-custom p-3 h-100">
                        <h6 class="fw-bold text-dark mb-3"><i class="fas fa-chart-area me-2 text-primary"></i>Daily Revenue Trend</h6>
                        <div style="position: relative; height: 260px;">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card card-custom p-3 h-100">
                        <h6 class="fw-bold text-dark mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Category Breakdown</h6>
                        <div style="position: relative; height: 260px;" class="d-flex align-items-center justify-content-center">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Itemized Table -->
            <div class="card card-custom">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2 text-primary"></i>Itemized Sales History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice Ref</th>
                                    <th>Product Name</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-center">Date</th>
                                </tr>
                            </thead>
                            <tbody id="sales-body">
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="fas fa-spinner fa-spin me-2"></i> Loading sales data...
                                    </td>
                                </tr>
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
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let mChart = null;
let wChart = null;

function loadSales() {
    const btn = $('#filter-btn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> UPDATING...');

    $.ajax({
        url: 'actions/fetch_sales.php',
        method: 'POST',
        data: {
            search: $('#search').val(),
            startDate: $('#startDate').val(),
            endDate: $('#endDate').val()
        },
        dataType: 'json',
        success: function(res) {
            let rows = '';
            if (res.sales && res.sales.length > 0) {
                res.sales.forEach((s, i) => {
                    rows += `<tr>
                        <td class="fw-bold text-muted">${i + 1}</td>
                        <td><span class="badge bg-light text-dark border fw-semibold">${s.invoice_no}</span></td>
                        <td class="fw-bold text-dark">${s.item_name}</td>
                        <td class="text-center"><span class="badge bg-secondary">${s.quantity}</span></td>
                        <td class="text-end fw-bold text-success">K${parseFloat(s.total_price).toFixed(2)}</td>
                        <td class="text-center text-muted small">${s.date}</td>
                    </tr>`;
                });
            } else {
                rows = '<tr><td colspan="6" class="text-center py-4 text-muted">No sales transactions found for the selected range.</td></tr>';
            }
            
            $('#sales-body').html(rows);
            $('#total-sales').text('K ' + parseFloat(res.total_sales || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            $('#total-items').text(res.total_items || 0);
            $('#total-invoices').text(res.total_invoices || 0);

            renderCharts(res.monthly_snapshot || {}, res.daily_trend || {});
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            $('#sales-body').html('<tr><td colspan="6" class="text-center text-danger py-4">Failed to load sales data. Check server logs.</td></tr>');
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i> UPDATE');
        }
    });
}

function renderCharts(monthly, daily) {
    if (mChart) mChart.destroy();
    if (wChart) wChart.destroy();

    const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
    mChart = new Chart(ctxMonthly, {
        type: 'doughnut',
        data: { 
            labels: Object.keys(monthly), 
            datasets: [{ 
                data: Object.values(monthly), 
                backgroundColor: ['#0d6efd','#198754','#ffc107','#0dcaf0','#fd7e14','#6c757d'] 
            }] 
        },
        options: { 
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'bottom' } 
            } 
        }
    });

    const ctxWeekly = document.getElementById('weeklyChart').getContext('2d');
    wChart = new Chart(ctxWeekly, {
        type: 'line',
        data: { 
            labels: Object.keys(daily), 
            datasets: [{ 
                label: 'Revenue (K)', 
                data: Object.values(daily), 
                borderColor: '#0d6efd', 
                backgroundColor: 'rgba(13, 110, 253, 0.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 4,
                pointBackgroundColor: '#0d6efd'
            }] 
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}

$(document).ready(function() {
    loadSales();
    
    $('#filter-btn').on('click', function(e) {
        e.preventDefault();
        loadSales();
    });
});
</script>
</body>
</html>
