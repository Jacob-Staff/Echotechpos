<?php
$pharmacy_name = $pharmacy_name ?? 'PHARMACY POS';
$user_role = $user_role ?? 'Admin';
$user_display_name = $user_display_name ?? 'Administrator';
$branch_count = (int)($branch_count ?? 0);
$total_orders = (int)($total_orders ?? 0);
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
.admin-header{
    height:64px;border-bottom:1px solid var(--border);background:#fff;
    display:flex;align-items:center;justify-content:space-between;
    padding:0 28px;position:sticky;top:0;z-index:900;
    box-shadow:0 1px 7px rgba(0,0,0,.03)
}
.admin-header .top-left{display:flex;align-items:center;gap:12px}
.admin-header .mobile-btn{
    display:none;width:36px;height:36px;border:1px solid var(--border);
    border-radius:8px;background:#fff;color:var(--charcoal)
}
.admin-header .crumb{font-size:12px;color:var(--muted)}
.admin-header .crumb b{color:var(--text);font-size:14px}
.admin-header .top-right{display:flex;align-items:center;gap:8px}
.admin-header .search-mini{
    width:230px;height:37px;background:#fff;border:1px solid var(--border);
    border-radius:8px;color:var(--text);font-size:12px;padding:0 12px;outline:none
}
.admin-header .search-mini:focus{
    border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)
}
.admin-header .top-icon{
    width:37px;height:37px;background:#fff;border:1px solid var(--border);
    border-radius:8px;color:#65717d;display:grid;place-items:center
}
.admin-header .top-icon:hover{color:var(--blue);border-color:#a9c0ec}
.admin-header .branch{
    height:37px;padding:0 11px;border:1px solid var(--border);
    background:#fff;border-radius:8px;color:#65717d;font-size:11px;
    display:flex;align-items:center;gap:7px
}
.admin-header .branch i{color:var(--blue)}
@media(max-width:900px){
    .admin-header{padding:0 16px}
    .admin-header .mobile-btn{display:grid;place-items:center}
    .admin-header .search-mini{display:none}
}
@media(max-width:560px){
    .admin-header .branch{display:none}
    .admin-header .top-right{gap:5px}
}

</style>

<header class="admin-header" id="adminHeader">
    <div class="top-left">
        <button class="mobile-btn" id="adminMobileToggle" type="button" aria-label="Open admin menu">
            <i class="fas fa-bars"></i>
        </button>
        <div class="crumb">
            <b>Admin Control Center</b>
            <span>&nbsp; / &nbsp;</span>
            <span><?php echo htmlspecialchars($admin_page_title ?? 'Executive Overview'); ?></span>
        </div>
    </div>

    <div class="top-right">
        <input class="search-mini" id="adminHeaderSearch" placeholder="Search dashboard..." type="search" autocomplete="off">
        <div class="branch"><i class="fas fa-layer-group"></i><?php echo (int)$branch_count; ?> Active</div>
        <button class="top-icon" type="button" title="Refresh" onclick="location.reload()"><i class="fas fa-rotate"></i></button>
        <button class="top-icon" type="button" title="Notifications"><i class="far fa-bell"></i></button>
        <button class="top-icon" type="button" title="Account"><i class="far fa-user"></i></button>
    </div>
</header>

<script>
(function () {
    const aside = document.getElementById('adminAside');
    const toggle = document.getElementById('adminMobileToggle');

    if (toggle && aside) {
        toggle.addEventListener('click', function () {
            aside.classList.toggle('open');
        });
    }

    document.querySelectorAll('.admin-aside a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 900 && aside) {
                aside.classList.remove('open');
            }
        });
    });
})();
</script>
