<?php
declare(strict_types=1);

/**
 * EchoTech POS — Branch Operations Dashboard
 * Branch-scoped detail / performance page opened from Branch Management.
 */

session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$adminDb = $conn;
$adminDb->set_charset('utf8mb4');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

$user_role = function_exists('current_role') ? current_role() : (string)($_SESSION['role'] ?? 'Admin');
$user_display_name = function_exists('current_user') ? current_user() : 'Administrator';

function vb_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vb_money(mixed $value): string
{
    return 'K' . number_format((float)$value, 2);
}

function vb_rows(mysqli $db, string $sql, string $types = '', array $values = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => &$value) {
            $refs[$key] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function vb_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array
{
    $rows = vb_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}

function vb_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

$branch_id = (int)($_GET['id'] ?? 0);
if ($branch_id <= 0) {
    header('Location: branches.php');
    exit;
}

$branch = vb_one(
    $adminDb,
    'SELECT b.*, p.name AS pharmacy_name
     FROM branches b
     INNER JOIN pharmacies p ON p.id=b.pharmacy_id
     WHERE b.id=? AND b.pharmacy_id=?
     LIMIT 1',
    'ii',
    [$branch_id, $pharmacy_id]
);

if (!$branch) {
    http_response_code(404);
    exit('Branch not found or it does not belong to this pharmacy.');
}

$pharmacy_name = (string)($branch['pharmacy_name'] ?? 'PHARMACY POS');

/* ---------- Date range ---------- */
$start_date = (string)($_GET['start_date'] ?? date('Y-m-01'));
$end_date = (string)($_GET['end_date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = date('Y-m-d');
}
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_dt = $start_date . ' 00:00:00';
$end_dt = $end_date . ' 23:59:59';

/* ---------- Core branch KPIs ---------- */
$revenueRow = vb_one(
    $adminDb,
    'SELECT
        COALESCE(SUM(COALESCE(total_amount,total,0)),0) AS revenue,
        COUNT(*) AS sales_count,
        COALESCE(AVG(COALESCE(total_amount,total,0)),0) AS average_ticket
     FROM sales
     WHERE pharmacy_id=? AND branch_id=? AND sale_date BETWEEN ? AND ?',
    'iiss',
    [$pharmacy_id, $branch_id, $start_dt, $end_dt]
) ?? [];

$revenue = (float)($revenueRow['revenue'] ?? 0);
$sales_count = (int)($revenueRow['sales_count'] ?? 0);
$average_ticket = (float)($revenueRow['average_ticket'] ?? 0);

$expenseRow = vb_one(
    $adminDb,
    'SELECT COALESCE(SUM(amount),0) AS amount, COUNT(*) AS count
     FROM expenses
     WHERE pharmacy_id=? AND branch_id=? AND expense_date BETWEEN ? AND ?',
    'iiss',
    [$pharmacy_id, $branch_id, $start_date, $end_date]
) ?? [];

$expenses = (float)($expenseRow['amount'] ?? 0);
$expense_count = (int)($expenseRow['count'] ?? 0);
$net = $revenue - $expenses;

$inventory = vb_one(
    $adminDb,
    'SELECT
        COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN is_active=1 THEN quantity ELSE 0 END),0) AS units,
        COALESCE(SUM(CASE WHEN is_active=1 THEN quantity*price ELSE 0 END),0) AS valuation,
        COALESCE(SUM(CASE WHEN is_active=1 AND quantity < 10 THEN 1 ELSE 0 END),0) AS low_stock,
        COALESCE(SUM(CASE WHEN is_active=1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 1 ELSE 0 END),0) AS expired
     FROM store_items
     WHERE pharmacy_id=? AND branch_id=?',
    'ii',
    [$pharmacy_id, $branch_id]
) ?? [];

$item_count = (int)($inventory['item_count'] ?? 0);
$units = (int)($inventory['units'] ?? 0);
$valuation = (float)($inventory['valuation'] ?? 0);
$low_stock = (int)($inventory['low_stock'] ?? 0);
$expired = (int)($inventory['expired'] ?? 0);

