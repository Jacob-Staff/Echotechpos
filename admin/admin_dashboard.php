<?php
session_start(); // Ensure session is started for pharmacy_id
require_once '../includes/auth.php'; 
require_once '../includes/conn.php';

// 1. PROTECTION: Ensure only Admin or Manager can access global stats
require_admin();

// Get user info and tenant ID from session
$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;

// Redirect if session is lost to prevent SQL errors
if ($pharmacy_id == 0) {
    header("Location: ../index.php?error=session_expired");
    exit();
}

// --- LOGIC: Fetch Pharmacy Name ---
$pharmacy_name = "PHARMA-JACOBS";
$p_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$p_stmt->bind_param("i", $pharmacy_id);
$p_stmt->execute();
$p_res = $p_stmt->get_result();
if($p_row = $p_res->fetch_assoc()){
    $pharmacy_name = $p_row['name'];
}

// --- LOGIC: Fetch Global Stats ---
$total_sales = 0;
$total_orders = 0;
$branch_count = 0;

$res1 = $conn->prepare("SELECT SUM(total_amount) as total FROM sales WHERE pharmacy_id = ?");
$res1->bind_param("i", $pharmacy_id);
$res1->execute();
$row1 = $res1->get_result()->fetch_assoc();
$total_sales = $row1['total'] ?? 0;

$res2 = $conn->prepare("SELECT COUNT(id) as total FROM sales WHERE pharmacy_id = ?");
$res2->bind_param("i", $pharmacy_id);
$res2->execute();
$row2 = $res2->get_result()->fetch_assoc();
$total_orders = $row2['total'] ?? 0;

$res3 = $conn->prepare("SELECT COUNT(id) as total FROM branches WHERE pharmacy_id = ? AND is_active = 1");
$res3->bind_param("i", $pharmacy_id);
$res3->execute();
$row3 = $res3->get_result()->fetch_assoc();
$branch_count = $row3['total'] ?? 0;

