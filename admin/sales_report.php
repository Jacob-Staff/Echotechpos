<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';

date_default_timezone_set('Africa/Lusaka');

/* Admin-only page */
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($userRole, ['admin', 'administrator'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
if ($pharmacy_id <= 0) {
    header('Location: ../login_inc.php');
    exit;
}

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string {
    return 'K' . number_format($value, 2);
}

/* Branding / admin sidebar data */
$pharmacy_name = 'PHARMACY POS';
$branch_count = 0;
$total_orders = 0;
$user_display_name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator');
$user_role = 'Admin';
$admin_page_title = 'Sales Analytics';

try {
    $stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $pharmacy_name = (string)$row['name'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id = ?");
    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();
    $branch_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id = ?");
    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();
    $total_orders = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
} catch (Throwable $e) {
    error_log('ADMIN SALES BRANDING: ' . $e->getMessage());
}

/* Filters */
$start = trim((string)($_GET['start'] ?? date('Y-m-01')));
$end   = trim((string)($_GET['end'] ?? date('Y-m-d')));
$branch_filter = (int)($_GET['branch_id'] ?? 0);
$category_filter = trim((string)($_GET['category'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
if ($start > $end) {
    [$start, $end] = [$end, $start];
}

/* Branches and categories */
$branches = [];
$categories = [];

try {
    $stmt = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? ORDER BY branch_name");
    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) $branches[] = $x;
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT DISTINCT category
        FROM store_items
        WHERE pharmacy_id = ? AND category IS NOT NULL AND category <> ''
        ORDER BY category
    ");
    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) $categories[] = (string)$x['category'];
    $stmt->close();
} catch (Throwable $e) {
    error_log('ADMIN SALES FILTERS: ' . $e->getMessage());
}

/* Dynamic itemized report */
$where = [
    's.pharmacy_id = ?',
    'DATE(COALESCE(s.sale_date, s.created_at)) BETWEEN ? AND ?'
];
$params = [$pharmacy_id, $start, $end];
$types = 'iss';

if ($branch_filter > 0) {
    $where[] = 's.branch_id = ?';
    $params[] = $branch_filter;
    $types .= 'i';
}
if ($category_filter !== '') {
    $where[] = 'COALESCE(i.category, \'\') = ?';
    $params[] = $category_filter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(s.invoice LIKE ? OR i.item_name LIKE ? OR COALESCE(u.username, s.issued_by, \'\') LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$whereSql = implode(' AND ', $where);

$sales_rows = [];
$total_revenue = 0.0;
$total_units = 0;
$invoice_set = [];

$sql = "
    SELECT
        s.id AS sale_id,
        s.invoice,
        s.created_at,
        s.sale_date,
        s.payment_method,
        COALESCE(s.total, s.total_amount, 0) AS sale_total,
        b.branch_name,
        COALESCE(u.full_name, u.username, s.issued_by, 'System') AS issuer,
        i.item_name,
        i.category,
        si.quantity,
        si.unit_price,
        (si.quantity * si.unit_price) AS line_total
    FROM sales s
    INNER JOIN sales_items si ON si.sale_id = s.id
    LEFT JOIN store_items i ON i.id = si.product_id
    LEFT JOIN branches b ON b.id = s.branch_id AND b.pharmacy_id = s.pharmacy_id
    LEFT JOIN users u ON u.id = s.user_id
    WHERE {$whereSql}
    ORDER BY COALESCE(s.sale_date, s.created_at) DESC, s.id DESC
    LIMIT 1000
";

try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException($conn->error);

    $bind = [$types];
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);

    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $sales_rows[] = $row;
        $total_units += (int)$row['quantity'];
        $total_revenue += (float)$row['line_total'];
        $invoice_set[(string)$row['invoice']] = true;
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('ADMIN SALES REPORT: ' . $e->getMessage());
}

/* Daily trend */
$daily_labels = [];
$daily_values = [];
try {
    $sql = "
        SELECT DATE(COALESCE(s.sale_date, s.created_at)) AS sale_day,
               SUM(si.quantity * si.unit_price) AS amount
        FROM sales s
        INNER JOIN sales_items si ON si.sale_id = s.id
        LEFT JOIN store_items i ON i.id = si.product_id
        WHERE {$whereSql}
        GROUP BY DATE(COALESCE(s.sale_date, s.created_at))
        ORDER BY sale_day
    ";
    $stmt = $conn->prepare($sql);
    $bind = [$types];
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $daily_labels[] = date('d M', strtotime((string)$row['sale_day']));
        $daily_values[] = round((float)$row['amount'], 2);
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('ADMIN SALES DAILY: ' . $e->getMessage());
}

/* Category totals */
$category_labels = [];
$category_values = [];
try {
    $sql = "
        SELECT COALESCE(i.category, 'General') AS cat,
               SUM(si.quantity * si.unit_price) AS amount
        FROM sales s
        INNER JOIN sales_items si ON si.sale_id = s.id
        LEFT JOIN store_items i ON i.id = si.product_id
        WHERE {$whereSql}
        GROUP BY COALESCE(i.category, 'General')
        ORDER BY amount DESC
    ";
    $stmt = $conn->prepare($sql);
    $bind = [$types];
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $category_labels[] = (string)$row['cat'];
        $category_values[] = round((float)$row['amount'], 2);
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('ADMIN SALES CATEGORY: ' . $e->getMessage());
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pharmacy_name) ?> - Admin Sales Analytics</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#1d252d;--muted:#6d7782;--border:#dfe4e9;--blue:#246bfe;--green:#159a68;--yellow:#e7a72e;--sidebar:250px;--shadow:0 4px 18px rgba(31,40,49,.06)}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}body{overflow-x:hidden}
.main{margin-left:var(--sidebar);min-height:100vh}.page{padding:22px;max-width:1700px;margin:auto}
.heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.heading h1{margin:0 0 5px;font-size:28px}.heading p{margin:0;color:var(--muted)}
.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--border);background:#fff;border-radius:8px;padding:10px 13px;font-weight:700;cursor:pointer;text-decoration:none;color:var(--text)}.btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}.kpi{padding:16px}.kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:800}.kpi strong{display:block;font-size:25px;margin-top:7px}.kpi.green{border-top:3px solid var(--green)}.kpi.blue{border-top:3px solid var(--blue)}.kpi.yellow{border-top:3px solid var(--yellow)}
.filters{padding:15px;margin-bottom:16px}.filter-grid{display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr 1fr auto;gap:9px;align-items:end}.field label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;color:var(--muted);margin-bottom:5px}.field input,.field select{width:100%;height:40px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff}
.charts{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:16px}.chart-card{padding:16px}.chart-wrap{height:280px;position:relative}
.table-card{overflow:hidden}.table-head{padding:15px 17px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}.table-head h2{font-size:16px;margin:0}.responsive{overflow:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid #edf0f4;font-size:12px}th{font-size:10px;text-transform:uppercase;color:#667789;background:#fafbfc;white-space:nowrap}td.num{text-align:right;font-weight:700}.badge{display:inline-block;border-radius:999px;padding:5px 8px;background:#eef2f6;color:#52606d;font-size:10px;font-weight:800}
.empty{text-align:center;padding:40px;color:var(--muted)}@media(max-width:1200px){.kpis{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:repeat(3,1fr)}.charts{grid-template-columns:1fr}}@media(max-width:900px){.main{margin-left:0}.page{padding:16px}.heading{flex-direction:column}.filter-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.kpis{grid-template-columns:1fr}.filter-grid{grid-template-columns:1fr}.heading h1{font-size:23px}}@media print{.admin-aside,.admin-header,.no-print{display:none!important}.main{margin-left:0}.page{padding:0}.card{box-shadow:none}}
</style>
</head>
<body>
<main class="main">
<?php
require_once __DIR__ . '/actions/admin_aside.php';
require_once __DIR__ . '/actions/admin_header.php';
?>
<div class="page">
<div class="heading">
<div><h1><i class="fas fa-chart-line" style="color:var(--blue)"></i> Sales &amp; Analytics</h1><p><?= h($pharmacy_name) ?> &mdash; group-wide admin report across all branches.</p></div>
<div class="actions no-print"><button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print / Export</button></div>
</div>

<div class="kpis">
<div class="card kpi green"><small>Total Revenue</small><strong><?= money($total_revenue) ?></strong></div>
<div class="card kpi blue"><small>Units Sold</small><strong><?= number_format($total_units) ?></strong></div>
<div class="card kpi yellow"><small>Invoices</small><strong><?= number_format(count($invoice_set)) ?></strong></div>
<div class="card kpi"><small>Report Rows</small><strong><?= number_format(count($sales_rows)) ?></strong></div>
</div>

<form class="card filters no-print" method="get">
<div class="filter-grid">
<div class="field"><label>Search</label><input name="search" value="<?= h($search) ?>" placeholder="Invoice, product or staff"></div>
<div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $branch_filter===(int)$b['id']?'selected':'' ?>><?= h($b['branch_name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Category</label><select name="category"><option value="">All categories</option><?php foreach($categories as $c): ?><option value="<?= h($c) ?>" <?= $category_filter===$c?'selected':'' ?>><?= h($c) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Start date</label><input type="date" name="start" value="<?= h($start) ?>"></div>
<div class="field"><label>End date</label><input type="date" name="end" value="<?= h($end) ?>"></div>
<div><button class="btn primary" type="submit"><i class="fas fa-filter"></i> Apply</button></div>
</div>
</form>

<div class="charts">
<div class="card chart-card"><h3>Daily Revenue Trend</h3><div class="chart-wrap"><canvas id="dailyChart"></canvas></div></div>
<div class="card chart-card"><h3>Revenue by Category</h3><div class="chart-wrap"><canvas id="categoryChart"></canvas></div></div>
</div>

<div class="card table-card">
<div class="table-head"><h2>Itemized Sales History</h2><span class="badge"><?= number_format(count($sales_rows)) ?> rows</span></div>
<div class="responsive"><table><thead><tr><th>#</th><th>Invoice</th><th>Product</th><th>Category</th><th>Branch</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><th>Payment</th><th>Handled By</th><th>Date</th></tr></thead>
<tbody>
<?php if($sales_rows): foreach($sales_rows as $i=>$r): ?>
<tr>
<td><?= $i+1 ?></td><td><strong>#<?= h($r['invoice']) ?></strong></td><td><?= h($r['item_name'] ?: 'Item unavailable') ?></td><td><?= h($r['category'] ?: 'General') ?></td><td><?= h($r['branch_name'] ?: 'Unassigned') ?></td><td><?= (int)$r['quantity'] ?></td><td><?= money((float)$r['unit_price']) ?></td><td class="num"><?= money((float)$r['line_total']) ?></td><td><span class="badge"><?= h($r['payment_method'] ?: 'Cash') ?></span></td><td><?= h($r['issuer']) ?></td><td><?= h(date('d M Y H:i', strtotime((string)($r['sale_date'] ?: $r['created_at'])))) ?></td>
</tr>
<?php endforeach; else: ?><tr><td colspan="11" class="empty">No sales found for the selected criteria.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
</main>
<script>
const dailyLabels=<?= json_encode($daily_labels,JSON_UNESCAPED_UNICODE) ?>;
const dailyValues=<?= json_encode($daily_values) ?>;
const catLabels=<?= json_encode($category_labels,JSON_UNESCAPED_UNICODE) ?>;
const catValues=<?= json_encode($category_values) ?>;
new Chart(document.getElementById('dailyChart'),{type:'line',data:{labels:dailyLabels,datasets:[{label:'Revenue (K)',data:dailyValues,borderColor:'#246bfe',backgroundColor:'rgba(36,107,254,.08)',fill:true,tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
new Chart(document.getElementById('categoryChart'),{type:'doughnut',data:{labels:catLabels,datasets:[{data:catValues,backgroundColor:['#246bfe','#159a68','#e7a72e','#19a9d2','#7658e8','#d94d61','#6d7782']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
</script>
</body>
</html>