$staff_count = (int)(vb_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM users WHERE pharmacy_id=? AND branch_id=?',
    'ii',
    [$pharmacy_id, $branch_id]
)['c'] ?? 0);

$online_count = (int)(vb_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM clients_orders WHERE pharmacy_id=? AND branch_id=?',
    'ii',
    [$pharmacy_id, $branch_id]
)['c'] ?? 0);

$online_pending = (int)(vb_one(
    $adminDb,
    "SELECT COUNT(*) AS c FROM clients_orders
     WHERE pharmacy_id=? AND branch_id=? AND status IN ('Pending','Processing')",
    'ii',
    [$pharmacy_id, $branch_id]
)['c'] ?? 0);

$pending_prescriptions = (int)(vb_one(
    $adminDb,
    "SELECT COUNT(*) AS c FROM prescriptions
     WHERE pharmacy_id=? AND branch_id=? AND status <> 'Ready'",
    'ii',
    [$pharmacy_id, $branch_id]
)['c'] ?? 0);

/* ---------- Payment mix for selected period ---------- */
$payment_mix = vb_rows(
    $adminDb,
    'SELECT
        COALESCE(NULLIF(TRIM(payment_method),""),NULLIF(TRIM(payment),""),"Other") AS method,
        COUNT(*) AS count,
        COALESCE(SUM(COALESCE(total_amount,total,0)),0) AS value
     FROM sales
     WHERE pharmacy_id=? AND branch_id=? AND sale_date BETWEEN ? AND ?
     GROUP BY method
     ORDER BY value DESC',
    'iiss',
    [$pharmacy_id, $branch_id, $start_dt, $end_dt]
);

$payment_total = array_sum(array_map(static fn($r) => (float)$r['value'], $payment_mix));

/* ---------- Last 12 calendar months trend ---------- */
$trend = vb_rows(
    $adminDb,
    'SELECT
        DATE_FORMAT(sale_date,"%Y-%m") AS ym,
        DATE_FORMAT(sale_date,"%b %Y") AS label,
        COUNT(*) AS count,
        COALESCE(SUM(COALESCE(total_amount,total,0)),0) AS value
     FROM sales
     WHERE pharmacy_id=? AND branch_id=?
       AND sale_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH),"%Y-%m-01")
     GROUP BY ym,label
     ORDER BY ym ASC',
    'ii',
    [$pharmacy_id, $branch_id]
);

/* ---------- Recent sales ---------- */
$recent_sales = vb_rows(
    $adminDb,
    'SELECT id, invoice, issued_by, payment_method, payment, COALESCE(total_amount,total,0) AS amount, sale_date
     FROM sales
     WHERE pharmacy_id=? AND branch_id=?
     ORDER BY sale_date DESC, id DESC
     LIMIT 12',
    'ii',
    [$pharmacy_id, $branch_id]
);

/* ---------- Stock alerts ---------- */
$stock_alerts = vb_rows(
    $adminDb,
    'SELECT id, item_name, barcode, quantity, price, expiry_date, is_active
     FROM store_items
     WHERE pharmacy_id=? AND branch_id=?
       AND is_active=1
       AND (quantity < 10 OR (expiry_date IS NOT NULL AND expiry_date < CURDATE()))
     ORDER BY
       CASE WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 0 ELSE 1 END,
       quantity ASC, item_name ASC
     LIMIT 15',
    'ii',
    [$pharmacy_id, $branch_id]
);

/* ---------- Online orders ---------- */
$online_orders = vb_rows(
    $adminDb,
    'SELECT
        co.id, co.order_number, co.total_amount, co.payment_method, co.status, co.order_date,
        c.full_name, c.phone
     FROM clients_orders co
     LEFT JOIN clients c ON c.id=co.client_id
     WHERE co.pharmacy_id=? AND co.branch_id=?
     ORDER BY co.order_date DESC, co.id DESC
     LIMIT 10',
    'ii',
    [$pharmacy_id, $branch_id]
);

/* ---------- Staff ---------- */
$staff = vb_rows(
    $adminDb,
    'SELECT id, full_name, username, email, role, mobile_number, status
     FROM users
     WHERE pharmacy_id=? AND branch_id=?
     ORDER BY full_name ASC, username ASC
     LIMIT 12',
    'ii',
    [$pharmacy_id, $branch_id]
);

