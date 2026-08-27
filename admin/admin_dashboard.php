<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/conn.php';

require_admin();

$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);

if ($pharmacy_id <= 0) {
    header("Location: ../index.php?error=session_expired");
    exit();
}

function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ---------- Pharmacy ---------- */
$pharmacy_name = 'EchoTech POS';
if ($stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $pharmacy_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if ($r && !empty($r['name'])) $pharmacy_name = $r['name'];
    $stmt->close();
}

/* ---------- Helpers ---------- */
function scalar_query($conn, $sql, $types = '', $params = [], $default = 0) {
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return $default;
    if ($types !== '') @$stmt->bind_param($types, ...$params);
    if (!@$stmt->execute()) { $stmt->close(); return $default; }
    $row = @$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $default;
    $value = array_values($row)[0] ?? $default;
    return is_numeric($value) ? $value : ($value ?: $default);
}

function rows_query($conn, $sql, $types = '', $params = []) {
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') @$stmt->bind_param($types, ...$params);
    if (!@$stmt->execute()) { $stmt->close(); return []; }
    $result = @$stmt->get_result();
    $rows = [];
    if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function table_exists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$table}'");
    return $r && $r->num_rows > 0;
}

function column_exists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $r = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $r && $r->num_rows > 0;
}

function first_existing_column($conn, $table, $candidates) {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return null;
}

/* ---------- Core sales ---------- */
$total_sales = (float)scalar_query(
    $conn,
    "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE pharmacy_id = ?",
    "i", [$pharmacy_id], 0
);

$total_orders = (int)scalar_query(
    $conn,
    "SELECT COUNT(id) FROM sales WHERE pharmacy_id = ?",
    "i", [$pharmacy_id], 0
);

$branch_count = (int)scalar_query(
    $conn,
    "SELECT COUNT(id) FROM branches WHERE pharmacy_id = ? AND is_active = 1",
    "i", [$pharmacy_id], 0
);

$avg = $total_orders > 0 ? $total_sales / $total_orders : 0;

/* ---------- Branch revenue ---------- */
$b_names = [];
$b_revenues = [];
$branch_rows = rows_query($conn,
    "SELECT b.branch_name, COALESCE(SUM(s.total_amount),0) AS revenue
     FROM branches b
     LEFT JOIN sales s ON s.branch_id=b.id AND s.pharmacy_id=?
     WHERE b.pharmacy_id=? AND b.is_active=1
     GROUP BY b.id,b.branch_name
     ORDER BY revenue DESC",
    "ii", [$pharmacy_id,$pharmacy_id]
);
foreach ($branch_rows as $r) {
    $b_names[] = $r['branch_name'];
    $b_revenues[] = (float)$r['revenue'];
}

/* ---------- Payment mix ---------- */
$p_labels = [];
$p_counts = [];
if (column_exists($conn,'sales','payment_method')) {
    $payment_rows = rows_query($conn,
        "SELECT COALESCE(NULLIF(TRIM(payment_method),''),'Other') AS method, COUNT(*) AS total
         FROM sales WHERE pharmacy_id=? GROUP BY method ORDER BY total DESC",
        "i", [$pharmacy_id]
    );
    foreach ($payment_rows as $r) {
        $p_labels[] = $r['method'];
        $p_counts[] = (int)$r['total'];
    }
}
if (!$p_labels) { $p_labels=['No data']; $p_counts=[1]; }

/* ---------- Date column discovery ---------- */
$sales_date_col = first_existing_column($conn,'sales',['created_at','sale_date','transaction_date','date','created']);
$trend_labels=[]; $trend_revenue=[]; $trend_transactions=[];

if ($sales_date_col) {
    $sql = "SELECT DATE(`$sales_date_col`) d, COALESCE(SUM(total_amount),0) revenue, COUNT(*) transactions
            FROM sales WHERE pharmacy_id=? GROUP BY DATE(`$sales_date_col`) ORDER BY d DESC LIMIT 14";
    $trend_rows = array_reverse(rows_query($conn,$sql,"i",[$pharmacy_id]));
    foreach ($trend_rows as $r) {
        $trend_labels[] = date('d M',strtotime($r['d']));
        $trend_revenue[] = (float)$r['revenue'];
        $trend_transactions[] = (int)$r['transactions'];
    }
}

/* fallback so charts are always valid */
if (!$trend_labels) {
    $trend_labels=['Today'];
    $trend_revenue=[(float)$total_sales];
    $trend_transactions=[(int)$total_orders];
}

/* ---------- Inventory / categories ---------- */
$stock_total=0; $category_total=0; $category_labels=[]; $category_data=[];
$low_stock_count=0; $expired_count=0;

