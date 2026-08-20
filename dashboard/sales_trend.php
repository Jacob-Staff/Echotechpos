<?php  
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Dynamic Pharmacy & Branch Name Retrieval
$display_pharmacy_name = "Echo Prime Ltd"; 
$display_branch_name   = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_query->close();

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_query->bind_param("ii", $branch_id, $pharmacy_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_query->close();

// Fetch Store Categories for Dropdown Filter
$cat_options = [];
$cat_stmt = $conn->prepare("SELECT DISTINCT category FROM store_items WHERE pharmacy_id = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$cat_stmt->bind_param("i", $pharmacy_id);
$cat_stmt->execute();
$cat_res = $cat_stmt->get_result();
while ($c_row = $cat_res->fetch_assoc()) {
    $cat_options[] = $c_row['category'];
}
$cat_stmt->close();

// Fetch Payment Methods for Dropdown Filter
$pay_options = [];
$pay_stmt = $conn->prepare("SELECT DISTINCT payment_method FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND payment_method IS NOT NULL AND payment_method != '' ORDER BY payment_method ASC");
$pay_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$pay_stmt->execute();
$pay_res = $pay_stmt->get_result();
while ($p_row = $pay_res->fetch_assoc()) {
    $pay_options[] = $p_row['payment_method'];
}
$pay_stmt->close();

require_once "../includes/head.php";
?>

<style>
.sales-trend-wrapper {
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

/* Print Specific Stylesheet */
@media print {
    body {
        background-color: #ffffff !important;
        color: #000000 !important;
    }

    #header, #aside, .no-print, nav, .btn, .input-group, form, footer {
        display: none !important;
    }

    .sales-trend-wrapper {
        padding: 0 !important;
        background-color: #ffffff !important;
    }

    .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
    }

    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
    }

    .chart-container {
        page-break-inside: avoid;
    }
}

