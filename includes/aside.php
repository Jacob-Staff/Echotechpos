<?php
/**
 * ============================================================
 * EchoTech POS - ORIGINAL SIDEBAR
 * ============================================================
 * Restored to the user's original sidebar layout.
 *
 * IMPORTANT:
 * - The visual layout is kept unchanged.
 * - Access control does NOT remove menu items.
 * - A page without access remains visible but its link is dormant.
 * - Logout and Online App are NOT controlled by page permissions.
 * - Direct URL protection is handled by includes/auth.php.
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* conn.php is normally loaded by the page before aside.php. */
if (!isset($conn) || !($conn instanceof mysqli)) {
    $connFile = __DIR__ . '/conn.php';
    if (file_exists($connFile)) {
        require_once $connFile;
    }
}

if (function_exists('require_login')) {
    require_login();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$display_name = $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? $_SESSION['sessionUsername']
    ?? 'Staff';
$display_role = $_SESSION['role'] ?? 'Staff';

if (isset($conn) && $conn instanceof mysqli && $user_id > 0) {
    try {
        $stmt = $conn->prepare(
            'SELECT full_name, username, role FROM users WHERE id=? LIMIT 1'
        );

        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $display_name = $row['full_name']
                    ?: ($row['username'] ?: $display_name);
                $display_role = $row['role'] ?: $display_role;
            }
        }
    } catch (Throwable $e) {
        error_log('EchoTech sidebar user lookup: ' . $e->getMessage());
    }
}

$current_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));

function echotech_sidebar_active(string $route): string
{
    global $current_page;
    return strtolower($current_page) === strtolower($route) ? 'active' : '';
}

/**
 * Keep the menu item visible at all times.
 *
 * When access is OFF the link is deliberately rendered as a dormant
 * link rather than being removed from the sidebar.
 */
function echotech_sidebar_link(string $route, string $page): string
{
    $safeRoute = htmlspecialchars($route, ENT_QUOTES, 'UTF-8');

    if (function_exists('has_page_access') && !has_page_access($page)) {
        return 'href="' . $safeRoute . '" aria-disabled="true" tabindex="-1" class="sidebar-link sidebar-link-disabled" onclick="return false;"';
    }

    return 'href="' . $safeRoute . '"';
}
?>