if (table_exists($conn,'store_items')) {
    $stock_total=(int)scalar_query($conn,
        "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=?",
        "i",[$pharmacy_id],0
    );

    if (column_exists($conn,'store_items','category')) {
        $cat_rows=rows_query($conn,
            "SELECT COALESCE(NULLIF(TRIM(category),''),'Uncategorised') category, COUNT(*) total
             FROM store_items WHERE pharmacy_id=? GROUP BY category ORDER BY total DESC LIMIT 8",
            "i",[$pharmacy_id]
        );
        foreach($cat_rows as $r){
            $category_labels[]=$r['category'];
            $category_data[]=(int)$r['total'];
        }
        $category_total=count($category_labels);
    }

    if (column_exists($conn,'store_items','quantity')) {
        $low_stock_count=(int)scalar_query($conn,
            "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=? AND quantity<=3",
            "i",[$pharmacy_id],0
        );
    }

    if (column_exists($conn,'store_items','expiry_date')) {
        $expired_count=(int)scalar_query($conn,
            "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()",
            "i",[$pharmacy_id],0
        );
    }
}

/* Inventory status doughnut */
$stock_labels=['Healthy','Low Stock','Expired'];
$healthy=max($stock_total-$low_stock_count-$expired_count,0);
$stock_data=[$healthy,$low_stock_count,$expired_count];