/* ---------- Purchase orders if present ---------- */
$purchase_orders = [];
if (vb_table_exists($adminDb, 'purchase_orders')) {
    $purchase_orders = vb_rows(
        $adminDb,
        'SELECT id, po_number, po_date, expected_date, status, total_cost
         FROM purchase_orders
         WHERE pharmacy_id=? AND branch_id=?
         ORDER BY po_date DESC, id DESC
         LIMIT 8',
        'ii',
        [$pharmacy_id, $branch_id]
    );
}

$current_admin_page = 'branches.php';
$admin_page_title = 'Branch Dashboard';
$branch_count = (int)(vb_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);
$total_orders = (int)(vb_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);
?>
<?php require __DIR__ . '/actions/admin_aside.php'; ?>
<div class="branch-admin-main">
    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="branch-content">
        <div class="page-head">
            <div>
                <div class="eyebrow"><i class="fas fa-store"></i> Network / Branch Operations</div>
                <div class="title-line">
                    <div class="branch-title-icon"><i class="fas fa-store"></i></div>
                    <div>
                        <h1><?= vb_h($branch['branch_name']) ?></h1>
                        <div class="title-meta">
                            <span class="code"><?= vb_h($branch['branch_code'] ?: 'No code') ?></span>
                            <span class="status <?= (int)$branch['is_active'] === 1 ? 'active' : 'inactive' ?>"><span></span><?= (int)$branch['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                            <span><i class="fas fa-location-dot"></i><?= vb_h($branch['location'] ?: 'Location not set') ?></span>
                        </div>
                    </div>
                </div>
                <p>Operational dashboard for <strong><?= vb_h($branch['branch_name']) ?></strong>. All figures below are restricted to this pharmacy and branch.</p>
            </div>
            <div class="head-actions">
                <a class="btn light" href="branches.php"><i class="fas fa-arrow-left"></i> Branch Management</a>
                <button class="btn light" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <section class="filter-card">
            <form class="period-form" method="get">
                <input type="hidden" name="id" value="<?= $branch_id ?>">
                <div>
                    <span class="filter-label">Performance period</span>
                    <strong><?= vb_h(date('d M Y', strtotime($start_date))) ?> — <?= vb_h(date('d M Y', strtotime($end_date))) ?></strong>
                </div>
                <label><span>From</span><input type="date" name="start_date" value="<?= vb_h($start_date) ?>"></label>
                <label><span>To</span><input type="date" name="end_date" value="<?= vb_h($end_date) ?>"></label>
                <button class="btn primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
                <a class="btn light" href="view_branch.php?id=<?= $branch_id ?>"><i class="fas fa-rotate"></i> Reset</a>
            </form>
            <div class="quick-periods">
                <?php
                $periodLinks = [
                    'Today' => [date('Y-m-d'), date('Y-m-d')],
                    'This Week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                    'This Month' => [date('Y-m-01'), date('Y-m-d')],
                    'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                    'This Year' => [date('Y-01-01'), date('Y-m-d')],
                ];
                foreach ($periodLinks as $label => $range):
                ?>
                    <a href="view_branch.php?id=<?= $branch_id ?>&start_date=<?= vb_h($range[0]) ?>&end_date=<?= vb_h($range[1]) ?>"><?= vb_h($label) ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="kpi-grid">
            <div class="kpi"><div class="kpi-top"><span>Revenue</span><i class="fas fa-coins"></i></div><strong><?= vb_money($revenue) ?></strong><small><?= number_format($sales_count) ?> sales in period</small></div>
            <div class="kpi"><div class="kpi-top"><span>Expenses</span><i class="fas fa-file-invoice-dollar"></i></div><strong><?= vb_money($expenses) ?></strong><small><?= number_format($expense_count) ?> expense records</small></div>
            <div class="kpi"><div class="kpi-top"><span>Net</span><i class="fas fa-chart-line"></i></div><strong><?= vb_money($net) ?></strong><small>Revenue minus recorded expenses</small></div>
            <div class="kpi"><div class="kpi-top"><span>Average Ticket</span><i class="fas fa-receipt"></i></div><strong><?= vb_money($average_ticket) ?></strong><small>Average POS sale</small></div>
        </section>

        <section class="secondary-grid">
            <div class="mini-card"><span class="mini-icon blue"><i class="fas fa-boxes-stacked"></i></span><div><b><?= number_format($item_count) ?></b><small>Catalogue items</small></div></div>
            <div class="mini-card"><span class="mini-icon green"><i class="fas fa-cubes"></i></span><div><b><?= number_format($units) ?></b><small>Stock units</small></div></div>
            <div class="mini-card"><span class="mini-icon purple"><i class="fas fa-vault"></i></span><div><b><?= vb_money($valuation) ?></b><small>Stock valuation</small></div></div>
            <div class="mini-card"><span class="mini-icon yellow"><i class="fas fa-triangle-exclamation"></i></span><div><b><?= number_format($low_stock) ?></b><small>Low-stock items</small></div></div>
            <div class="mini-card"><span class="mini-icon red"><i class="fas fa-calendar-xmark"></i></span><div><b><?= number_format($expired) ?></b><small>Expired items</small></div></div>
            <div class="mini-card"><span class="mini-icon cyan"><i class="fas fa-users"></i></span><div><b><?= number_format($staff_count) ?></b><small>Branch staff</small></div></div>
            <div class="mini-card"><span class="mini-icon blue"><i class="fas fa-bag-shopping"></i></span><div><b><?= number_format($online_pending) ?></b><small>Pending online orders</small></div></div>
            <div class="mini-card"><span class="mini-icon purple"><i class="fas fa-prescription-bottle-medical"></i></span><div><b><?= number_format($pending_prescriptions) ?></b><small>Pending prescriptions</small></div></div>
        </section>

        <section class="content-grid">
            <div class="main-col">
                <div class="card">
                    <div class="card-head"><div><h2>Sales Trend</h2><span>Monthly branch revenue over the last 12 months</span></div><i class="fas fa-chart-column"></i></div>
                    <div class="trend-wrap">
                        <?php
                        $maxTrend = 0.0;
                        foreach ($trend as $row) { $maxTrend = max($maxTrend, (float)$row['value']); }
                        if (!$trend):
                        ?>
                            <div class="empty"><i class="fas fa-chart-column"></i>No sales trend data is available yet.</div>
                        <?php else: ?>
                            <div class="trend-chart">
                                <?php foreach ($trend as $row): ?>
                                    <?php $height = $maxTrend > 0 ? max(8, ((float)$row['value'] / $maxTrend) * 150) : 8; ?>
                                    <div class="trend-item" title="<?= vb_h($row['label']) ?> — <?= vb_money($row['value']) ?>">
                                        <div class="bar-value"><?= vb_money($row['value']) ?></div>
                                        <div class="bar" style="height:<?= number_format($height, 1, '.', '') ?>px"></div>
                                        <span><?= vb_h($row['label']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div><h2>Recent Sales</h2><span>Latest POS transactions for this branch</span></div><a href="sales_report.php?branch_id=<?= $branch_id ?>" class="text-link">Sales Report <i class="fas fa-arrow-right"></i></a></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Invoice</th><th>Date</th><th>Issued By</th><th>Payment</th><th class="right">Amount</th></tr></thead>
                            <tbody>
                            <?php if (!$recent_sales): ?>
                                <tr><td colspan="5" class="empty">No sales recorded for this branch.</td></tr>
                            <?php else: foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td><strong><?= vb_h($sale['invoice'] ?: '#'.$sale['id']) ?></strong></td>
                                    <td><?= vb_h(date('d M Y H:i', strtotime($sale['sale_date']))) ?></td>
                                    <td><?= vb_h($sale['issued_by'] ?: 'POS User') ?></td>
                                    <td><span class="payment-pill"><?= vb_h($sale['payment_method'] ?: ($sale['payment'] ?: 'Other')) ?></span></td>
                                    <td class="right amount"><?= vb_money($sale['amount']) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div><h2>Online Orders</h2><span><?= number_format($online_count) ?> total online orders linked to this branch</span></div><a href="online_orders.php?branch_id=<?= $branch_id ?>" class="text-link">Manage Orders <i class="fas fa-arrow-right"></i></a></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Status</th><th class="right">Amount</th></tr></thead>
                            <tbody>
                            <?php if (!$online_orders): ?>
                                <tr><td colspan="5" class="empty">No online orders found.</td></tr>
                            <?php else: foreach ($online_orders as $order): ?>
                                <tr>
                                    <td><strong><?= vb_h($order['order_number']) ?></strong><small><?= vb_h(date('d M Y H:i', strtotime($order['order_date']))) ?></small></td>
                                    <td><?= vb_h($order['full_name'] ?: 'Customer') ?><small><?= vb_h($order['phone'] ?: '') ?></small></td>
                                    <td><?= vb_h($order['payment_method'] ?: 'Other') ?></td>
                                    <td><span class="order-status <?= strtolower((string)$order['status']) ?>"><?= vb_h($order['status']) ?></span></td>
                                    <td class="right amount"><?= vb_money($order['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="side-col">
                <div class="card">
                    <div class="card-head"><div><h2>Payment Mix</h2><span>Selected performance period</span></div><i class="fas fa-wallet"></i></div>
                    <div class="payment-list">
                        <?php if (!$payment_mix): ?>
                            <div class="empty">No payment data in this period.</div>
                        <?php else: foreach ($payment_mix as $mix): ?>
                            <?php $percent = $payment_total > 0 ? ((float)$mix['value'] / $payment_total) * 100 : 0; ?>
                            <div class="payment-row">
                                <div class="payment-row-top"><strong><?= vb_h($mix['method']) ?></strong><span><?= number_format((float)$mix['value'], 2) ?> · <?= number_format($percent, 1) ?>%</span></div>
                                <div class="progress"><span style="width:<?= number_format(min(100, $percent), 2, '.', '') ?>%"></span></div>
                                <small><?= number_format((int)$mix['count']) ?> transaction<?= (int)$mix['count'] === 1 ? '' : 's' ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div><h2>Stock Alerts</h2><span>Items requiring attention</span></div><a href="pharmacy_stock.php?branch_id=<?= $branch_id ?>" class="text-link">Inventory</a></div>
                    <div class="alert-list">
                        <?php if (!$stock_alerts): ?>
                            <div class="healthy"><i class="fas fa-circle-check"></i><strong>Inventory looks healthy</strong><span>No low-stock or expired active items were found.</span></div>
                        <?php else: foreach ($stock_alerts as $item): ?>
                            <?php $isExpired = !empty($item['expiry_date']) && $item['expiry_date'] < date('Y-m-d'); ?>
                            <div class="stock-row">
                                <span class="stock-icon <?= $isExpired ? 'expired' : 'low' ?>"><i class="fas <?= $isExpired ? 'fa-calendar-xmark' : 'fa-box' ?>"></i></span>
                                <div><strong><?= vb_h($item['item_name']) ?></strong><small><?= $isExpired ? 'Expired '.$item['expiry_date'] : 'Quantity '.$item['quantity'] ?></small></div>
                                <span class="stock-qty"><?= (int)$item['quantity'] ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div><h2>Branch Staff</h2><span><?= number_format($staff_count) ?> assigned users</span></div><a href="staff_management.php" class="text-link">Staff</a></div>
                    <div class="staff-list">
                        <?php if (!$staff): ?>
                            <div class="empty">No staff assigned to this branch.</div>
                        <?php else: foreach ($staff as $member): ?>
                            <div class="staff-row">
                                <span class="avatar"><?= vb_h(strtoupper(substr(trim((string)($member['full_name'] ?: $member['username'] ?: 'U')),0,1))) ?></span>
                                <div><strong><?= vb_h($member['full_name'] ?: $member['username'] ?: 'Staff') ?></strong><small><?= vb_h($member['role'] ?: 'User') ?> · <?= vb_h($member['mobile_number'] ?: $member['email'] ?: '') ?></small></div>
                                <span class="staff-status"><?= vb_h($member['status'] ?: 'Active') ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <?php if ($purchase_orders): ?>
                <div class="card">
                    <div class="card-head"><div><h2>Purchase Orders</h2><span>Recent branch purchasing activity</span></div><a href="purchase_orders.php?branch_id=<?= $branch_id ?>" class="text-link">Open</a></div>
                    <div class="po-list">
                        <?php foreach ($purchase_orders as $po): ?>
                            <div class="po-row">
                                <div><strong><?= vb_h($po['po_number']) ?></strong><small><?= vb_h(date('d M Y', strtotime($po['po_date']))) ?></small></div>
                                <span class="po-status"><?= vb_h($po['status']) ?></span>
                                <strong><?= vb_money($po['total_cost']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card branch-details">
                    <div class="card-head"><div><h2>Branch Details</h2><span>Stored operational information</span></div><i class="fas fa-address-card"></i></div>
                    <div class="details">
                        <div><small>Branch code</small><strong><?= vb_h($branch['branch_code'] ?: '—') ?></strong></div>
                        <div><small>Phone</small><strong><?= vb_h($branch['phone'] ?: '—') ?></strong></div>
                        <div><small>Email</small><strong><?= vb_h($branch['branch_email'] ?: '—') ?></strong></div>
                        <div><small>Location</small><strong><?= vb_h($branch['location'] ?: '—') ?></strong></div>
                        <div class="full"><small>Bank details</small><p><?= nl2br(vb_h($branch['bank_details'] ?: 'Not configured')) ?></p></div>
                        <div class="full"><small>Mobile money</small><p><?= nl2br(vb_h($branch['mobile_money_details'] ?: 'Not configured')) ?></p></div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<style>
.branch-admin-main{margin-left:var(--sidebar);min-height:100vh;background:var(--bg)}
.branch-content{padding:24px 28px 42px;max-width:1600px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:18px}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1.1px;color:var(--blue);font-weight:850;margin-bottom:8px}
.title-line{display:flex;align-items:center;gap:12px}
.branch-title-icon{width:43px;height:43px;border-radius:11px;background:var(--blue);color:#fff;display:grid;place-items:center;box-shadow:0 6px 15px rgba(36,107,254,.18)}
.page-head h1{font-size:27px;line-height:1.1;margin:0;color:#18232d}
.page-head p{margin:7px 0 0;color:var(--muted);font-size:12px;line-height:1.55;max-width:900px}
.title-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;color:#73808d;font-size:10px}
.title-meta i{margin-right:4px;color:#8b97a3}
.code{padding:4px 7px;background:#f0f3f6;border:1px solid #e1e6eb;border-radius:6px;font-weight:800;color:#586572}
.status{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:4px 7px;font-size:9px;font-weight:850}
.status span{width:6px;height:6px;border-radius:50%}
.status.active{background:var(--green-soft);color:#19764f}.status.active span{background:var(--green)}
.status.inactive{background:#f1f3f5;color:#697582}.status.inactive span{background:#9ca6af}
.head-actions{display:flex;gap:8px}
.btn{border:1px solid transparent;border-radius:9px;min-height:38px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;font-size:11px;font-weight:800;text-decoration:none}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn.light{background:#fff;border-color:var(--border);color:#4e5b68}
.btn:hover{filter:brightness(.98)}
.filter-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:15px}
.period-form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;padding:13px 16px}
.period-form>div{margin-right:auto;min-width:220px}
.period-form>div strong{display:block;color:#24303b;font-size:12px;margin-top:3px}
.filter-label{display:block;color:#7b8793;text-transform:uppercase;letter-spacing:.7px;font-size:9px;font-weight:850}
.period-form label{display:flex;flex-direction:column;gap:4px}
.period-form label span{font-size:9px;text-transform:uppercase;color:#7b8793;font-weight:850}
.period-form input{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 9px;font-size:11px;color:#27323d}
.quick-periods{display:flex;gap:5px;flex-wrap:wrap;padding:0 16px 12px}
.quick-periods a{font-size:9px;font-weight:800;color:#566473;border:1px solid #e3e7eb;background:#fafbfd;border-radius:999px;padding:6px 9px}
.quick-periods a:hover{color:var(--blue);border-color:#b9cbef}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:13px}
.kpi{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 17px;box-shadow:var(--shadow)}
.kpi-top{display:flex;justify-content:space-between;color:#707b87;text-transform:uppercase;letter-spacing:.8px;font-size:9px;font-weight:850}
.kpi-top i{color:var(--blue)}
.kpi strong{display:block;font-size:22px;color:#18232d;margin-top:10px}
.kpi small{display:block;color:#818c97;font-size:9px;margin-top:4px}
.secondary-grid{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:9px;margin-bottom:15px}
.mini-card{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px;box-shadow:var(--shadow);min-width:0}
.mini-icon{width:29px;height:29px;flex:0 0 29px;border-radius:8px;display:grid;place-items:center;font-size:11px}
.mini-icon.blue{background:var(--blue-soft);color:var(--blue)}.mini-icon.green{background:var(--green-soft);color:var(--green)}.mini-icon.purple{background:#f0ecff;color:var(--purple)}.mini-icon.yellow{background:var(--yellow-soft);color:#c58a12}.mini-icon.red{background:var(--red-soft);color:var(--red)}.mini-icon.cyan{background:#e8f7fb;color:#168cab}
.mini-card b{display:block;font-size:12px;color:#26313b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mini-card small{display:block;font-size:8px;color:#84909b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.content-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.8fr);gap:14px}
.main-col,.side-col{min-width:0}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:14px}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid #edf0f3}
.card-head h2{margin:0;font-size:13px;color:#27323d;font-weight:850}
.card-head span{display:block;font-size:9px;color:#85909c;margin-top:4px}
.card-head>i{color:#8b97a3}
.text-link{font-size:9px;color:var(--blue);font-weight:850;text-decoration:none;white-space:nowrap}
.text-link i{margin-left:3px}
.trend-wrap{padding:14px 16px 11px}
.trend-chart{height:205px;display:flex;align-items:flex-end;gap:8px;overflow-x:auto;padding:8px 2px 0}
.trend-item{min-width:62px;height:190px;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}
.trend-item .bar{width:30px;max-height:150px;background:var(--blue);border-radius:6px 6px 2px 2px;opacity:.88}
.trend-item .bar-value{font-size:8px;color:#65717d;white-space:nowrap;margin-bottom:4px;transform:rotate(-48deg);transform-origin:center bottom;min-height:11px}
.trend-item>span{font-size:8px;color:#7d8995;margin-top:7px;white-space:nowrap}
.data-table{width:100%;border-collapse:collapse;min-width:650px}
.data-table th{background:#fafbfd;color:#707c88;text-transform:uppercase;letter-spacing:.7px;font-size:8px;padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.data-table td{padding:10px 12px;border-bottom:1px solid #edf0f3;font-size:10px;color:#53606c}
.data-table tr:last-child td{border-bottom:0}
.data-table tr:hover td{background:#fbfcfe}
.data-table strong{color:#26313b;font-size:10px}
.data-table small{display:block;color:#8b96a0;font-size:8px;margin-top:3px}
.data-table .right{text-align:right}
.amount{font-weight:850;color:#1e2b35!important}
.payment-pill{display:inline-block;background:#f3f6f9;border:1px solid #e3e8ed;border-radius:999px;padding:4px 7px;font-size:8px;font-weight:800}
.order-status{display:inline-block;padding:4px 7px;border-radius:999px;font-size:8px;font-weight:850}
.order-status.pending{background:var(--yellow-soft);color:#916b14}.order-status.processing{background:var(--blue-soft);color:#275cbf}.order-status.completed{background:var(--green-soft);color:#19764f}.order-status.cancelled{background:var(--red-soft);color:#b23d4d}
.payment-list{padding:13px 15px}
.payment-row{padding:10px 0;border-bottom:1px solid #edf0f3}.payment-row:last-child{border-bottom:0}
.payment-row-top{display:flex;justify-content:space-between;gap:8px}.payment-row-top strong{font-size:10px;color:#27323d}.payment-row-top span{font-size:9px;color:#75818d}
.payment-row small{display:block;color:#8b96a0;font-size:8px;margin-top:4px}
.progress{height:5px;background:#edf1f4;border-radius:5px;overflow:hidden;margin-top:7px}.progress span{display:block;height:100%;background:var(--blue);border-radius:5px}
.alert-list{padding:7px 14px 12px}
.stock-row{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #edf0f3}.stock-row:last-child{border-bottom:0}
.stock-icon{width:27px;height:27px;flex:0 0 27px;border-radius:7px;display:grid;place-items:center;font-size:10px}.stock-icon.low{background:var(--yellow-soft);color:#a27615}.stock-icon.expired{background:var(--red-soft);color:var(--red)}
.stock-row>div{min-width:0;flex:1}.stock-row strong{display:block;font-size:10px;color:#2c3741;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.stock-row small{display:block;color:#87929d;font-size:8px;margin-top:3px}.stock-qty{font-size:11px;font-weight:850;color:#465360}
.healthy{padding:19px 8px;text-align:center;color:#6f7c87}.healthy i{font-size:22px;color:var(--green);display:block;margin-bottom:8px}.healthy strong{display:block;font-size:10px;color:#33404b}.healthy span{display:block;font-size:8px;margin-top:3px}
.staff-list{padding:7px 14px 12px}.staff-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #edf0f3}.staff-row:last-child{border-bottom:0}.avatar{width:27px;height:27px;flex:0 0 27px;border-radius:50%;background:#e9eef5;color:#405164;display:grid;place-items:center;font-size:9px;font-weight:850}.staff-row>div{min-width:0;flex:1}.staff-row strong{display:block;font-size:9px;color:#2e3943;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.staff-row small{display:block;color:#85909b;font-size:7px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.staff-status{font-size:7px;font-weight:800;color:var(--green);background:var(--green-soft);padding:4px 6px;border-radius:999px}
.po-list{padding:7px 14px 11px}.po-row{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #edf0f3}.po-row:last-child{border-bottom:0}.po-row strong{font-size:9px;color:#2d3842}.po-row small{display:block;color:#87929d;font-size:7px;margin-top:2px}.po-status{font-size:7px;padding:4px 6px;background:#f3f5f7;border-radius:999px;color:#697580;font-weight:800}
.branch-details .details{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px 16px}.details>div{min-width:0}.details .full{grid-column:1/-1}.details small{display:block;text-transform:uppercase;letter-spacing:.6px;font-size:7px;color:#8b96a0;font-weight:850;margin-bottom:4px}.details strong{font-size:10px;color:#2d3842;word-break:break-word}.details p{margin:0;color:#63707c;font-size:9px;line-height:1.55;word-break:break-word}
.empty{padding:28px 12px!important;text-align:center;color:#89949f!important;font-size:9px}.empty i{display:block;font-size:22px;margin-bottom:8px;color:#b2bcc5}
@media(max-width:1200px){.secondary-grid{grid-template-columns:repeat(4,1fr)}.content-grid{grid-template-columns:1fr}.side-col{display:grid;grid-template-columns:1fr 1fr;gap:14px}.side-col .card{margin-bottom:0}.branch-details{grid-column:1/-1}}
@media(max-width:900px){.branch-admin-main{margin-left:0}.kpi-grid{grid-template-columns:repeat(2,1fr)}.page-head{flex-direction:column;align-items:flex-start}.head-actions{width:100%}}
@media(max-width:650px){.branch-content{padding:16px 12px}.secondary-grid{grid-template-columns:repeat(2,1fr)}.kpi-grid{grid-template-columns:1fr}.side-col{display:block}.side-col .card{margin-bottom:14px}.period-form>div{width:100%;margin-right:0}.period-form label{width:100%}.period-form label input{width:100%}.period-form .btn{width:100%}.title-line{align-items:flex-start}}
@media print{.admin-aside,.admin-header,.head-actions,.quick-periods,.period-form .btn,.text-link{display:none!important}.branch-admin-main{margin:0}.branch-content{padding:0}.card,.kpi,.mini-card{box-shadow:none}.filter-card{border:0}.page-head{margin-bottom:10px}}
</style>