// 2. Bar Chart Data
$b_names = [];
$b_revenues = [];
$branch_data_query = $conn->prepare("SELECT b.branch_name, IFNULL(SUM(s.total_amount), 0) as rev 
                                     FROM branches b 
                                     LEFT JOIN sales s ON b.id = s.branch_id 
                                     WHERE b.pharmacy_id = ? AND b.is_active = 1 
                                     GROUP BY b.id");
$branch_data_query->bind_param("i", $pharmacy_id);
$branch_data_query->execute();
$branch_res = $branch_data_query->get_result();
while($row = $branch_res->fetch_assoc()){
    $b_names[] = $row['branch_name'];
    $b_revenues[] = (float)$row['rev'];
}

// 3. Payment Doughnut Data
$p_labels = [];
$p_counts = [];
$pay_query = $conn->prepare("SELECT payment_method, COUNT(*) as count FROM sales WHERE pharmacy_id = ? GROUP BY payment_method");
$pay_query->bind_param("i", $pharmacy_id);
$pay_query->execute();
$pay_res = $pay_query->get_result();

if($pay_res && $pay_res->num_rows > 0){
    while($row = $pay_res->fetch_assoc()){
        $p_labels[] = $row['payment_method'] ?: 'Other';
        $p_counts[] = (int)$row['count'];
    }
} else {
    $p_labels = ['No Data']; $p_counts = [1];
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Control Center | <?php echo htmlspecialchars($pharmacy_name); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--bg:#090d14;--panel:#101722;--panel2:#141c28;--border:#263244;--text:#f4f7fb;--muted:#8e9bad;--blue:#4d8dff;--cyan:#36c7ff;--green:#36d399;--yellow:#f6c85f;--red:#ff6577;--sidebar:258px;--top:72px}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}body{overflow-x:hidden}a{text-decoration:none}
.shell{min-height:100vh;background:radial-gradient(circle at 72% -10%,rgba(77,141,255,.09),transparent 31%),var(--bg)}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);background:#0c121c;border-right:1px solid var(--border);z-index:1050;display:flex;flex-direction:column;padding:20px 14px 16px}.brand{display:flex;align-items:center;gap:11px;padding:7px 11px 23px;color:#fff}.brand-mark{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:linear-gradient(145deg,#4d8dff,#36c7ff);box-shadow:0 8px 25px rgba(77,141,255,.22)}.brand-copy strong{display:block;font-size:15px;letter-spacing:.4px}.brand-copy span{display:block;color:#69778a;font-size:9px;margin-top:2px;text-transform:uppercase;letter-spacing:1.4px}.nav-title{color:#647286;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:0 13px 8px}.nav{display:flex;flex-direction:column;gap:3px;overflow:auto}.nav a{min-height:43px;display:flex;align-items:center;gap:12px;padding:0 13px;color:#9eabba;border:1px solid transparent;border-radius:9px;font-size:12px;font-weight:650;transition:.18s}.nav a i{width:18px;text-align:center;color:#6f7d90;font-size:13px}.nav a:hover{color:#fff;background:#141d2a;border-color:#202d40}.nav a.active{color:#fff;background:linear-gradient(90deg,rgba(77,141,255,.18),rgba(77,141,255,.06));border-color:rgba(77,141,255,.24);box-shadow:inset 3px 0 0 var(--blue)}.nav a.active i{color:var(--blue)}.divider{height:1px;background:var(--border);margin:15px 8px}.bottom{margin-top:auto}.profile{display:flex;align-items:center;gap:10px;padding:11px;border:1px solid var(--border);background:#111925;border-radius:12px;margin-bottom:9px}.avatar{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#202c3d;color:#dce6f3;font-size:12px;font-weight:800}.profile-copy{min-width:0;flex:1}.profile-copy strong{display:block;color:#fff;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-copy span{color:var(--muted);font-size:9px}.logout{color:var(--red)!important}.logout i{color:var(--red)!important}
.main{margin-left:var(--sidebar);min-height:100vh}.topbar{height:var(--top);position:sticky;top:0;z-index:900;display:flex;align-items:center;justify-content:space-between;padding:0 28px;background:rgba(9,13,20,.94);border-bottom:1px solid var(--border);backdrop-filter:blur(14px)}.top-left{display:flex;align-items:center;gap:14px}.mobile{display:none;border:1px solid var(--border);background:#111925;color:#fff;width:38px;height:38px;border-radius:9px}.crumb{color:#68778b;font-size:10px}.crumb strong{color:#fff;font-size:12px}.actions{display:flex;align-items:center;gap:8px}.icon-btn{width:38px;height:38px;border-radius:9px;border:1px solid var(--border);background:#111925;color:#9eabba;display:grid;place-items:center;position:relative}.icon-btn:hover{color:#fff}.dot{position:absolute;top:6px;right:6px;width:7px;height:7px;border-radius:50%;background:var(--red);box-shadow:0 0 0 2px #111925}.branch-chip{display:flex;align-items:center;gap:7px;height:38px;padding:0 12px;border:1px solid var(--border);border-radius:9px;background:#111925;color:#b6c1cf;font-size:10px;font-weight:700}.branch-chip i{color:var(--blue)}
.content{padding:28px;max-width:1700px;margin:auto}.heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:24px}.eyebrow{color:var(--blue);text-transform:uppercase;letter-spacing:1.5px;font-size:9px;font-weight:850;margin-bottom:7px}.heading h1{margin:0;font-size:27px;font-weight:800;letter-spacing:-.7px}.heading p{margin:6px 0 0;color:var(--muted);font-size:11px}.head-actions{display:flex;gap:8px}.btn-admin{border:1px solid var(--border);background:#111925;color:#d8e0eb;border-radius:9px;height:38px;padding:0 14px;font-size:10px;font-weight:750}.btn-admin:hover{background:#182231;color:#fff}.btn-admin.primary{border-color:rgba(77,141,255,.35);background:#397cf0;color:#fff}
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.metric{position:relative;overflow:hidden;min-height:142px;padding:18px;border:1px solid var(--border);background:linear-gradient(145deg,#111925,#0f1621);border-radius:13px}.metric:after{content:"";position:absolute;width:120px;height:120px;border-radius:50%;right:-52px;bottom:-62px;background:rgba(77,141,255,.07)}.metric-head{display:flex;align-items:center;justify-content:space-between}.label{color:#8c99aa;font-size:9px;text-transform:uppercase;letter-spacing:1px;font-weight:800}.metric-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;color:var(--blue);background:rgba(77,141,255,.10)}.value{font-size:25px;font-weight:800;margin-top:17px;letter-spacing:-.7px}.foot{display:flex;align-items:center;gap:6px;margin-top:8px;color:#68778b;font-size:9px}.positive{color:var(--green)}.warning{color:var(--yellow)}
.grid2{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.8fr);gap:16px;margin-bottom:16px}.panel{border:1px solid var(--border);background:linear-gradient(145deg,#101722,#0e151f);border-radius:13px;overflow:hidden}.panel-head{min-height:62px;padding:15px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}.panel-title{font-size:13px;font-weight:800;color:#fff}.panel-sub{margin-top:4px;color:#68778b;font-size:9px}.tools{display:flex;gap:6px}.period{border:1px solid var(--border);background:#111925;color:#8794a6;border-radius:7px;height:29px;padding:0 9px;font-size:9px;font-weight:750}.period.active{background:#1d3761;border-color:#315a9a;color:#fff}.chart{height:350px;padding:15px 18px 18px}.donut{height:350px;padding:15px 18px;display:flex;flex-direction:column}.donut-wrap{flex:1;min-height:0;display:grid;place-items:center}.donut-wrap canvas{max-height:235px}.legend{display:grid;grid-template-columns:1fr 1fr;gap:7px 12px}.legend-item{display:flex;align-items:center;gap:7px;color:#8d9aab;font-size:9px}.legend-dot{width:7px;height:7px;border-radius:50%}
.lower{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.8fr);gap:16px}.table-wrap{overflow:auto}.table-admin{width:100%;border-collapse:collapse}.table-admin th{padding:12px 16px;color:#657286;font-size:8px;text-transform:uppercase;letter-spacing:1px;font-weight:800;border-bottom:1px solid var(--border);white-space:nowrap}.table-admin td{padding:13px 16px;color:#b6c1cf;font-size:10px;border-bottom:1px solid rgba(38,50,68,.7);white-space:nowrap}.table-admin tr:last-child td{border-bottom:0}.table-admin tbody tr:hover{background:rgba(255,255,255,.018)}.status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:20px;font-size:8px;font-weight:800}.completed{background:rgba(54,211,153,.11);color:var(--green)}.pending{background:rgba(246,200,95,.11);color:var(--yellow)}.activity-list{padding:8px 18px 15px}.activity{display:flex;gap:11px;padding:13px 0;border-bottom:1px solid rgba(38,50,68,.7)}.activity:last-child{border-bottom:0}.activity-icon{width:30px;height:30px;flex:0 0 30px;border-radius:8px;display:grid;place-items:center;background:#182536;color:var(--blue);font-size:10px}.activity-copy{min-width:0;flex:1}.activity-copy strong{display:block;color:#dce4ee;font-size:10px}.activity-copy span{display:block;margin-top:3px;color:#657286;font-size:9px}.activity-amount{color:#fff;font-size:9px;font-weight:800;align-self:center}.empty{padding:45px 20px;text-align:center;color:#647286;font-size:10px}
@media(max-width:1200px){.metrics{grid-template-columns:repeat(2,1fr)}.grid2,.lower{grid-template-columns:1fr}}@media(max-width:900px){:root{--sidebar:0px}.sidebar{width:258px;transform:translateX(-100%);transition:transform .22s;box-shadow:20px 0 50px rgba(0,0,0,.4)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.mobile{display:grid;place-items:center}.content{padding:20px 15px}.topbar{padding:0 15px}.branch-chip{display:none}.heading{align-items:flex-start;flex-direction:column}}@media(max-width:600px){.metrics{grid-template-columns:1fr}.head-actions{width:100%}.head-actions .btn-admin{flex:1}.topbar .icon-btn:nth-child(1){display:none}.chart,.donut{height:310px}}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1040}.overlay.show{display:block}
</style></head>
<body><div class="shell">
<aside class="sidebar" id="sidebar">
<a href="admin_dashboard.php" class="brand"><span class="brand-mark"><i class="fas fa-capsules"></i></span><span class="brand-copy"><strong><?php echo htmlspecialchars($pharmacy_name); ?></strong><span>Admin Control</span></span></a>
<div class="nav-title">Overview</div><nav class="nav">
<a class="active" href="admin_dashboard.php"><i class="fas fa-chart-line"></i><span>Dashboard</span></a>
<?php if ($user_role === 'Admin'): ?><a href="staff_management.php"><i class="fas fa-users"></i><span>Staff Management</span></a><a href="manage_setup.php"><i class="fas fa-sliders"></i><span>System Setup</span></a><?php endif; ?>
<div class="divider"></div><div class="nav-title">Branches</div>
<?php $stmt_b=$conn->prepare("SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name ASC");$stmt_b->bind_param("i",$pharmacy_id);$stmt_b->execute();$b_res=$stmt_b->get_result();if($b_res&&$b_res->num_rows):while($b=$b_res->fetch_assoc()): ?><a href="view_branch.php?id=<?php echo (int)$b['id']; ?>"><i class="fas fa-store"></i><span><?php echo htmlspecialchars($b['branch_name']); ?></span></a><?php endwhile;else:?><a href="#" onclick="return false" style="opacity:.55"><i class="fas fa-store-slash"></i><span>No active branches</span></a><?php endif; ?>
</nav><div class="bottom"><div class="profile"><div class="avatar"><?php echo strtoupper(substr($user_display_name?:'A',0,1)); ?></div><div class="profile-copy"><strong><?php echo htmlspecialchars($user_display_name); ?></strong><span><?php echo htmlspecialchars($user_role); ?></span></div><i class="fas fa-ellipsis" style="color:#667489;font-size:10px"></i></div><a class="nav logout" href="../logout.php"><i class="fas fa-right-from-bracket"></i><span>Sign Out</span></a></div>
</aside><div class="overlay" id="overlay"></div>
<main class="main"><header class="topbar"><div class="top-left"><button class="mobile" id="mobile" type="button"><i class="fas fa-bars"></i></button><div class="crumb"><strong>Admin Dashboard</strong><span> / Global Overview</span></div></div><div class="actions"><div class="branch-chip"><i class="fas fa-layer-group"></i><?php echo (int)$branch_count; ?> Active Branch<?php echo $branch_count==1?'':'es'; ?></div><button class="icon-btn" onclick="location.reload()" title="Refresh"><i class="fas fa-rotate"></i></button><button class="icon-btn" title="Notifications"><i class="far fa-bell"></i><span class="dot"></span></button><div class="icon-btn" title="User"><i class="far fa-user"></i></div></div></header>
<section class="content">
<div class="heading"><div><div class="eyebrow">Management Overview</div><h1>Global Dashboard</h1><p>Network performance across <strong style="color:#dce4ee"><?php echo (int)$branch_count; ?></strong> active branch<?php echo $branch_count==1?'':'es'; ?>.</p></div><div class="head-actions"><button class="btn-admin" onclick="window.print()"><i class="fas fa-print me-2"></i>Export</button><button class="btn-admin primary" onclick="location.reload()"><i class="fas fa-sync me-2"></i>Sync Data</button></div></div>
<div class="metrics">
<div class="metric"><div class="metric-head"><span class="label">Total Revenue</span><span class="metric-icon"><i class="fas fa-coins"></i></span></div><div class="value">K<?php echo number_format((float)$total_sales,2); ?></div><div class="foot"><span class="positive"><i class="fas fa-arrow-trend-up"></i></span>Combined branch sales</div></div>
<div class="metric"><div class="metric-head"><span class="label">Transactions</span><span class="metric-icon"><i class="fas fa-receipt"></i></span></div><div class="value"><?php echo number_format((int)$total_orders); ?></div><div class="foot"><span class="positive"><i class="fas fa-chart-line"></i></span>Network sales volume</div></div>
<div class="metric"><div class="metric-head"><span class="label">Active Branches</span><span class="metric-icon"><i class="fas fa-store"></i></span></div><div class="value"><?php echo (int)$branch_count; ?></div><div class="foot"><span class="positive"><i class="fas fa-circle-check"></i></span>Currently operational</div></div>
<div class="metric"><div class="metric-head"><span class="label">Avg. Transaction</span><span class="metric-icon"><i class="fas fa-calculator"></i></span></div><div class="value">K<?php $avg_transaction=$total_orders>0?((float)$total_sales/(int)$total_orders):0;echo number_format($avg_transaction,2); ?></div><div class="foot"><span class="warning"><i class="fas fa-wallet"></i></span>Revenue per transaction</div></div>
</div>
<div class="grid2"><div class="panel"><div class="panel-head"><div><div class="panel-title">Branch Performance</div><div class="panel-sub">Revenue comparison by active location</div></div><div class="tools"><button class="period active">All Time</button><button class="period">Monthly</button></div></div><div class="chart"><canvas id="branchChart"></canvas></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title">Payment Mix</div><div class="panel-sub">Transactions by payment method</div></div></div><div class="donut"><div class="donut-wrap"><canvas id="paymentChart"></canvas></div><div class="legend" id="paymentLegend"></div></div></div></div>
<div class="lower"><div class="panel"><div class="panel-head"><div><div class="panel-title">Branch Revenue Register</div><div class="panel-sub">Current network performance snapshot</div></div></div><div class="table-wrap"><?php if(count($b_names)>0): ?><table class="table-admin"><thead><tr><th>Branch</th><th>Revenue</th><th>Share</th><th>Status</th></tr></thead><tbody><?php foreach($b_names as $i=>$bn):$rev=(float)($b_revenues[$i]??0);$share=$total_sales>0?($rev/(float)$total_sales)*100:0; ?><tr><td><strong style="color:#fff"><i class="fas fa-store me-2" style="color:#4d8dff"></i><?php echo htmlspecialchars($bn); ?></strong></td><td>K<?php echo number_format($rev,2); ?></td><td><?php echo number_format($share,1); ?>%</td><td><?php if($rev>0): ?><span class="status completed"><i class="fas fa-circle-check me-1"></i>Active</span><?php else: ?><span class="status pending">No Sales</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty"><i class="fas fa-store-slash d-block mb-2" style="font-size:20px"></i>No active branches found.</div><?php endif; ?></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title">System Activity</div><div class="panel-sub">Latest administrative indicators</div></div></div><div class="activity-list"><div class="activity"><div class="activity-icon"><i class="fas fa-store"></i></div><div class="activity-copy"><strong>Branch network online</strong><span><?php echo (int)$branch_count; ?> active location(s)</span></div><div class="activity-amount positive">LIVE</div></div><div class="activity"><div class="activity-icon"><i class="fas fa-chart-column"></i></div><div class="activity-copy"><strong>Sales volume recorded</strong><span><?php echo number_format((int)$total_orders); ?> transaction(s)</span></div><div class="activity-amount"><?php echo number_format((int)$total_orders); ?></div></div><div class="activity"><div class="activity-icon"><i class="fas fa-money-bill-wave"></i></div><div class="activity-copy"><strong>Revenue consolidated</strong><span>All active branch sales</span></div><div class="activity-amount">K<?php echo number_format((float)$total_sales,0); ?></div></div><div class="activity"><div class="activity-icon"><i class="fas fa-shield-halved"></i></div><div class="activity-copy"><strong>Admin session</strong><span><?php echo htmlspecialchars($user_display_name); ?> Â· <?php echo htmlspecialchars($user_role); ?></span></div><div class="activity-amount positive">SECURE</div></div></div></div></div>
</section></main></div>
<script>
(function(){const branchLabels=<?php echo json_encode($b_names,JSON_UNESCAPED_UNICODE); ?>,branchSales=<?php echo json_encode($b_revenues); ?>,payLabels=<?php echo json_encode($p_labels,JSON_UNESCAPED_UNICODE); ?>,payCounts=<?php echo json_encode($p_counts); ?>;Chart.defaults.font.family='Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';Chart.defaults.color='#8290a3';const grid='rgba(112,130,155,.13)';
new Chart(document.getElementById('branchChart'),{type:'bar',data:{labels:branchLabels.length?branchLabels:['No Data'],datasets:[{label:'Revenue (K)',data:branchSales.length?branchSales:[0],backgroundColor:'#4d8dff',borderRadius:6,borderSkipped:false,maxBarThickness:42}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{backgroundColor:'#0b111a',borderColor:'#263244',borderWidth:1,titleColor:'#fff',bodyColor:'#b9c4d2'}},scales:{y:{beginAtZero:true,grid:{color:grid},border:{display:false},ticks:{color:'#8290a3',font:{size:9},callback:v=>'K'+v}},x:{grid:{display:false},border:{display:false},ticks:{color:'#8290a3',font:{size:9,weight:'600'}}}}}});
const colors=['#4d8dff','#36d399','#f6c85f','#ff6577','#a78bfa','#36c7ff'];new Chart(document.getElementById('paymentChart'),{type:'doughnut',data:{labels:payLabels.length?payLabels:['No Data'],datasets:[{data:payCounts.length?payCounts:[1],backgroundColor:colors,borderWidth:2,borderColor:'#101722',hoverOffset:5}]},options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{backgroundColor:'#0b111a',borderColor:'#263244',borderWidth:1,titleColor:'#fff',bodyColor:'#b9c4d2'}}}});
const legend=document.getElementById('paymentLegend'),labs=payLabels.length?payLabels:['No Data'],vals=payCounts.length?payCounts:[1];labs.forEach((x,i)=>{const d=document.createElement('div');d.className='legend-item';d.innerHTML='<span class="legend-dot" style="background:'+colors[i%colors.length]+'"></span><span></span>';d.querySelector('span:last-child').textContent=x+' ('+(vals[i]||0)+')';legend.appendChild(d)});
const side=document.getElementById('sidebar'),over=document.getElementById('overlay');document.getElementById('mobile')?.addEventListener('click',()=>{side.classList.toggle('open');over.classList.toggle('show')});over?.addEventListener('click',()=>{side.classList.remove('open');over.classList.remove('show')});})();
</script></body></html>