/* ---------- Top products ---------- */
$top_products=[];
if (table_exists($conn,'store_items')) {
    /*
      Product-level sales schemas vary between POS installations.
      We only query this panel when the sales table has a product/item reference
      and store_items has the matching id/name fields.
    */
    $sale_item_col=first_existing_column($conn,'sales',['store_item_id','item_id','product_id']);
    $item_name_col=first_existing_column($conn,'store_items',['item_name','name','product_name']);
    if ($sale_item_col && $item_name_col && column_exists($conn,'sales','quantity')) {
        $top_products=rows_query($conn,
            "SELECT si.`$item_name_col` name,
                    COALESCE(SUM(s.quantity),0) qty,
                    COALESCE(SUM(s.total_amount),0) revenue
             FROM sales s
             INNER JOIN store_items si ON si.id=s.`$sale_item_col`
             WHERE s.pharmacy_id=?
             GROUP BY si.id,si.`$item_name_col`
             ORDER BY revenue DESC LIMIT 5",
            "i",[$pharmacy_id]
        );
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Control Center | <?php echo htmlspecialchars($pharmacy_name); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
:root{
    --bg:#080b11;
    --side:#0b0f16;
    --card:#111722;
    --card2:#151c27;
    --card3:#0d131c;
    --border:#273140;
    --text:#f2f5f9;
    --muted:#738094;
    --blue:#377dff;
    --cyan:#22c7ef;
    --green:#31cf98;
    --yellow:#f5bd45;
    --red:#ed617b;
    --purple:#9a82ff;
    --sidebar:248px;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}
body{overflow-x:hidden}
button,a{font:inherit}
a{text-decoration:none;color:inherit}

.app{min-height:100vh;background:
    radial-gradient(circle at 67% -10%,rgba(55,125,255,.12),transparent 32%),
    radial-gradient(circle at 88% 50%,rgba(34,199,239,.035),transparent 30%),
    var(--bg)}

.sidebar{
    position:fixed;left:0;top:0;bottom:0;width:var(--sidebar);
    background:linear-gradient(180deg,#0c1119,#090d14);
    border-right:1px solid var(--border);z-index:1000;padding:16px 13px;
    overflow:auto;
}
.brand{height:47px;display:flex;align-items:center;gap:10px;padding:0 8px;margin-bottom:14px}
.brand-mark{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#347cff,#2bc9ef);display:grid;place-items:center;box-shadow:0 8px 25px rgba(55,125,255,.2)}
.brand-mark i{font-size:15px;color:#fff}
.brand strong{display:block;font-size:12px;font-weight:800;letter-spacing:.3px}
.brand small{display:block;color:#5d697b;font-size:7px;text-transform:uppercase;letter-spacing:1.3px;margin-top:2px}

.side-caption{font-size:7px;text-transform:uppercase;letter-spacing:1.5px;color:#4e5b6e;font-weight:800;padding:9px 10px 5px}
.nav{display:flex;flex-direction:column;gap:2px}
.nav a{height:36px;border-radius:7px;color:#8995a6;display:flex;align-items:center;gap:10px;padding:0 10px;font-size:9px;font-weight:650;border:1px solid transparent}
.nav a i{width:16px;text-align:center;color:#5e6b7e;font-size:10px}
.nav a:hover{background:#131a25;color:#fff}
.nav a.active{background:#17243a;border-color:#28405f;color:#fff;box-shadow:inset 3px 0 var(--blue)}
.nav a.active i{color:var(--blue)}
.nav-badge{margin-left:auto;background:#26354a;color:#9fb3cf;border-radius:10px;padding:2px 6px;font-size:7px}
.nav a.danger{color:#e66b83}.nav a.danger i{color:#e66b83}
.separator{height:1px;background:var(--border);margin:12px 8px}

.sidebar-mini{
    margin:10px 7px 0;background:#0f1620;border:1px solid var(--border);
    border-radius:8px;padding:9px;
}
.mini-title{font-size:8px;font-weight:800;color:#cbd3df;margin-bottom:8px}
.mini-line{display:flex;justify-content:space-between;color:#667387;font-size:7px;margin:6px 0}
.mini-line b{color:#e7ebf0}
.mini-progress{height:3px;background:#202b3a;border-radius:4px;overflow:hidden}
.mini-progress span{display:block;height:100%;border-radius:4px;background:var(--blue)}

.side-user{position:absolute;left:13px;right:13px;bottom:12px;border-top:1px solid var(--border);padding-top:10px}
.user{display:flex;align-items:center;gap:8px;padding:8px;background:#101722;border:1px solid var(--border);border-radius:8px}
.avatar{width:28px;height:28px;border-radius:50%;background:#263347;display:grid;place-items:center;font-size:9px;font-weight:800}
.user-copy{min-width:0;flex:1}.user-copy b{display:block;font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-copy span{display:block;color:#647185;font-size:7px;margin-top:2px}
.user i{color:#536174;font-size:9px}

.main{margin-left:var(--sidebar);min-height:100vh}
.topbar{
    height:60px;border-bottom:1px solid var(--border);background:rgba(8,11,17,.92);
    display:flex;align-items:center;justify-content:space-between;padding:0 23px;
    position:sticky;top:0;z-index:900;backdrop-filter:blur(12px)
}
.top-left{display:flex;align-items:center;gap:10px}
.mobile-btn{display:none;width:31px;height:31px;border:1px solid var(--border);border-radius:7px;background:#111823;color:#fff}
.crumb{font-size:9px;color:#647084}.crumb b{color:#e7ecf2;font-size:10px}
.top-right{display:flex;align-items:center;gap:6px}
.search-mini{width:190px;height:31px;background:#101721;border:1px solid var(--border);border-radius:7px;color:#8290a3;font-size:8px;padding:0 10px}
.top-icon{width:31px;height:31px;background:#101721;border:1px solid var(--border);border-radius:7px;color:#8c99aa;display:grid;place-items:center}
.top-icon:hover{color:#fff}
.branch{height:31px;padding:0 9px;border:1px solid var(--border);background:#101721;border-radius:7px;color:#9aa6b6;font-size:8px;display:flex;align-items:center;gap:6px}
.branch i{color:var(--blue)}

.content{max-width:1580px;margin:auto;padding:22px 23px 35px}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:13px}
.page-head h1{font-size:20px;margin:0;font-weight:800;letter-spacing:-.5px}
.page-head p{margin:5px 0 0;color:#667286;font-size:8px}
.head-actions{display:flex;gap:6px}
.btn{height:31px;padding:0 10px;border-radius:7px;border:1px solid var(--border);background:#111823;color:#aab5c3;font-size:8px;font-weight:700}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn:hover{color:#fff}

.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:11px}
.kpi{position:relative;overflow:hidden;background:var(--card);border:1px solid var(--border);border-radius:9px;padding:12px 13px;min-height:93px}
.kpi:after{content:"";position:absolute;width:70px;height:70px;border-radius:50%;right:-25px;bottom:-35px;background:rgba(55,125,255,.08)}
.kpi-head{display:flex;align-items:center;justify-content:space-between;color:#6d7a8d;font-size:7px;text-transform:uppercase;letter-spacing:.8px;font-weight:800}
.kpi-icon{width:25px;height:25px;border-radius:7px;background:#192435;display:grid;place-items:center;color:var(--blue);font-size:9px}
.kpi-value{font-size:18px;font-weight:800;margin-top:7px;letter-spacing:-.4px}
.kpi-sub{font-size:7px;color:#667286;margin-top:3px}
.kpi.green .kpi-icon{color:var(--green)} .kpi.yellow .kpi-icon{color:var(--yellow)} .kpi.purple .kpi-icon{color:var(--purple)}

.core{display:grid;grid-template-columns:minmax(0,1.55fr) 285px;gap:11px}
.reference-panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.panel-top{height:43px;padding:0 13px;display:flex;align-items:center;justify-content:space-between;background:#0f151e;border-bottom:1px solid var(--border)}
.panel-top b{font-size:9px}.panel-top span{font-size:7px;color:#647084}
.hero{display:grid;grid-template-columns:39% 61%;min-height:254px}
.hero-info{padding:17px 15px;background:linear-gradient(145deg,#111925,#0d131c)}
.eyebrow{font-size:7px;text-transform:uppercase;letter-spacing:1.4px;color:var(--blue);font-weight:900}
.hero-info h2{font-size:21px;line-height:1.05;margin:7px 0 7px;font-weight:850}
.hero-info p{font-size:8px;line-height:1.55;color:#6d7a8c;max-width:310px;margin:0}
.hero-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:16px}
.hero-stat{background:#111a26;border:1px solid #222d3c;border-radius:7px;padding:8px}
.hero-stat b{display:block;font-size:11px}.hero-stat span{display:block;color:#626f82;font-size:6px;margin-top:2px}

.image-box{position:relative;min-height:254px;background:#151d28;overflow:hidden}
.image-box img{width:100%;height:100%;min-height:254px;object-fit:cover;display:block}
.image-empty{height:100%;min-height:254px;display:grid;place-items:center;background:
    linear-gradient(135deg,rgba(55,125,255,.06),transparent),#101721}
.image-empty-inner{text-align:center;color:#58667a}
.image-empty i{font-size:28px;display:block;margin-bottom:8px}
.image-empty b{display:block;color:#8d9aaa;font-size:9px}
.image-empty span{display:block;font-size:7px;margin-top:4px}
.image-label{position:absolute;left:12px;bottom:11px;padding:7px 9px;background:rgba(7,10,15,.8);border:1px solid rgba(255,255,255,.08);border-radius:6px;backdrop-filter:blur(8px)}
.image-label b{display:block;font-size:8px}.image-label span{display:block;color:#778396;font-size:6px;margin-top:2px}

.side-analytics{display:flex;flex-direction:column;gap:11px}
.donut-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:11px 12px;min-height:185px}
.donut-head{display:flex;justify-content:space-between;align-items:center}
.donut-head b{font-size:9px}.donut-head span{font-size:7px;color:#5e6b7d}
.donut-wrap{height:120px;position:relative;display:grid;place-items:center;margin-top:2px}
.donut-wrap canvas{max-width:120px!important;max-height:120px!important}
.donut-center{position:absolute;text-align:center;pointer-events:none}.donut-center b{display:block;font-size:15px}.donut-center span{display:block;font-size:6px;color:#657286;margin-top:2px}
.legend{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-top:3px}
.legend-item{font-size:6px;color:#6f7c8e;display:flex;align-items:center;gap:5px}
.dot{width:6px;height:6px;border-radius:50%;background:var(--blue);display:inline-block}.dot.cyan{background:var(--cyan)}.dot.green{background:var(--green)}.dot.yellow{background:var(--yellow)}.dot.purple{background:var(--purple)}

.four{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:11px}
.small-card{background:var(--card);border:1px solid var(--border);border-radius:9px;padding:11px;min-height:142px}
.small-head{display:flex;justify-content:space-between;align-items:center}.small-head b{font-size:8px}.small-head i{font-size:9px;color:var(--blue)}
.small-chart{height:72px;margin-top:5px}.small-foot{display:flex;justify-content:space-between;color:#657286;font-size:6px;margin-top:6px}.small-foot b{color:#dfe5ec;font-size:7px}

.lower{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(260px,.75fr);gap:11px;margin-top:11px}
.chart-panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.chart-body{height:285px;padding:10px 12px 12px}
.activity{padding:4px 12px 10px}
.activity-row{display:flex;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid rgba(39,49,64,.75)}
.activity-row:last-child{border-bottom:0}
.activity-icon{width:27px;height:27px;border-radius:7px;background:#192435;display:grid;place-items:center;color:var(--cyan);font-size:8px}
.activity-copy{flex:1;min-width:0}.activity-copy b{display:block;font-size:8px;color:#d4dbe4}.activity-copy span{display:block;color:#606e81;font-size:6px;margin-top:2px}.activity-value{font-size:8px;font-weight:800;color:#fff}

.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:11px}
.list-panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.list{padding:4px 12px 10px}
.list-row{display:flex;align-items:center;gap:9px;padding:9px 0;border-bottom:1px solid rgba(39,49,64,.7)}
.list-row:last-child{border-bottom:0}
.rank{width:22px;height:22px;border-radius:6px;background:#182332;display:grid;place-items:center;font-size:7px;color:#91a0b3;font-weight:800}
.list-copy{flex:1;min-width:0}.list-copy b{display:block;font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.list-copy span{display:block;font-size:6px;color:#627084;margin-top:2px}
.list-value{text-align:right}.list-value b{display:block;font-size:8px}.list-value span{display:block;color:#627084;font-size:6px;margin-top:2px}

.status-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(39,49,64,.7);font-size:7px;color:#7a8798}
.status-row:last-child{border-bottom:0}.status-left{display:flex;align-items:center;gap:7px}.status-dot{width:6px;height:6px;border-radius:50%;background:var(--green)}.status-dot.warn{background:var(--yellow)}.status-dot.red{background:var(--red)}

@media(max-width:1200px){
    .core{grid-template-columns:1fr}.side-analytics{display:grid;grid-template-columns:1fr 1fr}
    .four{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:850px){
    :root{--sidebar:0px}
    .sidebar{width:248px;transform:translateX(-100%);transition:.2s;box-shadow:20px 0 40px rgba(0,0,0,.5)}
    .sidebar.open{transform:translateX(0)}
    .mobile-btn{display:grid;place-items:center}
    .search-mini{display:none}
    .content{padding:16px 12px 30px}
    .kpis{grid-template-columns:repeat(2,1fr)}
    .hero{grid-template-columns:1fr}.image-box{border-top:1px solid var(--border)}
    .lower,.bottom-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
    .kpis,.four,.side-analytics{grid-template-columns:1fr}
    .page-head{flex-direction:column;align-items:flex-start;gap:10px}
    .head-actions{width:100%}.head-actions .btn{flex:1}
    .topbar{padding:0 12px}
    .hero-info h2{font-size:18px}
}
</style>
</head>

<body>
<div class="app">

<aside class="sidebar" id="sidebar">
    <a class="brand" href="admin_dashboard.php">
        <span class="brand-mark"><i class="fas fa-capsules"></i></span>
        <span>
            <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong>
            <small>POS ADMIN CONTROL</small>
        </span>
    </a>

    <div class="side-caption">Workspace</div>
    <nav class="nav">
        <a class="active" href="admin_dashboard.php"><i class="fas fa-chart-pie"></i>Dashboard</a>
        <?php if ($user_role === 'Admin'): ?>
        <a href="staff_management.php"><i class="fas fa-users"></i>Staff Management</a>
        <a href="manage_setup.php"><i class="fas fa-sliders"></i>System Setup</a>
        <?php endif; ?>
        <a href="sales_report.php"><i class="fas fa-chart-line"></i>Sales Analytics</a>
        <a href="today_transactions.php"><i class="fas fa-receipt"></i>Transactions <span class="nav-badge"><?php echo (int)$total_orders; ?></span></a>
        <a href="online_orders.php"><i class="fas fa-bag-shopping"></i>Online Orders</a>
        <a href="pharmacy_stock.php"><i class="fas fa-boxes-stacked"></i>Inventory</a>
        <a href="suppliers.php"><i class="fas fa-truck"></i>Suppliers</a>
        <a href="customers.php"><i class="fas fa-user-group"></i>Customers</a>
    </nav>

    <div class="separator"></div>
    <div class="side-caption">Network</div>
    <nav class="nav">
        <a href="#"><i class="fas fa-store"></i>Branches <span class="nav-badge"><?php echo (int)$branch_count; ?></span></a>
        <a href="#"><i class="fas fa-file-invoice-dollar"></i>Purchase Orders</a>
        <a href="#"><i class="fas fa-shield-halved"></i>Audit & Security</a>
        <a href="#"><i class="fas fa-gear"></i>Configuration</a>
    </nav>

    <div class="sidebar-mini">
        <div class="mini-title">Network Health</div>
        <div class="mini-line"><span>Database</span><b>Online</b></div>
        <div class="mini-progress"><span style="width:92%"></span></div>
        <div class="mini-line"><span>Active branches</span><b><?php echo (int)$branch_count; ?></b></div>
        <div class="mini-progress"><span style="width:78%"></span></div>
        <div class="mini-line"><span>Sales records</span><b><?php echo number_format((int)$total_orders); ?></b></div>
        <div class="mini-progress"><span style="width:66%"></span></div>
    </div>

    <div class="side-user">
        <div class="user">
            <div class="avatar"><?php echo strtoupper(substr($user_display_name ?: 'A',0,1)); ?></div>
            <div class="user-copy">
                <b><?php echo htmlspecialchars($user_display_name); ?></b>
                <span><?php echo htmlspecialchars($user_role); ?> Â· Administrator</span>
            </div>
            <i class="fas fa-ellipsis"></i>
        </div>
        <a class="nav-link" href="../logout.php" style="display:flex;align-items:center;gap:9px;color:#e66b83;font-size:8px;padding:8px 9px">
            <i class="fas fa-right-from-bracket"></i>Sign out
        </a>
    </div>
</aside>

<main class="main">
<header class="topbar">
    <div class="top-left">
        <button class="mobile-btn" id="mobileToggle"><i class="fas fa-bars"></i></button>
        <div class="crumb"><b>Admin Control Center</b> &nbsp;/&nbsp; Executive Overview</div>
    </div>
    <div class="top-right">
        <input class="search-mini" placeholder="Search dashboard..." type="search">
        <div class="branch"><i class="fas fa-layer-group"></i><?php echo (int)$branch_count; ?> Active</div>
        <button class="top-icon" title="Refresh" onclick="location.reload()"><i class="fas fa-rotate"></i></button>
        <button class="top-icon" title="Notifications"><i class="far fa-bell"></i></button>
        <button class="top-icon" title="Account"><i class="far fa-user"></i></button>
    </div>
</header>

<section class="content">

    <div class="page-head">
        <div>
            <h1>Business Intelligence</h1>
            <p>Centralized sales, customer, inventory and branch performance for <?php echo htmlspecialchars($pharmacy_name); ?>.</p>
        </div>
        <div class="head-actions">
            <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print / Export</button>
            <button class="btn primary" onclick="location.reload()"><i class="fas fa-arrows-rotate"></i> Sync Data</button>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi">
            <div class="kpi-head"><span>Total Revenue</span><span class="kpi-icon"><i class="fas fa-coins"></i></span></div>
            <div class="kpi-value">K<?php echo number_format((float)$total_sales,2); ?></div>
            <div class="kpi-sub">All recorded sales</div>
        </div>
        <div class="kpi green">
            <div class="kpi-head"><span>Transactions</span><span class="kpi-icon"><i class="fas fa-receipt"></i></span></div>
            <div class="kpi-value"><?php echo number_format((int)$total_orders); ?></div>
            <div class="kpi-sub">Completed POS sales</div>
        </div>
        <div class="kpi yellow">
            <div class="kpi-head"><span>Average Ticket</span><span class="kpi-icon"><i class="fas fa-calculator"></i></span></div>
            <div class="kpi-value">K<?php echo number_format((float)$avg,2); ?></div>
            <div class="kpi-sub">Revenue per transaction</div>
        </div>
        <div class="kpi purple">
            <div class="kpi-head"><span>Active Branches</span><span class="kpi-icon"><i class="fas fa-store"></i></span></div>
            <div class="kpi-value"><?php echo (int)$branch_count; ?></div>
            <div class="kpi-sub">Operational locations</div>
        </div>
    </div>

    <div class="core">

        <section class="reference-panel">
            <div class="panel-top">
                <b>Central POS Intelligence</b>
                <span><?php echo date('d M Y'); ?> Â· Live database view</span>
            </div>

            <div class="hero">
                <div class="hero-info">
                    <div class="eyebrow">Executive overview</div>
                    <h2><?php echo htmlspecialchars($pharmacy_name); ?></h2>
                    <p>
                        A single control surface for monitoring the pharmacy network,
                        sales performance, transaction activity, branches and payment mix.
                    </p>

                    <div class="hero-stats">
                        <div class="hero-stat">
                            <b>K<?php echo number_format((float)$total_sales,0); ?></b>
                            <span>Revenue</span>
                        </div>
                        <div class="hero-stat">
                            <b><?php echo number_format((int)$total_orders); ?></b>
                            <span>Transactions</span>
                        </div>
                        <div class="hero-stat">
                            <b><?php echo (int)$branch_count; ?></b>
                            <span>Branches</span>
                        </div>
                        <div class="hero-stat">
                            <b>K<?php echo number_format((float)$avg,0); ?></b>
                            <span>Avg. ticket</span>
                        </div>
                    </div>
                </div>

                <div class="image-box">
                    <?php
                    /* IMAGE SLOT
                       Put your own admin visual here:
                       /uploads/admin/admin_dashboard.jpg
                       From dashboard/admin_dashboard.php the URL is:
                       ../uploads/admin/admin_dashboard.jpg
                    */
                    $admin_image_url = '../uploads/admin/admin_dashboard.jpg';
                    $admin_image_file = dirname(__DIR__) . '/uploads/admin/admin_dashboard.jpg';
                    ?>
                    <?php if (is_file($admin_image_file)): ?>
                        <img src="<?php echo htmlspecialchars($admin_image_url); ?>" alt="EchoTech POS analytics">
                        <div class="image-label">
                            <b>POS Command View</b>
                            <span>Network performance visual</span>
                        </div>
                    <?php else: ?>
                        <div class="image-empty">
                            <div class="image-empty-inner">
                                <i class="fas fa-image"></i>
                                <b>ADMIN VISUAL AREA</b>
                                <span>Add admin_dashboard.jpg to<br>/uploads/admin/</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <aside class="side-analytics">

            <div class="donut-card">
                <div class="donut-head"><b>Payment Mix</b><span>Transactions</span></div>
                <div class="donut-wrap">
                    <canvas id="paymentChart"></canvas>
                    <div class="donut-center"><b><?php echo number_format((int)$total_orders); ?></b><span>Sales</span></div>
                </div>
                <div class="legend" id="paymentLegend"></div>
            </div>

            <div class="donut-card">
                <div class="donut-head"><b>Branch Share</b><span>Revenue</span></div>
                <div class="donut-wrap">
                    <canvas id="branchDonut"></canvas>
                    <div class="donut-center"><b><?php echo (int)$branch_count; ?></b><span>Branches</span></div>
                </div>
                <div class="legend" id="branchLegend"></div>
            </div>

        </aside>
    </div>

    <div class="four">

        <div class="small-card">
            <div class="small-head"><b>Revenue Trend</b><i class="fas fa-chart-line"></i></div>
            <div class="small-chart"><canvas id="sparkRevenue"></canvas></div>
            <div class="small-foot"><span>Sales movement</span><b>K<?php echo number_format((float)$total_sales,0); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Transaction Trend</b><i class="fas fa-arrow-trend-up"></i></div>
            <div class="small-chart"><canvas id="sparkTransactions"></canvas></div>
            <div class="small-foot"><span>Recorded sales</span><b><?php echo number_format((int)$total_orders); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Category Mix</b><i class="fas fa-layer-group"></i></div>
            <div class="small-chart"><canvas id="categoryChart"></canvas></div>
            <div class="small-foot"><span>Inventory categories</span><b><?php echo number_format((int)$category_total); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Inventory Health</b><i class="fas fa-boxes-stacked"></i></div>
            <div class="small-chart"><canvas id="stockChart"></canvas></div>
            <div class="small-foot"><span>Available items</span><b><?php echo number_format((int)$stock_total); ?></b></div>
        </div>

    </div>

    <div class="lower">

        <section class="chart-panel">
            <div class="panel-top">
                <div>
                    <b>Sales Performance</b>
                    <span style="display:block;margin-top:3px">Revenue trend across the pharmacy network</span>
                </div>
                <span>Weekly view</span>
            </div>
            <div class="chart-body"><canvas id="salesChart"></canvas></div>
        </section>

        <section class="chart-panel">
            <div class="panel-top">
                <div>
                    <b>System Activity</b>
                    <span style="display:block;margin-top:3px">Operational intelligence</span>
                </div>
                <span>LIVE</span>
            </div>
            <div class="activity">
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="activity-copy"><b>Revenue consolidated</b><span>All active branches</span></div>
                    <div class="activity-value">K<?php echo number_format((float)$total_sales,0); ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-cart-shopping"></i></div>
                    <div class="activity-copy"><b>Transactions recorded</b><span>POS sales in database</span></div>
                    <div class="activity-value"><?php echo number_format((int)$total_orders); ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-store"></i></div>
                    <div class="activity-copy"><b>Active locations</b><span>Operational branches</span></div>
                    <div class="activity-value"><?php echo (int)$branch_count; ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-database"></i></div>
                    <div class="activity-copy"><b>Database connection</b><span>EchoTech POS data source</span></div>
                    <div class="activity-value" style="color:var(--green)">ONLINE</div>
                </div>
            </div>
        </section>
    </div>

    <div class="bottom-grid">

        <section class="list-panel">
            <div class="panel-top">
                <div><b>Top Products</b><span style="display:block;margin-top:3px">Highest sales value</span></div>
                <span>TOP 5</span>
            </div>
            <div class="list">
                <?php if (!empty($top_products)): ?>
                    <?php foreach ($top_products as $i => $product): ?>
                    <div class="list-row">
                        <div class="rank"><?php echo $i + 1; ?></div>
                        <div class="list-copy">
                            <b><?php echo htmlspecialchars($product['name']); ?></b>
                            <span><?php echo number_format((int)$product['qty']); ?> units sold</span>
                        </div>
                        <div class="list-value">
                            <b>K<?php echo number_format((float)$product['revenue'],2); ?></b>
                            <span>revenue</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-row"><div class="list-copy"><b>No product sales data</b><span>Sales product details will appear here.</span></div></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="list-panel">
            <div class="panel-top">
                <div><b>Network Status</b><span style="display:block;margin-top:3px">Core POS services</span></div>
                <span>HEALTH</span>
            </div>
            <div class="list" style="padding-top:7px">
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Database</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Sales processing</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Inventory</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Branch network</div><b style="color:var(--green)"><?php echo (int)$branch_count; ?> Active</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot warn"></span>Review required</div><b style="color:var(--yellow)"><?php echo number_format((int)$low_stock_count); ?> low stock</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot red"></span>Expired products</div><b style="color:var(--red)"><?php echo number_format((int)$expired_count); ?></b></div>
            </div>
        </section>

    </div>

</section>
</main>
</div>

<script>
const paymentLabels = <?php echo json_encode($p_labels, JSON_UNESCAPED_UNICODE); ?>;
const paymentData = <?php echo json_encode($p_counts); ?>;
const branchLabels = <?php echo json_encode($b_names, JSON_UNESCAPED_UNICODE); ?>;
const branchData = <?php echo json_encode($b_revenues); ?>;
const trendLabels = <?php echo json_encode($trend_labels, JSON_UNESCAPED_UNICODE); ?>;
const trendRevenue = <?php echo json_encode($trend_revenue); ?>;
const trendTransactions = <?php echo json_encode($trend_transactions); ?>;
const categoryLabels = <?php echo json_encode($category_labels, JSON_UNESCAPED_UNICODE); ?>;
const categoryData = <?php echo json_encode($category_data); ?>;
const stockLabels = <?php echo json_encode($stock_labels, JSON_UNESCAPED_UNICODE); ?>;
const stockData = <?php echo json_encode($stock_data); ?>;

Chart.defaults.font.family='Inter,Arial,sans-serif';
Chart.defaults.color='#728095';
Chart.defaults.animation.duration=650;

const palette=['#377dff','#22c7ef','#31cf98','#f5bd45','#9a82ff','#ed617b','#7b8da8'];

function donut(id,labels,data,legendId){
    const el=document.getElementById(id); if(!el)return;
    const safeLabels=labels.length?labels:['No data'];
    const safeData=data.length?data:[1];
    new Chart(el,{
        type:'doughnut',
        data:{labels:safeLabels,datasets:[{
            data:safeData,
            backgroundColor:safeLabels.map((_,i)=>palette[i%palette.length]),
            borderWidth:0,
            spacing:2
        }]},
        options:{responsive:true,maintainAspectRatio:false,cutout:'73%',
            plugins:{legend:{display:false},tooltip:{backgroundColor:'#090e16',borderColor:'#2a3647',borderWidth:1}}}
    });
    const box=document.getElementById(legendId);
    if(box){
        box.innerHTML=safeLabels.slice(0,6).map((l,i)=>
            `<div class="legend-item"><span class="dot" style="background:${palette[i%palette.length]}"></span>${String(l).replace(/</g,'&lt;')}</div>`
        ).join('');
    }
}

donut('paymentChart',paymentLabels,paymentData,'paymentLegend');
donut('branchDonut',branchLabels,branchData,'branchLegend');

function spark(id,data){
    const el=document.getElementById(id); if(!el)return;
    const values=data.length?data:[0];
    new Chart(el,{type:'line',
        data:{labels:values.map((_,i)=>i+1),datasets:[{data:values,borderColor:'#377dff',backgroundColor:'rgba(55,125,255,.08)',fill:true,tension:.42,pointRadius:0,borderWidth:2}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}},
            scales:{x:{display:false},y:{display:false}},elements:{line:{capBezierPoints:true}}}
    });
}
spark('sparkRevenue',trendRevenue);
spark('sparkTransactions',trendTransactions);

const cat=document.getElementById('categoryChart');
if(cat){
    new Chart(cat,{type:'bar',data:{labels:categoryLabels.length?categoryLabels:['No data'],datasets:[{data:categoryData.length?categoryData:[0],backgroundColor:'#31cf98',borderRadius:4,maxBarThickness:16}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:true}},scales:{x:{display:false},y:{display:false}}}});
}

const stock=document.getElementById('stockChart');
if(stock){
    new Chart(stock,{type:'doughnut',data:{labels:stockLabels,datasets:[{data:stockData,backgroundColor:['#31cf98','#f5bd45','#ed617b'],borderWidth:0}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{display:false}}}});
}

const sales=document.getElementById('salesChart');
if(sales){
    new Chart(sales,{type:'line',data:{
        labels:trendLabels.length?trendLabels:['No data'],
        datasets:[
            {label:'Revenue',data:trendRevenue.length?trendRevenue:[0],borderColor:'#377dff',backgroundColor:'rgba(55,125,255,.09)',fill:true,tension:.4,pointRadius:3,pointHoverRadius:5,borderWidth:2},
            {label:'Transactions',data:trendTransactions.length?trendTransactions:[0],borderColor:'#f5bd45',backgroundColor:'transparent',tension:.4,pointRadius:2,pointHoverRadius:4,borderWidth:2,yAxisID:'y1'}
        ]},
        options:{responsive:true,maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{labels:{color:'#8996a8',font:{size:8},boxWidth:8,boxHeight:8}},tooltip:{backgroundColor:'#090e16',borderColor:'#2a3647',borderWidth:1}},
            scales:{
                x:{grid:{display:false},ticks:{color:'#69778a',font:{size:7}},border:{display:false}},
                y:{beginAtZero:true,grid:{color:'rgba(115,128,148,.12)'},ticks:{color:'#69778a',font:{size:7},callback:v=>'K'+v},border:{display:false}},
                y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{color:'#69778a',font:{size:7}},border:{display:false}}
            }
        }
    });
}

document.getElementById('mobileToggle')?.addEventListener('click',()=>{
    document.getElementById('sidebar')?.classList.toggle('open');
});
document.querySelectorAll('.sidebar a').forEach(a=>{
    a.addEventListener('click',()=>{
        if(window.innerWidth<=850)document.getElementById('sidebar')?.classList.remove('open');
    });
});
</script>
</body>
</html>
