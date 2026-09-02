<?php
/**
 * EchoTech POS - Sales & Analytics
 * Database-driven Admin report.
 */
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

function sr_e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function sr_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $k => &$v) $refs[] = &$v;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
function sr_fetch_all(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException($conn->error);
    if ($types !== '') {
        $p = $params;
        sr_bind($stmt, $types, $p);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

$pharmacy = sr_fetch_all($conn,
    "SELECT name FROM pharmacies WHERE id=? LIMIT 1", "i", [$pharmacy_id]);
$pharmacy_name = $pharmacy[0]['name'] ?? 'PHARMACY POS';

$branches = sr_fetch_all($conn,
    "SELECT id, branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name",
    "i", [$pharmacy_id]);

$categories = sr_fetch_all($conn,
    "SELECT DISTINCT category AS name FROM store_items
     WHERE pharmacy_id=? AND category IS NOT NULL AND TRIM(category)<>''
     ORDER BY category",
    "i", [$pharmacy_id]);

$today = date('Y-m-d');
$start_date = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$end_date   = trim((string)($_GET['end_date'] ?? $today));
$branch_id  = (int)($_GET['branch_id'] ?? 0);
$category   = trim((string)($_GET['category'] ?? ''));
$search     = trim((string)($_GET['search'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = $today;
if ($start_date > $end_date) [$start_date, $end_date] = [$end_date, $start_date];

if ($branch_id > 0) {
    $ok = sr_fetch_all($conn,
        "SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1",
        "ii", [$branch_id, $pharmacy_id]);
    if (!$ok) $branch_id = 0;
}

$where = "s.pharmacy_id=? AND DATE(s.sale_date) BETWEEN ? AND ?";
$types = "iss";
$params = [$pharmacy_id, $start_date, $end_date];

if ($branch_id > 0) { $where .= " AND s.branch_id=?"; $types .= "i"; $params[] = $branch_id; }
if ($category !== '') { $where .= " AND COALESCE(i.category,'')=?"; $types .= "s"; $params[] = $category; }
if ($search !== '') {
    $where .= " AND (s.invoice LIKE CONCAT('%',?,'%')
                OR s.issued_by LIKE CONCAT('%',?,'%')
                OR u.username LIKE CONCAT('%',?,'%')
                OR u.full_name LIKE CONCAT('%',?,'%')
                OR i.item_name LIKE CONCAT('%',?,'%')
                OR i.barcode LIKE CONCAT('%',?,'%'))";
    $types .= "ssssss";
    for ($n=0;$n<6;$n++) $params[]=$search;
}

$summary = sr_fetch_all($conn,
    "SELECT COALESCE(SUM(x.total_amount),0) AS revenue,
            COALESCE(SUM(x.units),0) AS units,
            COUNT(*) AS invoices
     FROM (
        SELECT s.id, s.total_amount, COALESCE(SUM(si.quantity),0) AS units
        FROM sales s
        LEFT JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
        LEFT JOIN store_items i ON i.id=si.product_id
        LEFT JOIN users u ON u.id=s.user_id
        WHERE $where
        GROUP BY s.id, s.total_amount
     ) x",
    $types, $params)[0] ?? ['revenue'=>0,'units'=>0,'invoices'=>0];

$daily = sr_fetch_all($conn,
    "SELECT x.sale_day, COALESCE(SUM(x.total_amount),0) AS revenue,
            COUNT(*) AS invoices
     FROM (
        SELECT DATE(s.sale_date) AS sale_day, s.id, s.total_amount
        FROM sales s
        LEFT JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
        LEFT JOIN store_items i ON i.id=si.product_id
        LEFT JOIN users u ON u.id=s.user_id
        WHERE $where
        GROUP BY DATE(s.sale_date), s.id, s.total_amount
     ) x
     GROUP BY x.sale_day ORDER BY x.sale_day",
    $types, $params);

$categoryRows = sr_fetch_all($conn,
    "SELECT COALESCE(NULLIF(TRIM(i.category),''),'General') AS category_name,
            COALESCE(SUM(si.quantity*si.unit_price),0) AS revenue
     FROM sales s
     INNER JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
     LEFT JOIN store_items i ON i.id=si.product_id
     LEFT JOIN users u ON u.id=s.user_id
     WHERE $where
     GROUP BY COALESCE(NULLIF(TRIM(i.category),''),'General')
     ORDER BY revenue DESC",
    $types, $params);

$rows = sr_fetch_all($conn,
    "SELECT s.id, s.invoice, DATE_FORMAT(s.sale_date,'%Y-%m-%d %H:%i') AS sale_time,
            s.total_amount, s.payment_method, s.issued_by, s.user_id,
            b.branch_name,
            COALESCE(NULLIF(TRIM(i.item_name),''),'Unknown item') AS item_name,
            i.barcode, i.category, si.quantity, si.unit_price,
            COALESCE(NULLIF(TRIM(u.full_name),''),u.username,'') AS cashier
     FROM sales s
     INNER JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
     LEFT JOIN store_items i ON i.id=si.product_id
     LEFT JOIN branches b ON b.id=s.branch_id AND b.pharmacy_id=s.pharmacy_id
     LEFT JOIN users u ON u.id=s.user_id AND u.pharmacy_id=s.pharmacy_id
     WHERE $where
     ORDER BY s.sale_date DESC, s.id DESC, si.id ASC",
    $types, $params);

$branch_label = 'All branches';
if ($branch_id > 0) {
    foreach ($branches as $b) if ((int)$b['id']===$branch_id) $branch_label=$b['branch_name'];
}
$admin_page_title = 'Sales & Analytics';
$user_role = current_role();
$user_display_name = current_user();
$branch_count = count($branches);
$total_orders = (int)($summary['invoices'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sales Report | <?=sr_e($pharmacy_name)?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#6d7782;--green:#159a68;--yellow:#e7a72e}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}a{text-decoration:none}
.admin-main{margin-left:250px;min-height:100vh}.report-content{padding:22px 28px 35px}.page-head{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:20px}.page-head h1{margin:0;font-size:29px;line-height:1.1}.page-head p{margin:7px 0 0;color:var(--muted);font-size:13px}.btn{border:1px solid var(--border);background:#fff;border-radius:8px;padding:10px 15px;font-weight:700;cursor:pointer}.btn-primary{background:var(--blue);border-color:var(--blue);color:#fff}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:18px 20px;min-height:108px;border-top:3px solid var(--blue)}.kpi:nth-child(2){border-top-color:var(--green)}.kpi:nth-child(3){border-top-color:#f0a51a}.kpi:nth-child(4){border-top-color:#7658e8}.label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:#697585;font-weight:800}.value{font-size:27px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.filter-grid{display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr 1fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#6b7786;margin-bottom:6px}.field input,.field select{height:40px;width:100%;border:1px solid #d8dee5;border-radius:7px;padding:0 10px;background:#fff;color:#222}.charts{display:grid;grid-template-columns:1.4fr 1fr;gap:16px;margin-bottom:16px}.chart-card{padding:18px}.chart-title{font-weight:800;margin-bottom:14px}.bars{display:flex;align-items:flex-end;gap:8px;height:180px;border-bottom:1px solid #e8edf2;padding:10px 4px 0}.bar-wrap{flex:1;height:100%;display:flex;align-items:flex-end;min-width:12px}.bar{width:100%;background:#246bfe;border-radius:4px 4px 0 0;min-height:2px}.bar-label{font-size:8px;color:#788494;text-align:center;margin-top:5px;white-space:nowrap;overflow:hidden}.cat-list{display:flex;flex-direction:column;gap:11px}.cat-row{display:grid;grid-template-columns:120px 1fr 75px;gap:9px;align-items:center;font-size:11px}.cat-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.track{height:8px;background:#edf1f5;border-radius:10px;overflow:hidden}.track span{display:block;height:100%;background:#159a68}.cat-amount{text-align:right;font-weight:800}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;font-weight:800}.table-wrap{overflow:auto}.report-table{width:100%;min-width:1050px;border-collapse:collapse}.report-table th{background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;letter-spacing:.5px;padding:12px 14px;text-align:left}.report-table td{padding:12px 14px;border-top:1px solid #edf0f3;font-size:12px}.money{font-weight:800}.muted{color:#8793a0}.badge{display:inline-block;border-radius:14px;padding:4px 8px;background:#edf3ff;color:#246bfe;font-size:10px;font-weight:800}.empty{text-align:center;padding:55px;color:#8b96a4}@media(max-width:1050px){.filter-grid{grid-template-columns:repeat(3,1fr)}.charts{grid-template-columns:1fr}.kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.admin-main{margin-left:0}.report-content{padding:18px 15px}.kpis{grid-template-columns:1fr 1fr}}@media(max-width:600px){.page-head{align-items:flex-start;flex-direction:column}.filter-grid{grid-template-columns:1fr}.kpis{grid-template-columns:1fr}.page-head h1{font-size:24px}}
@media print{.admin-aside,.admin-header,.filters,.no-print{display:none!important}.admin-main{margin:0}.report-content{padding:0}.card{box-shadow:none}}
</style>
</head>
<body>
<?php require __DIR__.'/actions/admin_aside.php'; ?>
<div class="admin-main">
<?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="report-content">
<div class="page-head"><div><h1><i class="fa-solid fa-chart-line" style="color:#246bfe"></i> Sales &amp; Analytics</h1><p><?=sr_e($pharmacy_name)?> â€” group-wide sales report Â· <?=sr_e($branch_label)?></p></div><button class="btn no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Export</button></div>

<div class="kpis">
<div class="card kpi"><div class="label">Total Revenue</div><div class="value">K<?=number_format((float)$summary['revenue'],2)?></div></div>
<div class="card kpi"><div class="label">Units Sold</div><div class="value"><?=number_format((int)$summary['units'])?></div></div>
<div class="card kpi"><div class="label">Invoices</div><div class="value"><?=number_format((int)$summary['invoices'])?></div></div>
<div class="card kpi"><div class="label">Report Rows</div><div class="value"><?=number_format(count($rows))?></div></div>
</div>

<form class="card filters" method="get"><div class="filter-grid">
<div class="field"><label>Search</label><input name="search" value="<?=sr_e($search)?>" placeholder="Invoice, product or staff"></div>
<div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b): ?><option value="<?=$b['id']?>" <?=$branch_id==(int)$b['id']?'selected':''?>><?=sr_e($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Category</label><select name="category"><option value="">All categories</option><?php foreach($categories as $c): ?><option value="<?=sr_e($c['name'])?>" <?=$category===$c['name']?'selected':''?>><?=sr_e($c['name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Start date</label><input type="date" name="start_date" value="<?=sr_e($start_date)?>"></div>
<div class="field"><label>End date</label><input type="date" name="end_date" value="<?=sr_e($end_date)?>"></div>
<div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
</div></form>

<div class="charts">
<div class="card chart-card"><div class="chart-title">Daily Revenue Trend</div><?php $max=0;foreach($daily as $d)$max=max($max,(float)$d['revenue']); if($daily): ?><div class="bars"><?php foreach($daily as $d): $h=$max>0?max(2,((float)$d['revenue']/$max)*100):2; ?><div class="bar-wrap"><div style="width:100%"><div class="bar" style="height:<?=$h?>%"></div><div class="bar-label"><?=date('d M',strtotime($d['sale_day']))?></div></div></div><?php endforeach;?></div><?php else:?><div class="empty">No sales for this date range.</div><?php endif;?></div>
<div class="card chart-card"><div class="chart-title">Revenue by Category</div><?php $catMax=0;foreach($categoryRows as $c)$catMax=max($catMax,(float)$c['revenue']); if($categoryRows): ?><div class="cat-list"><?php foreach($categoryRows as $c): ?><div class="cat-row"><div class="cat-name"><?=sr_e($c['category_name'])?></div><div class="track"><span style="width:<?=($catMax>0?max(2,((float)$c['revenue']/$catMax)*100):0)?>%"></span></div><div class="cat-amount">K<?=number_format((float)$c['revenue'],2)?></div></div><?php endforeach;?></div><?php else:?><div class="empty">No category sales for this period.</div><?php endif;?></div>
</div>

<div class="card table-card"><div class="table-head"><span>Sales Detail</span><span class="muted"><?=count($rows)?> rows</span></div><div class="table-wrap"><table class="report-table"><thead><tr><th>#</th><th>Invoice</th><th>Date / Time</th><th>Product</th><th>Category</th><th>Branch</th><th>Payment</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Cashier</th><th>Action</th></tr></thead><tbody>
<?php if($rows): $n=1;foreach($rows as $r): ?><tr><td><?=$n++?></td><td><span class="badge"><?=sr_e($r['invoice'])?></span></td><td><?=sr_e($r['sale_time'])?></td><td><b><?=sr_e($r['item_name'])?></b><br><span class="muted"><?=sr_e($r['barcode'])?></span></td><td><?=sr_e($r['category'] ?: 'General')?></td><td><?=sr_e($r['branch_name'])?></td><td><?=sr_e($r['payment_method'] ?: 'Cash')?></td><td><?=number_format((int)$r['quantity'])?></td><td>K<?=number_format((float)$r['unit_price'],2)?></td><td class="money">K<?=number_format((float)$r['quantity']*(float)$r['unit_price'],2)?></td><td><?=sr_e($r['cashier'] ?: $r['issued_by'])?></td><td><a class="btn" href="view_invoice.php?id=<?=$r['id']?>"><i class="fa-solid fa-eye"></i></a></td></tr><?php endforeach; else:?><tr><td colspan="12" class="empty">No sales found for the selected criteria.</td></tr><?php endif;?>
</tbody></table></div></div>
</main></div>
</body></html>
