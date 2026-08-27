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
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Analytics | <?php echo htmlspecialchars($pharmacy_name); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
    --bg:#090c13;
    --sidebar:#0b1018;
    --panel:#111722;
    --panel2:#151d29;
    --line:#273140;
    --text:#f4f7fb;
    --muted:#7d8999;
    --blue:#4f8cff;
    --cyan:#36c9ff;
    --green:#35d39a;
    --yellow:#f4c95d;
    --pink:#e96891;
    --purple:#9c83ff;
    --sidebar-w:245px;
}

*{box-sizing:border-box}
html,body{
    margin:0;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Arial,Helvetica,sans-serif;
}
body{overflow-x:hidden}
a{text-decoration:none}

.admin-app{
    min-height:100vh;
    display:flex;
    background:
        radial-gradient(circle at 62% 0%,rgba(79,140,255,.08),transparent 28%),
        var(--bg);
}

/* ================= SIDEBAR ================= */
.sidebar{
    width:var(--sidebar-w);
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    background:linear-gradient(180deg,#0b1018,#0a0f17);
    border-right:1px solid var(--line);
    padding:18px 14px;
    z-index:1000;
    overflow-y:auto;
}

.sidebar-brand{
    height:48px;
    display:flex;
    align-items:center;
    gap:10px;
    padding:0 9px;
    color:#fff;
    margin-bottom:20px;
}

.brand-icon{
    width:34px;
    height:34px;
    border-radius:9px;
    background:linear-gradient(145deg,var(--blue),var(--cyan));
    display:grid;
    place-items:center;
    box-shadow:0 8px 22px rgba(79,140,255,.18);
}

.brand-text strong{
    display:block;
    font-size:13px;
    font-weight:850;
    letter-spacing:.4px;
}
.brand-text span{
    color:#647184;
    display:block;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:1.4px;
    margin-top:2px;
}

.sidebar-section{
    color:#556276;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:1.5px;
    font-weight:850;
    padding:7px 11px;
}

.side-link{
    min-height:39px;
    display:flex;
    align-items:center;
    gap:11px;
    padding:0 11px;
    border-radius:8px;
    color:#8995a6;
    font-size:11px;
    font-weight:650;
    margin:2px 0;
    border:1px solid transparent;
}
.side-link i{
    width:17px;
    text-align:center;
    color:#657286;
    font-size:11px;
}
.side-link:hover{
    background:#131b27;
    color:#fff;
}
.side-link.active{
    background:#17243a;
    color:#fff;
    border-color:#263c60;
    box-shadow:inset 3px 0 var(--blue);
}
.side-link.active i{color:var(--blue)}

.side-separator{
    height:1px;
    background:var(--line);
    margin:13px 8px;
}

.sidebar-user{
    position:absolute;
    left:14px;
    right:14px;
    bottom:14px;
    padding-top:12px;
    border-top:1px solid var(--line);
}
.user-box{
    display:flex;
    align-items:center;
    gap:9px;
    padding:9px;
    background:#111925;
    border:1px solid var(--line);
    border-radius:9px;
}
.user-avatar{
    width:30px;
    height:30px;
    border-radius:50%;
    background:#253144;
    display:grid;
    place-items:center;
    font-size:10px;
    font-weight:800;
}
.user-copy{
    min-width:0;
    flex:1;
}
.user-copy strong{
    display:block;
    font-size:10px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.user-copy span{
    color:#667387;
    font-size:8px;
}

/* ================= MAIN ================= */
.main{
    width:calc(100% - var(--sidebar-w));
    margin-left:var(--sidebar-w);
    min-height:100vh;
}

.topbar{
    height:62px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 24px;
    background:rgba(9,12,19,.94);
    border-bottom:1px solid var(--line);
    position:sticky;
    top:0;
    z-index:900;
    backdrop-filter:blur(12px);
}

.top-title{
    font-size:11px;
    color:#6d7a8d;
}
.top-title strong{
    color:#fff;
    font-size:12px;
}
.top-actions{
    display:flex;
    align-items:center;
    gap:7px;
}
.top-btn{
    height:32px;
    min-width:32px;
    padding:0 9px;
    display:grid;
    place-items:center;
    background:#111925;
    color:#9ca8b7;
    border:1px solid var(--line);
    border-radius:7px;
    font-size:10px;
}
.top-btn:hover{color:#fff}
.branch-pill{
    height:32px;
    padding:0 10px;
    display:flex;
    align-items:center;
    gap:6px;
    background:#111925;
    border:1px solid var(--line);
    border-radius:7px;
    color:#aab5c4;
    font-size:9px;
    font-weight:700;
}
.branch-pill i{color:var(--blue)}

.content{
    padding:24px;
    max-width:1550px;
    margin:auto;
}

.heading{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    margin-bottom:16px;
}
.heading h1{
    margin:0;
    font-size:21px;
    font-weight:850;
    letter-spacing:-.5px;
}
.heading p{
    color:#657286;
    margin:5px 0 0;
    font-size:9px;
}
.heading-actions{
    display:flex;
    gap:6px;
}
.action-btn{
    height:32px;
    padding:0 11px;
    border-radius:7px;
    border:1px solid var(--line);
    background:#111925;
    color:#aeb9c8;
    font-size:9px;
    font-weight:750;
}
.action-btn.primary{
    background:var(--blue);
    border-color:var(--blue);
    color:#fff;
}

/* ================= EXACT REFERENCE-STYLE HERO ================= */
.dashboard-grid{
    display:grid;
    grid-template-columns:205px minmax(0,1fr);
    gap:13px;
}

/* Reference has a dense analytics rail on the left */
.analytics-rail{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:10px;
    padding:13px;
    min-height:270px;
}

.rail-title{
    font-size:10px;
    font-weight:850;
    margin-bottom:12px;
}
.rail-sub{
    color:#5e6b7d;
    font-size:8px;
    margin-bottom:10px;
}
.rail-item{
    padding:9px 0;
    border-bottom:1px solid rgba(39,49,64,.72);
}
.rail-item:last-child{border-bottom:0}
.rail-item-head{
    display:flex;
    justify-content:space-between;
    color:#9aa6b5;
    font-size:8px;
}
.rail-value{
    color:#fff;
    font-size:13px;
    font-weight:800;
    margin-top:4px;
}
.mini-bar{
    height:3px;
    background:#202b3b;
    border-radius:4px;
    margin-top:5px;
    overflow:hidden;
}
.mini-bar span{
    display:block;
    height:100%;
    border-radius:4px;
    background:var(--blue);
}

/* Hero area with the image in the same prominent position as reference */
.hero{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:10px;
    overflow:hidden;
}

.hero-toolbar{
    height:38px;
    padding:0 13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid var(--line);
    background:#0f151f;
}
.hero-toolbar-title{
    color:#cbd4df;
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.8px;
}
.hero-toolbar-meta{
    color:#647286;
    font-size:8px;
}

.hero-main{
    min-height:230px;
    display:grid;
    grid-template-columns:42% 58%;
}

.hero-copy{
    padding:19px 17px;
    background:
        radial-gradient(circle at 20% 10%,rgba(79,140,255,.09),transparent 45%),
        #101722;
}

.hero-copy .eyebrow{
    color:var(--blue);
    text-transform:uppercase;
    font-size:7px;
    letter-spacing:1.4px;
    font-weight:900;
}
.hero-copy h2{
    margin:8px 0 7px;
    font-size:20px;
    line-height:1.05;
    font-weight:850;
}
.hero-copy p{
    color:#6f7d90;
    font-size:8px;
    line-height:1.55;
    max-width:260px;
}
.hero-stat{
    margin-top:15px;
    display:flex;
    gap:16px;
}
.hero-stat strong{
    display:block;
    font-size:12px;
}
.hero-stat span{
    color:#647286;
    font-size:7px;
    display:block;
    margin-top:3px;
}

/* THIS is the image position requested by the user */
.dashboard-image{
    position:relative;
    min-height:230px;
    overflow:hidden;
    background:#151d29;
}
.dashboard-image img{
    width:100%;
    height:100%;
    min-height:230px;
    display:block;
    object-fit:cover;
    object-position:center;
}
.dashboard-image.no-image{
    display:grid;
    place-items:center;
    border-left:1px solid var(--line);
}
.image-placeholder{
    width:82%;
    height:78%;
    border:1px dashed #3b485a;
    border-radius:9px;
    display:grid;
    place-items:center;
    text-align:center;
    color:#69778a;
    font-size:9px;
}
.image-placeholder i{
    font-size:24px;
    margin-bottom:8px;
    color:#435166;
}
.image-overlay{
    position:absolute;
    left:12px;
    right:12px;
    bottom:11px;
    display:flex;
    justify-content:space-between;
    align-items:end;
}
.image-caption{
    padding:6px 8px;
    border-radius:6px;
    background:rgba(7,10,16,.78);
    border:1px solid rgba(255,255,255,.08);
    backdrop-filter:blur(8px);
}
.image-caption strong{
    display:block;
    color:#fff;
    font-size:8px;
}
.image-caption span{
    color:#7f8b9c;
    font-size:7px;
}

/* ================= CIRCULAR ANALYTICS ================= */
.circular-row{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-top:13px;
}

.circle-card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:10px;
    padding:13px;
    min-height:155px;
    position:relative;
}
.circle-card-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.circle-card-title{
    color:#6e7b8d;
    text-transform:uppercase;
    letter-spacing:.8px;
    font-size:7px;
    font-weight:850;
}
.circle-icon{
    width:25px;
    height:25px;
    border-radius:7px;
    background:#192435;
    display:grid;
    place-items:center;
    color:var(--blue);
    font-size:9px;
}
.circle-area{
    height:90px;
    display:grid;
    place-items:center;
    position:relative;
}
.circle-area canvas{
    max-width:92px!important;
    max-height:92px!important;
}
.circle-center{
    position:absolute;
    text-align:center;
    pointer-events:none;
}
.circle-center strong{
    display:block;
    font-size:12px;
    font-weight:850;
}
.circle-center span{
    display:block;
    color:#667386;
    font-size:6px;
    margin-top:2px;
}
.circle-foot{
    display:flex;
    justify-content:space-between;
    color:#647286;
    font-size:7px;
}
.circle-foot strong{color:#dbe2ea}

/* ================= LOWER ANALYTICS ================= */
.lower-grid{
    display:grid;
    grid-template-columns:minmax(0,1.55fr) minmax(260px,.75fr);
    gap:13px;
    margin-top:13px;
}

.panel{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:10px;
    overflow:hidden;
}
.panel-head{
    min-height:49px;
    padding:11px 13px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid var(--line);
}
.panel-head strong{
    display:block;
    font-size:10px;
}
.panel-head span{
    color:#5f6c7e;
    display:block;
    font-size:7px;
    margin-top:3px;
}
.panel-tool{
    color:#6c798b;
    font-size:8px;
}

.big-chart{
    height:270px;
    padding:10px 12px 12px;
}

.activity{
    padding:7px 13px 13px;
}
.activity-row{
    display:flex;
    align-items:center;
    gap:9px;
    padding:10px 0;
    border-bottom:1px solid rgba(39,49,64,.7);
}
.activity-row:last-child{border-bottom:0}
.activity-icon{
    width:27px;
    height:27px;
    border-radius:7px;
    background:#192435;
    display:grid;
    place-items:center;
    color:var(--cyan);
    font-size:8px;
}
.activity-copy{
    flex:1;
    min-width:0;
}
.activity-copy strong{
    display:block;
    color:#c9d2dd;
    font-size:8px;
}
.activity-copy span{
    display:block;
    color:#5f6d80;
    font-size:7px;
    margin-top:2px;
}
.activity-value{
    color:#fff;
    font-size:8px;
    font-weight:800;
}

/* ================= MOBILE ================= */
.mobile-toggle{display:none}

@media(max-width:1100px){
    .dashboard-grid{grid-template-columns:1fr}
    .analytics-rail{min-height:auto}
    .hero-main{grid-template-columns:1fr}
    .dashboard-image{border-top:1px solid var(--line);border-left:0}
    .circular-row{grid-template-columns:repeat(2,1fr)}
    .lower-grid{grid-template-columns:1fr}
}

@media(max-width:800px){
    :root{--sidebar-w:0px}
    .sidebar{
        width:245px;
        transform:translateX(-100%);
        transition:.2s ease;
        box-shadow:20px 0 40px rgba(0,0,0,.4);
    }
    .sidebar.open{transform:translateX(0)}
    .main{width:100%;margin-left:0}
    .mobile-toggle{
        display:grid;
        place-items:center;
        width:32px;
        height:32px;
        background:#111925;
        border:1px solid var(--line);
        color:#fff;
        border-radius:7px;
        margin-right:8px;
    }
    .top-title{display:flex;align-items:center}
    .content{padding:17px 12px}
    .heading{align-items:flex-start;gap:12px;flex-direction:column}
    .heading-actions{width:100%}
    .heading-actions .action-btn{flex:1}
}

@media(max-width:520px){
    .circular-row{grid-template-columns:1fr}
    .hero-copy h2{font-size:18px}
    .top-actions .branch-pill{display:none}
}
</style>
</head>

<body>
<div class="admin-app">

<!-- ================= SIDEBAR ================= -->
<aside class="sidebar" id="sidebar">

    <a href="admin_dashboard.php" class="sidebar-brand">
        <span class="brand-icon"><i class="fas fa-capsules"></i></span>
        <span class="brand-text">
            <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong>
            <span>Master Control</span>
        </span>
    </a>

    <div class="sidebar-section">Main Analytics</div>

    <a class="side-link active" href="admin_dashboard.php">
        <i class="fas fa-chart-pie"></i><span>Overview</span>
    </a>

    <?php if ($user_role === 'Admin'): ?>
    <a class="side-link" href="staff_management.php">
        <i class="fas fa-users"></i><span>Staff Management</span>
    </a>
    <a class="side-link" href="manage_setup.php">
        <i class="fas fa-sliders"></i><span>System Setup</span>
    </a>
    <?php endif; ?>

    <div class="side-separator"></div>

    <div class="sidebar-section">Branches</div>

    <?php
    $stmt_b = $conn->prepare("
        SELECT id, branch_name
        FROM branches
        WHERE pharmacy_id = ?
          AND is_active = 1
        ORDER BY branch_name ASC
    ");
    $stmt_b->bind_param("i", $pharmacy_id);
    $stmt_b->execute();
    $b_res = $stmt_b->get_result();

    if ($b_res && $b_res->num_rows > 0):
        while ($b = $b_res->fetch_assoc()):
    ?>
        <a class="side-link" href="view_branch.php?id=<?php echo (int)$b['id']; ?>">
            <i class="fas fa-store"></i>
            <span><?php echo htmlspecialchars($b['branch_name']); ?></span>
        </a>
    <?php
        endwhile;
    else:
    ?>
        <a class="side-link" href="#" onclick="return false;" style="opacity:.5">
            <i class="fas fa-store-slash"></i><span>No active branches</span>
        </a>
    <?php endif; ?>

    <div class="sidebar-user">
        <div class="user-box">
            <div class="user-avatar">
                <?php echo strtoupper(substr($user_display_name ?: 'A',0,1)); ?>
            </div>
            <div class="user-copy">
                <strong><?php echo htmlspecialchars($user_display_name); ?></strong>
                <span><?php echo htmlspecialchars($user_role); ?></span>
            </div>
            <i class="fas fa-ellipsis" style="font-size:9px;color:#566276"></i>
        </div>

        <a class="side-link" href="../logout.php" style="color:#e96891;margin-top:5px">
            <i class="fas fa-right-from-bracket" style="color:#e96891"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>

<!-- ================= MAIN ================= -->
<main class="main">

<header class="topbar">
    <div class="top-title">
        <button class="mobile-toggle" id="mobileToggle">
            <i class="fas fa-bars"></i>
        </button>
        <span><strong>Admin Control Center</strong> &nbsp;/&nbsp; Analytics Overview</span>
    </div>

    <div class="top-actions">
        <div class="branch-pill">
            <i class="fas fa-layer-group"></i>
            <?php echo (int)$branch_count; ?> Active Branch<?php echo $branch_count == 1 ? '' : 'es'; ?>
        </div>

        <button class="top-btn" title="Refresh" onclick="location.reload()">
            <i class="fas fa-rotate"></i>
        </button>

        <button class="top-btn" title="Notifications">
            <i class="far fa-bell"></i>
        </button>

        <button class="top-btn" title="Account">
            <i class="far fa-user"></i>
        </button>
    </div>
</header>

<section class="content">

    <div class="heading">
        <div>
            <h1>Analytics Dashboard</h1>
            <p>High-level performance and operational intelligence for <?php echo htmlspecialchars($pharmacy_name); ?>.</p>
        </div>

        <div class="heading-actions">
            <button class="action-btn" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Export
            </button>
            <button class="action-btn primary" onclick="location.reload()">
                <i class="fas fa-arrows-rotate me-1"></i> Sync
            </button>
        </div>
    </div>

    <!-- THE REFERENCE IMAGE POSITION:
         upload/place your dashboard image at:
         ../uploads/admin/admin_dashboard.jpg
         The image occupies the large right-hand hero area. -->
    <div class="dashboard-grid">

        <aside class="analytics-rail">
            <div class="rail-title">Performance Monitor</div>
            <div class="rail-sub">Live network indicators</div>

            <div class="rail-item">
                <div class="rail-item-head">
                    <span>Total Revenue</span>
                    <span><i class="fas fa-arrow-up positive"></i></span>
                </div>
                <div class="rail-value">K<?php echo number_format((float)$total_sales,2); ?></div>
                <div class="mini-bar"><span style="width:78%"></span></div>
            </div>

            <div class="rail-item">
                <div class="rail-item-head">
                    <span>Transactions</span>
                    <span><?php echo number_format((int)$total_orders); ?></span>
                </div>
                <div class="rail-value"><?php echo number_format((int)$total_orders); ?></div>
                <div class="mini-bar"><span style="width:64%"></span></div>
            </div>

            <div class="rail-item">
                <div class="rail-item-head">
                    <span>Branches</span>
                    <span class="positive">LIVE</span>
                </div>
                <div class="rail-value"><?php echo (int)$branch_count; ?></div>
                <div class="mini-bar"><span style="width:90%"></span></div>
            </div>

            <div class="rail-item">
                <div class="rail-item-head">
                    <span>Avg. Ticket</span>
                    <span>K</span>
                </div>
                <div class="rail-value">K<?php
                    $avg = $total_orders > 0 ? ((float)$total_sales/(int)$total_orders) : 0;
                    echo number_format($avg,2);
                ?></div>
                <div class="mini-bar"><span style="width:52%"></span></div>
            </div>

            <div class="rail-item">
                <div class="rail-item-head">
                    <span>System</span>
                    <span class="positive">ONLINE</span>
                </div>
                <div class="rail-value" style="font-size:10px">All services operational</div>
            </div>
        </aside>

        <section class="hero">

            <div class="hero-toolbar">
                <span class="hero-toolbar-title">Global Business Intelligence</span>
                <span class="hero-toolbar-meta">Network Â· <?php echo date('d M Y'); ?></span>
            </div>

            <div class="hero-main">

                <div class="hero-copy">
                    <div class="eyebrow">Central Command</div>
                    <h2><?php echo htmlspecialchars($pharmacy_name); ?></h2>
                    <p>
                        Monitor revenue, branch performance, transaction volume
                        and payment activity from one centralized administration view.
                    </p>

                    <div class="hero-stat">
                        <div>
                            <strong>K<?php echo number_format((float)$total_sales,0); ?></strong>
                            <span>Revenue</span>
                        </div>
                        <div>
                            <strong><?php echo number_format((int)$total_orders); ?></strong>
                            <span>Transactions</span>
                        </div>
                        <div>
                            <strong><?php echo (int)$branch_count; ?></strong>
                            <span>Locations</span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-image">
                    <?php
                    $admin_image = '../uploads/admin/admin_dashboard.jpg';
                    $admin_image_path = dirname(__DIR__) . '/uploads/admin/admin_dashboard.jpg';
                    ?>
                    <?php if (file_exists($admin_image_path)): ?>
                        <img src="<?php echo htmlspecialchars($admin_image); ?>"
                             alt="Admin dashboard visual">
                        <div class="image-overlay">
                            <div class="image-caption">
                                <strong>Business Intelligence</strong>
                                <span>Central analytics workspace</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="image-placeholder">
                            <div>
                                <i class="fas fa-image d-block"></i>
                                <strong style="display:block;color:#8996a8;font-size:9px">Dashboard Image Area</strong>
                                <span style="display:block;margin-top:5px">
                                    Place admin_dashboard.jpg in<br>
                                    /uploads/admin/
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </section>
    </div>

    <!-- Four circular analytics blocks like the reference -->
    <div class="circular-row">

        <div class="circle-card">
            <div class="circle-card-head">
                <span class="circle-card-title">Revenue</span>
                <span class="circle-icon"><i class="fas fa-coins"></i></span>
            </div>
            <div class="circle-area">
                <canvas id="revenueCircle"></canvas>
                <div class="circle-center">
                    <strong>K<?php echo number_format((float)$total_sales,0); ?></strong>
                    <span>Total</span>
                </div>
            </div>
            <div class="circle-foot">
                <span>Network sales</span>
                <strong>100%</strong>
            </div>
        </div>

        <div class="circle-card">
            <div class="circle-card-head">
                <span class="circle-card-title">Transactions</span>
                <span class="circle-icon"><i class="fas fa-receipt"></i></span>
            </div>
            <div class="circle-area">
                <canvas id="transactionCircle"></canvas>
                <div class="circle-center">
                    <strong><?php echo number_format((int)$total_orders); ?></strong>
                    <span>Sales</span>
                </div>
            </div>
            <div class="circle-foot">
                <span>Completed volume</span>
                <strong><?php echo number_format((int)$total_orders); ?></strong>
            </div>
        </div>

        <div class="circle-card">
            <div class="circle-card-head">
                <span class="circle-card-title">Branches</span>
                <span class="circle-icon"><i class="fas fa-store"></i></span>
            </div>
            <div class="circle-area">
                <canvas id="branchCircle"></canvas>
                <div class="circle-center">
                    <strong><?php echo (int)$branch_count; ?></strong>
                    <span>Active</span>
                </div>
            </div>
            <div class="circle-foot">
                <span>Operational locations</span>
                <strong>LIVE</strong>
            </div>
        </div>

        <div class="circle-card">
            <div class="circle-card-head">
                <span class="circle-card-title">Average Ticket</span>
                <span class="circle-icon"><i class="fas fa-calculator"></i></span>
            </div>
            <div class="circle-area">
                <canvas id="averageCircle"></canvas>
                <div class="circle-center">
                    <strong>K<?php echo number_format($avg,0); ?></strong>
                    <span>Average</span>
                </div>
            </div>
            <div class="circle-foot">
                <span>Per transaction</span>
                <strong>K<?php echo number_format($avg,2); ?></strong>
            </div>
        </div>

    </div>

    <div class="lower-grid">

        <section class="panel">
            <div class="panel-head">
                <div>
                    <strong>Branch Performance</strong>
                    <span>Revenue comparison across active branches</span>
                </div>
                <div class="panel-tool">ALL TIME</div>
            </div>
            <div class="big-chart">
                <canvas id="branchChart"></canvas>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <strong>Latest Activity</strong>
                    <span>Current network indicators</span>
                </div>
            </div>

            <div class="activity">

                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="activity-copy">
                        <strong>Revenue consolidated</strong>
                        <span>All active branches</span>
                    </div>
                    <div class="activity-value">K<?php echo number_format((float)$total_sales,0); ?></div>
                </div>

                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-cart-shopping"></i></div>
                    <div class="activity-copy">
                        <strong>Transactions recorded</strong>
                        <span>Network sales volume</span>
                    </div>
                    <div class="activity-value"><?php echo number_format((int)$total_orders); ?></div>
                </div>

                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-store"></i></div>
                    <div class="activity-copy">
                        <strong>Branches operational</strong>
                        <span>Active locations</span>
                    </div>
                    <div class="activity-value positive"><?php echo (int)$branch_count; ?></div>
                </div>

                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-shield-halved"></i></div>
                    <div class="activity-copy">
                        <strong>Admin session</strong>
                        <span><?php echo htmlspecialchars($user_display_name); ?> Â· <?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                    <div class="activity-value positive">SECURE</div>
                </div>

            </div>
        </section>

    </div>

</section>
</main>
</div>

<script>
(function(){

    const branchLabels = <?php echo json_encode($b_names, JSON_UNESCAPED_UNICODE); ?>;
    const branchSales = <?php echo json_encode($b_revenues); ?>;

    Chart.defaults.font.family =
        'Inter,Arial,Helvetica,sans-serif';
    Chart.defaults.color = '#758296';

    const donutBase = {
        type:'doughnut',
        options:{
            responsive:true,
            maintainAspectRatio:false,
            cutout:'76%',
            animation:{duration:900},
            plugins:{legend:{display:false},tooltip:{enabled:false}}
        }
    };

    function makeCircle(id,value,max,color){
        const el=document.getElementById(id);
        if(!el)return;

        const safeMax=Math.max(Number(max)||0,1);
        const safeValue=Math.min(Math.max(Number(value)||0,0),safeMax);

        new Chart(el,{
            ...donutBase,
            data:{
                datasets:[{
                    data:[safeValue,Math.max(safeMax-safeValue,0)],
                    backgroundColor:[color,'#202a39'],
                    borderWidth:0,
                    hoverOffset:0
                }]
            }
        });
    }

    makeCircle(
        'revenueCircle',
        <?php echo json_encode((float)$total_sales); ?>,
        <?php echo json_encode(max((float)$total_sales,1)); ?>,
        '#4f8cff'
    );

    makeCircle(
        'transactionCircle',
        <?php echo json_encode((float)$total_orders); ?>,
        <?php echo json_encode(max((float)$total_orders,1)); ?>,
        '#36c9ff'
    );

    makeCircle(
        'branchCircle',
        <?php echo json_encode((float)$branch_count); ?>,
        <?php echo json_encode(max((float)$branch_count,1)); ?>,
        '#35d39a'
    );

    makeCircle(
        'averageCircle',
        <?php echo json_encode((float)$avg); ?>,
        <?php echo json_encode(max((float)$avg,1)); ?>,
        '#f4c95d'
    );

    const branchCanvas=document.getElementById('branchChart');

    if(branchCanvas){
        new Chart(branchCanvas,{
            type:'bar',
            data:{
                labels:branchLabels.length ? branchLabels : ['No Data'],
                datasets:[{
                    label:'Revenue',
                    data:branchSales.length ? branchSales : [0],
                    backgroundColor:'#4f8cff',
                    borderRadius:5,
                    borderSkipped:false,
                    maxBarThickness:38
                }]
            },
            options:{
                responsive:true,
                maintainAspectRatio:false,
                plugins:{
                    legend:{display:false},
                    tooltip:{
                        backgroundColor:'#090e16',
                        borderColor:'#2a3647',
                        borderWidth:1,
                        titleColor:'#fff',
                        bodyColor:'#b8c2cf'
                    }
                },
                scales:{
                    y:{
                        beginAtZero:true,
                        grid:{color:'rgba(110,130,155,.12)'},
                        border:{display:false},
                        ticks:{
                            color:'#687589',
                            font:{size:8},
                            callback:function(v){return 'K'+v;}
                        }
                    },
                    x:{
                        grid:{display:false},
                        border:{display:false},
                        ticks:{
                            color:'#687589',
                            font:{size:8,weight:'600'}
                        }
                    }
                }
            }
        });
    }

    /* Mobile navigation */
    const sidebar=document.getElementById('sidebar');
    const toggle=document.getElementById('mobileToggle');

    toggle?.addEventListener('click',function(){
        sidebar.classList.toggle('open');
    });

    document.querySelectorAll('.sidebar a').forEach(function(a){
        a.addEventListener('click',function(){
            if(window.innerWidth<=800)sidebar.classList.remove('open');
        });
    });

})();
</script>
</body>
</html>
