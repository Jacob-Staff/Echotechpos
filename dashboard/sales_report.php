<?php  
session_start();
ob_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php");
    exit;
}

// Default fallbacks
$display_pharmacy_name = "Echo Prime Ltd"; 
$display_branch_name = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if($row = $pharm_res->fetch_assoc()) $display_pharmacy_name = $row['name'];

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
$branch_query->bind_param("i", $branch_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if($row = $branch_res->fetch_assoc()) $display_branch_name = $row['branch_name'];
?>

<div class="container-fluid pt-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h3 class="fw-bold"><i class="fas fa-chart-line me-2 text-success"></i>Sales Report</h3>
                <p class="text-muted mb-0"><?= strtoupper($display_pharmacy_name) ?> | <?= $display_branch_name ?></p>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-success text-white p-3 shadow-sm border-0">
                <small>Total Sales Value</small>
                <h2 id="total-sales" class="fw-bold">K 0.00</h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-primary text-white p-3 shadow-sm border-0">
                <small>Units Sold</small>
                <h2 id="total-items" class="fw-bold">0</h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark p-3 shadow-sm border-0">
                <small>Transactions</small>
                <h2 id="total-invoices" class="fw-bold">0</h2>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <input type="text" id="search" class="form-control" placeholder="Search Invoice or Product...">
            </div>
            <div class="col-md-3">
                <input type="date" id="startDate" class="form-control" value="<?= date('Y-m-01') ?>">
            </div>
            <div class="col-md-3">
                <input type="date" id="endDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-dark fw-bold" id="filter-btn">UPDATE</button>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-8"><div class="card p-3 shadow-sm border-0"><canvas id="weeklyChart" height="150"></canvas></div></div>
        <div class="col-lg-4"><div class="card p-3 shadow-sm border-0"><canvas id="monthlyChart" height="250"></canvas></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive p-3">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Invoice</th><th>Product</th><th>Qty</th><th>Total (K)</th><th>Date</th>
                    </tr>
                </thead>
                <tbody id="sales-body"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let mChart, wChart;

function loadSales() {
    const btn = $('#filter-btn');
    btn.prop('disabled', true).text('Updating...');

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
            if(res.sales.length > 0) {
                res.sales.forEach((s, i) => {
                    rows += `<tr>
                        <td>${i+1}</td>
                        <td><span class="badge bg-light text-dark border">${s.invoice_no}</span></td>
                        <td>${s.item_name}</td>
                        <td>${s.quantity}</td>
                        <td class="fw-bold">${parseFloat(s.total_price).toFixed(2)}</td>
                        <td>${s.date}</td>
                    </tr>`;
                });
            } else {
                rows = '<tr><td colspan="6" class="text-center">No transactions found for this period.</td></tr>';
            }
            
            $('#sales-body').html(rows);
            $('#total-sales').text('K ' + parseFloat(res.total_sales).toLocaleString(undefined, {minimumFractionDigits: 2}));
            $('#total-items').text(res.total_items);
            $('#total-invoices').text(res.total_invoices);

            renderCharts(res.monthly_snapshot, res.daily_trend);
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            alert("Connection error. Check console (F12).");
        },
        complete: function() {
            btn.prop('disabled', false).text('UPDATE');
        }
    });
}

function renderCharts(monthly, daily) {
    if(mChart) mChart.destroy();
    if(wChart) wChart.destroy();

    mChart = new Chart(document.getElementById('monthlyChart'), {
        type: 'doughnut',
        data: { 
            labels: Object.keys(monthly), 
            datasets: [{ 
                data: Object.values(monthly), 
                backgroundColor: ['#198754','#20c997','#0dcaf0','#ffc107','#fd7e14'] 
            }] 
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    wChart = new Chart(document.getElementById('weeklyChart'), {
        type: 'line',
        data: { 
            labels: Object.keys(daily), 
            datasets: [{ 
                label: 'Revenue (K)', 
                data: Object.values(daily), 
                borderColor: '#198754', 
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                fill: true,
                tension: 0.3
            }] 
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

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>