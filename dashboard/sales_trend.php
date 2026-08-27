<?php
declare(strict_types=1);

ini_set('display_errors', '0');
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
    exit;
}

$display_pharmacy_name = "PHARMANOVA";
$display_branch_name   = "Main Branch";

$stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $pharmacy_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $display_pharmacy_name = $row['name'];
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ii", $branch_id, $pharmacy_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $display_branch_name = $row['branch_name'];
    }
    $stmt->close();
}

$categories = [];
$stmt = $conn->prepare("
    SELECT DISTINCT category
    FROM store_items
    WHERE pharmacy_id = ?
      AND category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
");
if ($stmt) {
    $stmt->bind_param("i", $pharmacy_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $stmt->close();
}

$paymentMethods = [];
$stmt = $conn->prepare("
    SELECT DISTINCT payment_method
    FROM sales
    WHERE pharmacy_id = ?
      AND branch_id = ?
      AND payment_method IS NOT NULL
      AND payment_method <> ''
    ORDER BY payment_method ASC
");
if ($stmt) {
    $stmt->bind_param("ii", $pharmacy_id, $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $paymentMethods[] = $row['payment_method'];
    }
    $stmt->close();
}

$today = date('Y-m-d');
$sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));

require_once "../includes/head.php";
?>

<meta charset="UTF-8">

<style>
:root {
    --dash-blue: #1677ff;
    --dash-blue-dark: #33475b;
    --dash-orange: #ff9800;
    --dash-green: #198754;
    --dash-red: #d90429;
    --page-bg: #f1f4f8;
    --card-border: #e1e7ef;
}

.sales-trend-page {
    min-height: calc(100vh - 70px);
    background: var(--page-bg);
    padding: 18px;
}

.sales-trend-shell {
    max-width: 1500px;
    margin: 0 auto;
}

.trend-titlebar {
    background: #fff;
    border: 1px solid var(--card-border);
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: 0 3px 12px rgba(25, 45, 70, .05);
}

.trend-title-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #eaf3ff;
    color: var(--dash-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.trend-card {
    background: #fff;
    border: 1px solid var(--card-border);
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(25, 45, 70, .05);
}

.kpi {
    position: relative;
    overflow: hidden;
    min-height: 125px;
    color: #fff;
    border: 0;
}

.kpi::after {
    content: "";
    position: absolute;
    width: 110px;
    height: 110px;
    border-radius: 50%;
    right: -28px;
    bottom: -45px;
    background: rgba(255,255,255,.10);
}

.kpi-blue { background: #4299cf; }
.kpi-dark { background: #3e4f60; }
.kpi-orange { background: linear-gradient(135deg, #ff9800, #ef6c00); }
.kpi-green { background: #198754; }

.kpi-label {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
    opacity: .88;
}

.kpi-value {
    font-size: 27px;
    font-weight: 800;
    margin-top: 5px;
}

.kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,.20);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 2;
}

.filter-card {
    padding: 16px;
}

.filter-label {
    font-size: 11px;
    font-weight: 800;
    color: #536274;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 6px;
}

.form-control,
.form-select {
    border-color: #d7e0ea;
    min-height: 40px;
    border-radius: 7px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--dash-blue);
    box-shadow: 0 0 0 .18rem rgba(22,119,255,.10);
}

.preset-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.preset-buttons .btn {
    border-radius: 6px !important;
    font-size: 12px;
    font-weight: 700;
}

.chart-wrap {
    height: 430px;
    position: relative;
}

.chart-header {
    padding-bottom: 12px;
    border-bottom: 1px solid #edf1f5;
}

.chart-legend-note {
    font-size: 12px;
    color: #748092;
}

.insight-box {
    background: #f8fafc;
    border: 1px solid #e5ebf2;
    border-radius: 9px;
    padding: 13px 15px;
}

.insight-value {
    font-weight: 800;
    color: #203047;
}

.status-message {
    display: none;
    padding: 10px 12px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
}

.status-message.error {
    display: block;
    background: #fff0f1;
    color: #b4232d;
    border: 1px solid #ffd2d6;
}

.status-message.loading {
    display: block;
    background: #eef6ff;
    color: #145fc0;
    border: 1px solid #cfe4ff;
}

@media (max-width: 768px) {
    .sales-trend-page {
        padding: 10px;
    }

    .chart-wrap {
        height: 340px;
    }

    .kpi-value {
        font-size: 23px;
    }
}

@media print {
    #header,
    #aside,
    nav,
    footer,
    .no-print {
        display: none !important;
    }

    .sales-trend-page {
        padding: 0 !important;
        background: #fff !important;
    }

    .trend-card,
    .trend-titlebar {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .chart-wrap {
        height: 430px !important;
    }
}
</style>

<div id="main-wrapper">
    <?php
    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }
    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }
    ?>

    <main class="page-wrapper sales-trend-page">
        <div class="sales-trend-shell">

            <div class="trend-titlebar mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="trend-title-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3 class="mb-1 fw-bold">Sales Trend</h3>
                        <div class="small text-muted">
                            <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong>
                            <span class="mx-1">•</span>
                            <?= htmlspecialchars($display_branch_name) ?>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-outline-dark fw-bold no-print" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print / Export PDF
                </button>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card kpi kpi-blue p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Total Revenue</div>
                                <div class="kpi-value" id="total-revenue">K 0.00</div>
                                <small>Selected period</small>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card kpi kpi-dark p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Transactions</div>
                                <div class="kpi-value" id="total-transactions">0</div>
                                <small>Completed sales</small>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card kpi kpi-orange p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Average Ticket</div>
                                <div class="kpi-value" id="avg-ticket">K 0.00</div>
                                <small>Revenue per transaction</small>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-calculator"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card kpi kpi-green p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Best Period</div>
                                <div class="kpi-value" id="best-period">-</div>
                                <small id="best-period-value">No sales yet</small>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-trophy"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card p-3">
                        <div class="small text-muted fw-bold text-uppercase">Online Orders</div>
                        <div class="fs-4 fw-bold text-info" id="online-revenue">K 0.00</div>
                        <div class="small text-muted"><span id="online-transactions">0</span> transactions</div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="trend-card p-3">
                        <div class="small text-muted fw-bold text-uppercase">POS Sales</div>
                        <div class="fs-4 fw-bold text-secondary" id="pos-revenue">K 0.00</div>
                        <div class="small text-muted"><span id="pos-transactions">0</span> transactions</div>
                    </div>
                </div>
            </div>

            <div class="trend-card filter-card mb-3 no-print">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="fas fa-sliders-h text-primary me-2"></i>Trend Filters</h5>
                        <small class="text-muted">Analyze this branch without leaving the page.</small>
                    </div>
                    <span class="badge bg-light text-primary border">ZMW</span>
                </div>

                <div class="preset-buttons mb-3">
                    <button type="button" class="btn btn-outline-secondary" data-preset="today">Today</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="this_week">This Week</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="this_month">This Month</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="last_month">Last Month</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="6_months">Last 6 Months</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="this_year">This Year</button>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-3">
                        <label class="filter-label">Search</label>
                        <input type="text" id="search" class="form-control" placeholder="Invoice, product or barcode...">
                    </div>

                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="filter-label">Category</label>
                        <select id="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="filter-label">Payment</label>
                        <select id="payment_method" class="form-select">
                            <option value="">All Methods</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="filter-label">Grouping</label>
                        <select id="trendType" class="form-select">
                            <option value="daily">Daily</option>
                            <option value="weekly" selected>Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="filter-label">Date Range</label>
                        <div class="d-flex gap-2">
                            <input type="date" id="startDate" class="form-control" value="<?= $sixMonthsAgo ?>">
                            <input type="date" id="endDate" class="form-control" value="<?= $today ?>">
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="button" id="filter-btn" class="btn btn-primary fw-bold px-4">
                            <i class="fas fa-sync-alt me-1"></i> APPLY FILTERS
                        </button>
                        <button type="button" id="reset-btn" class="btn btn-light border fw-bold ms-2">
                            Reset
                        </button>
                    </div>

                    <div class="col-12">
                        <div id="status-message" class="status-message"></div>
                    </div>
                </div>
            </div>

            <div class="trend-card p-3 p-lg-4">
                <div class="chart-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Sales Performance</h5>
                        <div class="chart-legend-note">
                            Revenue trend split between Online Orders and POS Sales.
                        </div>
                    </div>
                    <div class="small text-muted">
                        <i class="fas fa-circle text-primary me-1"></i> Revenue
                        <span class="mx-2">|</span>
                        <i class="fas fa-circle text-warning me-1"></i> Transactions
                    </div>
                </div>

                <div class="chart-wrap">
                    <canvas id="salesTrendChart"></canvas>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12 col-md-4">
                        <div class="insight-box">
                            <div class="small text-muted">Highest Revenue Period</div>
                            <div class="insight-value" id="insight-highest">-</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="insight-box">
                            <div class="small text-muted">Transactions in Best Period</div>
                            <div class="insight-value" id="insight-count">0</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="insight-box">
                            <div class="small text-muted">Selected Date Range</div>
                            <div class="insight-value" id="insight-range">-</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <?php
    if (file_exists("../includes/footer.php")) {
        require_once "../includes/footer.php";
    }
    ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
(() => {
    'use strict';

    let trendChart = null;

    const $ = (id) => document.getElementById(id);

    function localDateString(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function parseLocalDate(value) {
        const [y, m, d] = value.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    function formatMoney(value) {
        return 'K ' + Number(value || 0).toLocaleString('en-ZM', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function setStatus(message = '', type = '') {
        const box = $('status-message');
        box.className = 'status-message';

        if (message) {
            box.textContent = message;
            box.classList.add(type);
        }
    }

    function setPreset(type) {
        const today = new Date();
        let start = new Date(today);
        let end = new Date(today);

        switch (type) {
            case 'today':
                $('trendType').value = 'daily';
                break;

            case 'yesterday':
                start.setDate(start.getDate() - 1);
                end = new Date(start);
                $('trendType').value = 'daily';
                break;

            case 'this_week': {
                const day = today.getDay();
                const mondayOffset = day === 0 ? -6 : 1 - day;
                start.setDate(today.getDate() + mondayOffset);
                $('trendType').value = 'daily';
                break;
            }

            case 'this_month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                $('trendType').value = 'weekly';
                break;

            case 'last_month':
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end = new Date(today.getFullYear(), today.getMonth(), 0);
                $('trendType').value = 'weekly';
                break;

            case '6_months':
                start = new Date(today.getFullYear(), today.getMonth() - 6, today.getDate());
                $('trendType').value = 'monthly';
                break;

            case 'this_year':
                start = new Date(today.getFullYear(), 0, 1);
                $('trendType').value = 'monthly';
                break;
        }

        $('startDate').value = localDateString(start);
        $('endDate').value = localDateString(end);

        loadTrendData();
    }

    async function loadTrendData() {
        const btn = $('filter-btn');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> UPDATING...';
        setStatus('Loading sales trend...', 'loading');

        const form = new FormData();
        form.append('search', $('search').value.trim());
        form.append('category', $('category').value);
        form.append('payment_method', $('payment_method').value);
        form.append('startDate', $('startDate').value);
        form.append('endDate', $('endDate').value);
        form.append('trendType', $('trendType').value);

        try {
            const response = await fetch('actions/fetch_sales_trend.php', {
                method: 'POST',
                body: form,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON:', text);
                throw new Error('The server returned an invalid response.');
            }

            if (!response.ok || data.status === 'error') {
                throw new Error(data.message || 'Unable to load sales trend data.');
            }

            updateDashboard(data);
            setStatus('');
        } catch (error) {
            console.error(error);
            setStatus(error.message || 'Failed to load sales trend data.', 'error');

            $('total-revenue').textContent = 'K 0.00';
            $('total-transactions').textContent = '0';
            $('avg-ticket').textContent = 'K 0.00';
            $('online-revenue').textContent = 'K 0.00';
            $('pos-revenue').textContent = 'K 0.00';
            $('online-transactions').textContent = '0';
            $('pos-transactions').textContent = '0';
            $('best-period').textContent = '-';
            $('best-period-value').textContent = 'No sales yet';
            renderChart([], [], [], []);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i> APPLY FILTERS';
        }
    }

    function updateDashboard(data) {
        const revenue = Number(data.total_revenue || 0);
        const transactions = Number(data.total_transactions || 0);
        const average = transactions > 0 ? revenue / transactions : 0;

        const labels = Array.isArray(data.labels) ? data.labels : [];
        const totals = Array.isArray(data.totals) ? data.totals.map(Number) : [];
        const counts = Array.isArray(data.counts) ? data.counts.map(Number) : [];
        const onlineTotals = Array.isArray(data.online_totals) ? data.online_totals.map(Number) : [];
        const posTotals = Array.isArray(data.pos_totals) ? data.pos_totals.map(Number) : [];

        $('total-revenue').textContent = formatMoney(revenue);
        $('total-transactions').textContent = transactions.toLocaleString('en-ZM');
        $('avg-ticket').textContent = formatMoney(average);
        $('online-revenue').textContent = formatMoney(data.total_online_revenue || 0);
        $('pos-revenue').textContent = formatMoney(data.total_pos_revenue || 0);
        $('online-transactions').textContent = Number(data.total_online_transactions || 0).toLocaleString('en-ZM');
        $('pos-transactions').textContent = Number(data.total_pos_transactions || 0).toLocaleString('en-ZM');

        let bestIndex = -1;
        let bestValue = 0;

        totals.forEach((value, index) => {
            if (value > bestValue) {
                bestValue = value;
                bestIndex = index;
            }
        });

        if (bestIndex >= 0) {
            $('best-period').textContent = labels[bestIndex] || '-';
            $('best-period-value').textContent = formatMoney(bestValue);
            $('insight-highest').textContent = `${labels[bestIndex]} - ${formatMoney(bestValue)}`;
            $('insight-count').textContent = (counts[bestIndex] || 0).toLocaleString('en-ZM');
        } else {
            $('best-period').textContent = '-';
            $('best-period-value').textContent = 'No sales yet';
            $('insight-highest').textContent = 'No sales recorded';
            $('insight-count').textContent = '0';
        }

        $('insight-range').textContent =
            `${$('startDate').value} to ${$('endDate').value}`;

        renderChart(labels, totals, onlineTotals, posTotals);
    }

    function renderChart(labels, totals, onlineTotals, posTotals) {
        const canvas = $('salesTrendChart');

        if (trendChart) {
            trendChart.destroy();
        }

        trendChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Total Revenue (ZMW)',
                        data: totals,
                        borderColor: '#1677ff',
                        backgroundColor: 'rgba(22,119,255,.08)',
                        borderWidth: 3,
                        fill: true,
                        tension: .35,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#1677ff'
                    },
                    {
                        label: 'Online Orders (ZMW)',
                        data: onlineTotals,
                        borderColor: '#0dcaf0',
                        backgroundColor: 'transparent',
                        borderWidth: 3,
                        tension: .35,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#0dcaf0'
                    },
                    {
                        label: 'POS Sales (ZMW)',
                        data: posTotals,
                        borderColor: '#6c757d',
                        backgroundColor: 'transparent',
                        borderWidth: 3,
                        borderDash: [7, 5],
                        tension: .35,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#6c757d'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 18
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                return ` ${context.dataset.label}: ${formatMoney(context.parsed.y)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Revenue (ZMW)'
                        },
                        grid: {
                            color: '#edf1f5'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
$('filter-btn').addEventListener('click', loadTrendData);

    $('reset-btn').addEventListener('click', () => {
        $('search').value = '';
        $('category').value = '';
        $('payment_method').value = '';
        $('trendType').value = 'weekly';
        $('startDate').value = '<?= $sixMonthsAgo ?>';
        $('endDate').value = '<?= $today ?>';
        loadTrendData();
    });

    document.querySelectorAll('[data-preset]').forEach(button => {
        button.addEventListener('click', () => setPreset(button.dataset.preset));
    });

    $('search').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadTrendData();
        }
    });

    loadTrendData();
})();
</script>

</body>
</html>
