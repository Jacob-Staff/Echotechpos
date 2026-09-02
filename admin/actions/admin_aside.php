<?php
$pharmacy_name = $pharmacy_name ?? 'PHARMACY POS';
$user_role = $user_role ?? 'Admin';
$user_display_name = $user_display_name ?? 'Administrator';
$branch_count = (int)($branch_count ?? 0);
$total_orders = (int)($total_orders ?? 0);
$current_admin_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
?>
<style>
:root{
    --bg:#f4f6f8;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --charcoal:#202831;
    --charcoal-2:#2b3540;
    --charcoal-3:#374452;
    --text:#1d252d;
    --muted:#6d7782;
    --border:#dfe4e9;
    --blue:#246bfe;
    --blue-soft:#eaf1ff;
    --cyan:#19a9d2;
    --green:#159a68;
    --green-soft:#e8f7f0;
    --yellow:#e7a72e;
    --yellow-soft:#fff6df;
    --red:#d94d61;
    --red-soft:#fff0f2;
    --purple:#7658e8;
    --sidebar:250px;
    --radius:12px;
    --shadow:0 4px 18px rgba(31,40,49,.06);
}
*{box-sizing:border-box}
html,body{
    margin:0;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Arial,sans-serif;
    font-size:14px;
}
body{overflow-x:hidden}
button,input{font:inherit}
button{cursor:pointer}
a{text-decoration:none;color:inherit}
.admin-aside{
    position:fixed;left:0;top:0;bottom:0;width:var(--sidebar);
    height:100vh;height:100dvh;
    background:var(--charcoal);border-right:1px solid #161d24;
    z-index:1000;
    padding:18px 14px 0;
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.admin-aside-scroll{
    flex:1 1 auto;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    padding-right:2px;
    scrollbar-width:thin;
    scrollbar-color:#465363 transparent;
}
.admin-aside-scroll::-webkit-scrollbar{width:6px}
.admin-aside-scroll::-webkit-scrollbar-track{background:transparent}
.admin-aside-scroll::-webkit-scrollbar-thumb{
    background:#465363;border-radius:8px
}
.admin-aside-scroll::-webkit-scrollbar-thumb:hover{background:#5a6878}

.admin-aside .brand{
    height:54px;display:flex;align-items:center;gap:12px;
    padding:0 9px;margin-bottom:18px;color:#fff;
}
.admin-aside .brand-mark{
    width:38px;height:38px;border-radius:10px;background:var(--blue);
    display:grid;place-items:center;
}
.admin-aside .brand-mark i{font-size:17px;color:#fff}
.admin-aside .brand strong{display:block;font-size:15px;font-weight:800;letter-spacing:.2px}
.admin-aside .brand small{
    display:block;color:#aeb8c2;font-size:9px;text-transform:uppercase;
    letter-spacing:1.2px;margin-top:3px
}
.admin-aside .side-caption{
    font-size:10px;text-transform:uppercase;letter-spacing:1.2px;
    color:#8f9ba7;font-weight:800;padding:12px 11px 7px
}
.admin-aside .nav{display:flex;flex-direction:column;gap:3px}
.admin-aside .nav a,.admin-aside .nav .nav-parent{
    min-height:42px;border-radius:8px;color:#bdc6cf;display:flex;
    align-items:center;gap:11px;padding:0 12px;font-size:13px;
    font-weight:600;border:1px solid transparent
}
.admin-aside .nav a i,.admin-aside .nav .nav-parent>i:first-child{width:18px;text-align:center;color:#8996a3;font-size:13px}
.admin-aside .nav a:hover,.admin-aside .nav .nav-parent:hover{background:#2a3541;color:#fff}
.admin-aside .nav a.active,.admin-aside .nav .nav-parent.active{
    background:#344253;border-color:#405166;color:#fff;
    box-shadow:inset 3px 0 var(--blue)
}
.admin-aside .nav a.active i,.admin-aside .nav .nav-parent.active>i:first-child{color:#70a0ff}
.admin-aside .nav-badge{
    margin-left:auto;background:#465363;color:#e5eaf0;
    border-radius:12px;padding:3px 7px;font-size:10px
}
.admin-aside .separator{height:1px;background:#3a444e;margin:14px 8px}
.admin-aside .sidebar-mini{
    margin:14px 7px 0;background:#18212a;border:1px solid #35414d;
    border-radius:9px;padding:12px;
}
.admin-aside .mini-title{font-size:11px;font-weight:800;color:#edf1f5;margin-bottom:9px}
.admin-aside .mini-line{display:flex;justify-content:space-between;color:#a3adb8;font-size:10px;margin:7px 0}
.admin-aside .mini-line b{color:#f0f3f6}
.admin-aside .mini-progress{height:4px;background:#303b47;border-radius:4px;overflow:hidden}
.admin-aside .mini-progress span{display:block;height:100%;border-radius:4px;background:var(--blue)}
.admin-aside .side-user{
    position:relative;
    flex:0 0 auto;
    left:auto;right:auto;bottom:auto;
    border-top:1px solid #3a444e;
    padding:11px 0 12px;
    margin-top:10px;
    background:var(--charcoal);
    z-index:5;
}
.admin-aside .user{
    display:flex;align-items:center;gap:9px;padding:9px;
    min-width:0;
    background:#18212a;border:1px solid #35414d;border-radius:9px
}
.admin-aside .avatar{
    width:32px;height:32px;border-radius:50%;background:#3b4857;
    display:grid;place-items:center;font-size:12px;font-weight:800;color:#fff
}
.admin-aside .user-copy{min-width:0;flex:1}
.admin-aside .user-copy b{
    display:block;font-size:11px;white-space:nowrap;overflow:hidden;
    text-overflow:ellipsis;color:#fff
}
.admin-aside .user-copy span{display:block;color:#9ba7b3;font-size:9px;margin-top:3px}
.admin-aside .user i{color:#84909d;font-size:11px}
.admin-aside .side-user .nav-link{
    display:flex;align-items:center;gap:9px;color:#f17a8b;
    font-size:11px;font-weight:700;padding:9px 10px;
    border-radius:7px;
    margin-top:3px;
}
.admin-aside .side-user .nav-link:hover{
    background:#2a3541;color:#ff9aaa;
}
.admin-aside .nav-group{display:flex;flex-direction:column;gap:2px}
.admin-aside .nav-parent{width:100%;border:1px solid transparent;background:transparent;font:inherit;text-align:left;cursor:pointer}
.admin-aside .nav-parent .nav-chevron{margin-left:auto;width:auto!important;color:#8996a3;font-size:10px;transition:transform .18s ease}
.admin-aside .nav-parent.open .nav-chevron{transform:rotate(180deg)}
.admin-aside .nav-subnav{display:flex;flex-direction:column;gap:2px;margin:0 0 3px 29px;padding-left:9px;border-left:1px solid #3a4652}
.admin-aside .nav-subnav a{min-height:35px;padding:0 10px;font-size:11px;border-radius:7px}
.admin-aside .nav-subnav a i{font-size:11px;width:15px}
.admin-aside .nav-subnav a.active{background:#2c3947;border-color:#3b4a5a;box-shadow:inset 2px 0 var(--blue)}
@media(max-width:900px){
    .admin-aside{
        width:250px;transform:translateX(-100%);transition:.22s;
        box-shadow:15px 0 35px rgba(0,0,0,.22)
    }
    .admin-aside.open{transform:translateX(0)}
    .admin-aside-scroll{padding-bottom:4px}
    .admin-aside .side-user{padding-bottom:14px}
}

</style>

<aside class="admin-aside" id="adminAside">
    <a class="brand" href="admin_dashboard.php">
        <span class="brand-mark"><i class="fas fa-capsules"></i></span>
        <span>
            <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong>
            <small>POS ADMIN CONTROL</small>
        </span>
    </a>

    <div class="admin-aside-scroll">
    <div class="side-caption">Workspace</div>
    <nav class="nav">
        <a class="<?= $current_admin_page === 'admin_dashboard.php' ? 'active' : '' ?>" href="admin_dashboard.php"><i class="fas fa-chart-pie"></i>Dashboard</a>
        <?php if ($user_role === 'Admin'): ?>
        <a href="staff_management.php"><i class="fas fa-users"></i>Staff Management</a>
        <a href="manage_setup.php"><i class="fas fa-sliders"></i>System Setup</a>
        <?php endif; ?>

        <?php $payrollNavOpen = in_array($current_admin_page, ['payroll.php','loans_advances.php'], true); ?>
        <div class="nav-group">
            <button type="button"
                    class="nav-parent <?= $payrollNavOpen ? 'open active' : '' ?>"
                    id="payrollNavParent"
                    aria-expanded="<?= $payrollNavOpen ? 'true' : 'false' ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Payroll</span>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </button>

            <div class="nav-subnav"
                 id="payrollNavSubnav"
                 style="<?= $payrollNavOpen ? '' : 'display:none;' ?>">
                <a class="<?= $current_admin_page === 'payroll.php' ? 'active' : '' ?>"
                   href="payroll.php">
                    <i class="fas fa-file-invoice"></i>Payroll Register
                </a>

                <a class="<?= $current_admin_page === 'loans_advances.php' ? 'active' : '' ?>"
                   href="loans_advances.php">
                    <i class="fas fa-hand-holding-dollar"></i>Loans &amp; Advances
                </a>
            </div>
        </div>

        <?php if ($user_role === 'Admin'): ?>
        <?php
            // Compliance / ZRA navigation is Admin-only.
            // Keep these pages under the Admin control panel.
            $complianceNavOpen = in_array($current_admin_page, [
                'compliance.php',
                'zra.php',
                'zra_items.php'
            ], true);
        ?>
        <div class="nav-group">
            <button type="button"
                    class="nav-parent <?= $complianceNavOpen ? 'open active' : '' ?>"
                    id="complianceNavParent"
                    aria-expanded="<?= $complianceNavOpen ? 'true' : 'false' ?>">
                <i class="fas fa-shield-halved"></i>
                <span>Compliance</span>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </button>

            <div class="nav-subnav"
                 id="complianceNavSubnav"
                 style="<?= $complianceNavOpen ? '' : 'display:none;' ?>">
                <a class="<?= $current_admin_page === 'compliance.php' ? 'active' : '' ?>"
                   href="compliance.php">
                    <i class="fas fa-shield-halved"></i>Compliance Overview
                </a>

                <a class="<?= $current_admin_page === 'zra.php' ? 'active' : '' ?>"
                   href="zra.php">
                    <i class="fas fa-file-invoice"></i>ZRA / Smart Invoice
                </a>

                <a class="<?= $current_admin_page === 'zra_items.php' ? 'active' : '' ?>"
                   href="zra_items.php">
                    <i class="fas fa-box-open"></i>ZRA Items
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php
            /*
             * Admin reporting navigation.
             * These pages are pharmacy-wide reports with optional
             * branch filters, so keep them grouped under Reports.
             */
            $reportsNavOpen = in_array($current_admin_page, [
                'sales_report.php',
                'today_transactions.php',
                'pharmacy_stock.php'
            ], true);
        ?>
        <div class="nav-group">
            <button type="button"
                    class="nav-parent <?= $reportsNavOpen ? 'open active' : '' ?>"
                    id="reportsNavParent"
                    aria-expanded="<?= $reportsNavOpen ? 'true' : 'false' ?>">
                <i class="fas fa-chart-column"></i>
                <span>Reports</span>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </button>

            <div class="nav-subnav"
                 id="reportsNavSubnav"
                 style="<?= $reportsNavOpen ? '' : 'display:none;' ?>">

                <a class="<?= $current_admin_page === 'sales_report.php' ? 'active' : '' ?>"
                   href="sales_report.php">
                    <i class="fas fa-chart-line"></i>Sales Report
                </a>

                <a class="<?= $current_admin_page === 'today_transactions.php' ? 'active' : '' ?>"
                   href="today_transactions.php">
                    <i class="fas fa-receipt"></i>Transactions
                    <span class="nav-badge"><?php echo (int)$total_orders; ?></span>
                </a>

                <a class="<?= $current_admin_page === 'pharmacy_stock.php' ? 'active' : '' ?>"
                   href="pharmacy_stock.php">
                    <i class="fas fa-boxes-stacked"></i>Stock Report
                </a>
            </div>
        </div>

        <a href="online_orders.php"><i class="fas fa-bag-shopping"></i>Online Orders</a>
        <a href="suppliers.php"><i class="fas fa-truck"></i>Suppliers</a>
        <a href="customers.php"><i class="fas fa-user-group"></i>Customers</a>
    </nav>

    <div class="separator"></div>
    <div class="side-caption">Network</div>
    <nav class="nav">
        <a href="branches.php"><i class="fas fa-store"></i>Branches <span class="nav-badge"><?php echo (int)$branch_count; ?></span></a>
        <a href="purchase_orders.php"><i class="fas fa-file-invoice-dollar"></i>Purchase Orders</a>
        <a href="audit_logs.php"><i class="fas fa-shield-halved"></i>Audit &amp; Security</a>
        <a href="settings.php"><i class="fas fa-gear"></i>Configuration</a>
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
    </div>

    <div class="side-user">
        <div class="user">
            <div class="avatar"><?php echo strtoupper(substr($user_display_name ?: 'A', 0, 1)); ?></div>
            <div class="user-copy">
                <b><?php echo htmlspecialchars($user_display_name); ?></b>
                <span><?php echo htmlspecialchars($user_role); ?> &middot; Administrator</span>
            </div>
            <i class="fas fa-ellipsis"></i>
        </div>
        <a class="nav-link" href="../logout.php">
            <i class="fas fa-right-from-bracket"></i>Sign out
        </a>
    </div>
</aside>
<script>
(function(){
    function setupNavGroup(parentId, subId){
        var parent=document.getElementById(parentId);
        var sub=document.getElementById(subId);
        if(!parent || !sub) return;

        parent.addEventListener('click',function(){
            var open=sub.style.display!=='none';
            sub.style.display=open?'none':'flex';
            parent.classList.toggle('open',!open);
            parent.setAttribute('aria-expanded',!open?'true':'false');
        });
    }

    setupNavGroup('payrollNavParent','payrollNavSubnav');
    setupNavGroup('complianceNavParent','complianceNavSubnav');
    setupNavGroup('reportsNavParent','reportsNavSubnav');
})();
</script>