<aside class="left-sidebar" id="echotechSidebar">
    <div class="sidebar-inner">

        <div class="user-profile-wrap">
            <button type="button"
                    class="user-profile-box user-profile-toggle"
                    id="staffProfileToggle"
                    aria-expanded="false"
                    aria-controls="staffProfileMenu">
                <div class="user-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>

                <div class="user-info text-truncate">
                    <div class="fw-bold small text-white text-truncate">
                        Staff: <?= htmlspecialchars((string)$display_name, ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <div class="extra-small text-truncate sidebar-role">
                        <?= htmlspecialchars((string)$display_role, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

                <i class="fas fa-chevron-down user-profile-chevron" aria-hidden="true"></i>
            </button>

            <div class="staff-profile-menu" id="staffProfileMenu">
                <a class="staff-profile-menu-item <?= echotech_sidebar_active('loans_advances.php'); ?>"
                   href="loans_advances.php">
                    <i class="fas fa-hand-holding-dollar"></i>
                    <span>Loans &amp; Advances</span>
                </a>
            </div>
        </div>

        <nav class="sidebar-nav pt-1">
            <ul class="sidebarnav list-unstyled mb-0">

                <!-- ORIGINAL MENU ORDER -->

                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('dashboard.php'); ?>"
                       href="dashboard.php">
                        <i class="mdi mdi-view-dashboard-outline"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('pharmacy_stock.php', 'Pharmacy stock'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('pharmacy_stock.php'); ?>">
                        <i class="mdi mdi-package-variant-closed"></i>
                        <span>Pharmacy Stock</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('purchase_orders.php', 'Purchases orders'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('purchase_orders.php'); ?>">
                        <i class="mdi mdi-cart-plus"></i>
                        <span>Purchase-orders</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('suppliers.php', 'Supplier'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('suppliers.php'); ?>">
                        <i class="mdi mdi-truck-outline"></i>
                        <span>Suppliers</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('add_product.php', 'Add Product'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('add_product.php'); ?>">
                        <i class="mdi mdi-plus-circle-outline"></i>
                        <span>Add Product</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('stock_transfer.php', 'Stock exchange'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('stock_transfer.php'); ?>">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Stock Transfers</span>
                    </a>
                </li>

                <li class="sidebar-item">
                    <a <?= echotech_sidebar_link('shift_log.php', 'Shift log'); ?>
                       class="sidebar-link <?= echotech_sidebar_active('shift_log.php'); ?>">
                        <i class="mdi mdi-clock-outline"></i>
                        <span>Duty &amp; Shift Log</span>
                    </a>
                </li>

                <!-- Online App is intentionally outside the permission system. -->
                <li class="sidebar-item online-app-link">
                    <a class="sidebar-link" href="../api/login_client.php">
                        <i class="mdi mdi-cellphone-link"></i>
                        <span>Online App</span>
                    </a>
                </li>

            </ul>
        </nav>
    </div>

    <!-- Logout is always available and is NEVER permission-gated. -->
    <div class="logout-btn-container">
        <a href="../logout.php"
           class="btn logout-btn w-100 text-center text-decoration-none">
            <i class="mdi mdi-logout me-1"></i>
            Logout
        </a>
    </div>
</aside>

<style>
.left-sidebar{
    position:fixed;
    top:0;
    left:0;
    width:240px;
    height:100vh;
    background:#1e293b;
    z-index:1000;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    box-sizing:border-box;
    overflow:hidden;
}

.sidebar-inner{
    flex:1;
    overflow-y:auto;
    padding:1rem .75rem .5rem;
    box-sizing:border-box;
}

.sidebar-inner::-webkit-scrollbar{
    width:0;
    background:transparent;
}

.user-profile-box{
    display:flex;
    align-items:center;
    gap:10px;
    padding:.75rem;
    background:#0f172a;
    border-radius:8px;
    margin-bottom:.75rem;
}

.user-avatar{
    width:36px;
    height:36px;
    border-radius:50%;
    background:#334155;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1rem;
    flex-shrink:0;
}

.sidebar-role{
    color:#92a4b5!important;
    font-size:.76rem;
}

/* ============================================================
   STAFF PROFILE DROPDOWN
   Only the profile box is made clickable.
   ============================================================ */
.user-profile-wrap{
    position:relative;
    margin-bottom:.75rem;
}

.user-profile-toggle{
    width:100%;
    border:0;
    margin:0;
    font:inherit;
    text-align:left;
    cursor:pointer;
    position:relative;
}

.user-profile-toggle:focus{
    outline:none;
}

.user-profile-chevron{
    margin-left:auto;
    color:#92a4b5;
    font-size:10px;
    transition:transform .16s ease;
}

.user-profile-toggle[aria-expanded="true"] .user-profile-chevron{
    transform:rotate(180deg);
}

.staff-profile-menu{
    display:none;
    margin-top:5px;
    padding:4px;
    background:#0f172a;
    border:1px solid #334155;
    border-radius:7px;
    box-sizing:border-box;
}

.staff-profile-menu.open{
    display:block;
}

.staff-profile-menu-item{
    display:flex;
    align-items:center;
    gap:9px;
    width:100%;
    box-sizing:border-box;
    padding:9px 10px;
    color:#cbd5e1;
    text-decoration:none;
    border-radius:5px;
    font-size:.82rem;
    font-weight:600;
    transition:all .16s ease;
}

.staff-profile-menu-item i{
    width:17px;
    text-align:center;
    color:#60a5fa;
}

.staff-profile-menu-item:hover,
.staff-profile-menu-item.active{
    color:#fff;
    background:#334155;
}

.staff-profile-menu-item.active{
    box-shadow:inset 3px 0 #60a5fa;
}

.sidebar-link{
    display:flex;
    align-items:center;
    gap:10px;
    padding:.55rem .8rem;
    color:#94a3b8;
    text-decoration:none;
    border-radius:7px;
    font-size:.84rem;
    transition:all .16s ease;
}

.sidebar-link i{
    width:18px;
    text-align:center;
    font-size:16px;
    flex-shrink:0;
}

.sidebar-link:hover,
.sidebar-link.active{
    color:#fff;
    background:#334155;
}

.sidebar-link.active{
    box-shadow:inset 3px 0 #60a5fa;
}

/*
 * Access OFF = same appearance, but completely dormant.
 * No blur, no opacity, no disappearing item.
 */
.sidebar-link-disabled,
.sidebar-link-disabled:hover{
    color:#94a3b8!important;
    background:transparent!important;
    box-shadow:none!important;
    cursor:not-allowed!important;
    text-decoration:none!important;
}

.online-app-link{
    margin-top:5px;
}

.logout-btn-container{
    padding:.75rem;
    background:#1e293b;
}

.logout-btn{
    background:#ff3b5c;
    color:#fff!important;
    font-weight:700;
    font-size:.95rem;
    border-radius:9px;
    padding:.65rem 1rem;
    border:none;
    display:block;
}

.logout-btn:hover{
    background:#e02d4c;
}

@media(max-width:991.98px){
    .left-sidebar{
        transform:translateX(-100%);
        transition:transform .2s ease;
    }

    .left-sidebar.open{
        transform:translateX(0);
    }
}
</style>

<script>
(function () {
    var toggle = document.getElementById('staffProfileToggle');
    var menu = document.getElementById('staffProfileMenu');

    if (!toggle || !menu) return;

    toggle.addEventListener('click', function (event) {
        event.stopPropagation();

        var isOpen = menu.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.user-profile-wrap')) {
            menu.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            menu.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>