.print-header {
    display: none;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper sales-trend-wrapper">
        <div class="container-fluid p-0">

            <!-- Print Banner Header -->
            <div class="print-header">
                <h2 class="fw-bold mb-1"><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></h2>
                <h5 class="mb-1"><?= htmlspecialchars($display_branch_name) ?> - Advanced Sales Trend Report</h5>
                <small class="text-muted">Generated on: <?= date('d M Y, H:i A') ?></small>
            </div>

            <!-- Header & Print Trigger -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">
                        <i class="fas fa-chart-line me-2 text-primary"></i>Sales Trend & Analytics
                    </h3>
                    <span class="text-secondary small">
                        <b><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></b> | <?= htmlspecialchars($display_branch_name) ?>
                    </span>
                </div>
                <div class="no-print">
                    <button class="btn btn-outline-dark fw-bold" onclick="window.print();">
                        <i class="fas fa-print me-1"></i> Print / Export PDF
                    </button>
                </div>
            </div>

            <!-- Summary KPI Dashboard Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-primary text-white p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Total Revenue</span>
                                <h2 id="total-revenue" class="fw-bold mb-0 mt-1">K 0.00</h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-wallet fa-2x text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-dark text-white p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Transactions Volume</span>
                                <h2 id="total-transactions" class="fw-bold mb-0 mt-1">0</h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-shopping-cart fa-2x text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card kpi-card bg-success text-white p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Average Ticket Value</span>
                                <h2 id="avg-ticket" class="fw-bold mb-0 mt-1">K 0.00</h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-receipt fa-2x text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Multi-Level Filter Panel -->
            <div class="card card-custom p-3 mb-4 no-print">
                <div class="row g-3">
                    <!-- Preset Date Range Buttons -->
                    <div class="col-12">
                        <div class="btn-group btn-group-sm flex-wrap" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('today')">Today</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('yesterday')">Yesterday</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('this_week')">This Week</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('this_month')">This Month</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('last_month')">Last Month</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('6_months')">Last 6 Months</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDatePreset('this_year')">This Year</button>
                        </div>
                    </div>

                    <!-- Row 1 Filters -->
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Search Keyword</label>
                        <input type="text" id="search" class="form-control" placeholder="Invoice #, Product, Cashier...">
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Category</label>
                        <select id="category" class="form-select">
                            <option value="">-- All Categories --</option>
                            <?php foreach ($cat_options as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Payment Method</label>
                        <select id="payment_method" class="form-select">
                            <option value="">-- All Payment Methods --</option>
                            <?php foreach ($pay_options as $pm): ?>
                                <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold text-muted mb-1">Trend Grouping</label>
                        <select id="trendType" class="form-select">
                            <option value="daily">Daily Grouping</option>
                            <option value="weekly" selected>Weekly Grouping</option>
                            <option value="monthly">Monthly Grouping</option>
                            <option value="yearly">Yearly Grouping</option>
                        </select>
                    </div>

                    <!-- Row 2 Filters & Action -->
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">Start Date</label>
                        <input type="date" id="startDate" class="form-control" value="<?= date('Y-m-d', strtotime('-6 months')) ?>">
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">End Date</label>
                        <input type="date" id="endDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-12 col-md-4 d-grid align-self-end">
                        <button class="btn btn-primary fw-bold py-2" id="filter-btn">
                            <i class="fas fa-sync-alt me-1"></i> APPLY FILTERS
                        </button>
                    </div>
                </div>
            </div>

            <!-- Interactive Trend Canvas -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-custom p-4 shadow-sm chart-container">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold text-dark mb-0">Sales Performance Curve</h5>
                            <span class="text-muted small"><i class="fas fa-chart-line text-success me-1"></i> Revenue vs Transaction Volume</span>
                        </div>
                        <div style="position: relative; height: 380px;">
                            <canvas id="salesTrendChart"></canvas>
                        </div>
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
let trendChart = null;

function setDatePreset(type) {
    const today = new Date();
    let start = new Date();
    let end = new Date();

    if (type === 'today') {
        start = today;
        end = today;
        $('#trendType').val('daily');
    } else if (type === 'yesterday') {
        start.setDate(today.getDate() - 1);
        end.setDate(today.getDate() - 1);
        $('#trendType').val('daily');
    } else if (type === 'this_week') {
        const day = today.getDay();
        const diff = today.getDate() - day + (day === 0 ? -6 : 1);
        start = new Date(today.setDate(diff));
        end = new Date();
        $('#trendType').val('daily');
    } else if (type === 'this_month') {
        start = new Date(today.getFullYear(), today.getMonth(), 1);
        end = new Date();
        $('#trendType').val('weekly');
    } else if (type === 'last_month') {
        start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        end = new Date(today.getFullYear(), today.getMonth(), 0);
        $('#trendType').val('weekly');
    } else if (type === '6_months') {
        start.setMonth(today.getMonth() - 6);
        end = new Date();
        $('#trendType').val('monthly');
    } else if (type === 'this_year') {
        start = new Date(today.getFullYear(), 0, 1);
        end = new Date();
        $('#trendType').val('monthly');
    }

    const formatDate = (d) => d.toISOString().split('T')[0];
    $('#startDate').val(formatDate(start));
    $('#endDate').val(formatDate(end));
    loadTrendData();
}

function loadTrendData() {
    const btn = $('#filter-btn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> UPDATING...');

    $.ajax({
        url: 'actions/fetch_sales_trend.php',
        method: 'POST',
        data: {
            search: $('#search').val(),
            category: $('#category').val(),
            payment_method: $('#payment_method').val(),
            startDate: $('#startDate').val(),
            endDate: $('#endDate').val(),
            trendType: $('#trendType').val()
        },
        dataType: 'json',
        success: function(res) {
            const rev = parseFloat(res.total_revenue || 0);
            const tx = parseInt(res.total_transactions || 0);
            const avg = tx > 0 ? (rev / tx) : 0;

            $('#total-revenue').text('K ' + rev.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            $('#total-transactions').text(tx.toLocaleString());
            $('#avg-ticket').text('K ' + avg.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));

            renderTrendChart(res.labels || [], res.totals || [], res.counts || []);
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            alert("Failed to fetch trend analysis data. Check server response.");
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-1"></i> APPLY FILTERS');
        }
    });
}

function renderTrendChart(labels, totals, counts) {
    if (trendChart) {
        trendChart.destroy();
    }

    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Revenue (ZMW)',
                    data: totals,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#0d6efd',
                    borderWidth: 3,
                    yAxisID: 'y'
                },
                {
                    label: 'Transactions Count',
                    data: counts,
                    borderColor: '#fd7e14',
                    backgroundColor: 'transparent',
                    fill: false,
                    tension: 0.35,
                    borderDash: [5, 5],
                    pointRadius: 4,
                    pointBackgroundColor: '#fd7e14',
                    borderWidth: 2,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    position: 'top', 
                    labels: { usePointStyle: true, boxWidth: 6 } 
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    title: { display: true, text: 'Revenue (ZMW)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Transaction Count' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}

$(document).ready(function() {
    loadTrendData();
    
    $('#filter-btn').on('click', function(e) {
        e.preventDefault();
        loadTrendData();
    });
});
</script>
</body>
</html>
