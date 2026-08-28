<?php
/**
 * ============================================================
 * EchoTech POS - Protected Sidebar
 * ============================================================
 * Uses the SAME page names and routes as Staff Management.
 * Links disappear when the logged-in role loses page access.
 * Direct URL access is separately enforced by includes/auth.php.
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
$display_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['sessionUsername'] ?? 'Staff';
$display_role = $_SESSION['role'] ?? 'Staff';

if (isset($conn) && $conn instanceof mysqli && $user_id > 0) {
    try {
        $stmt = $conn->prepare('SELECT full_name, username, role FROM users WHERE id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $display_name = $row['full_name'] ?: ($row['username'] ?: $display_name);
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

function echotech_sidebar_can(string $page): bool
{
    if (!function_exists('has_page_access')) {
        return true;
    }

    return has_page_access($page);
}
?>

<aside class="left-sidebar" id="echotechSidebar">
    <div class="sidebar-inner">

        <div class="user-profile-box">
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
        </div>

        <nav class="sidebar-nav pt-1">
            <ul class="sidebarnav list-unstyled mb-0">

                <li class="sidebar-section">MAIN</li>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('dashboard.php'); ?>"
                       href="dashboard.php">
                        <i class="mdi mdi-view-dashboard-outline"></i><span>Dashboard</span>
                    </a>
                </li>

                <?php if (echotech_sidebar_can('Sale now')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('sell_now.php'); ?>" href="sell_now.php">
                        <i class="mdi mdi-cash-register"></i><span>Sale Now</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Today transaction')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('today_transactions.php'); ?>" href="today_transactions.php">
                        <i class="mdi mdi-receipt-text-outline"></i><span>Today's Transaction</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Lay by sale')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('lay_by_sell.php'); ?>" href="lay_by_sell.php">
                        <i class="mdi mdi-credit-card-clock-outline"></i><span>Lay By Sale</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Customer')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('customers.php'); ?>" href="customers.php">
                        <i class="mdi mdi-account-group-outline"></i><span>Customer</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Add patient')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('add_patients.php'); ?>" href="add_patients.php">
                        <i class="mdi mdi-account-plus-outline"></i><span>Add Patient</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-section">INVENTORY</li>

                <?php if (echotech_sidebar_can('Pharmacy stock')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('pharmacy_stock.php'); ?>" href="pharmacy_stock.php">
                        <i class="mdi mdi-package-variant-closed"></i><span>Pharmacy Stock</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Out of stock')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('out_of_stock.php'); ?>" href="out_of_stock.php">
                        <i class="mdi mdi-package-variant-remove"></i><span>Out of Stock</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Expired products')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('expired_products.php'); ?>" href="expired_products.php">
                        <i class="mdi mdi-calendar-remove-outline"></i><span>Expired Products</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Restock')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('restock.php'); ?>" href="restock.php">
                        <i class="mdi mdi-package-up"></i><span>Restock</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Add Product')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('add_product.php'); ?>" href="add_product.php">
                        <i class="mdi mdi-plus-circle-outline"></i><span>Add Product</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Stock exchange')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('stock_transfer.php'); ?>" href="stock_transfer.php">
                        <i class="fas fa-exchange-alt"></i><span>Stock Exchange</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-section">PURCHASING</li>

                <?php if (echotech_sidebar_can('Purchases orders')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('purchase_orders.php'); ?>" href="purchase_orders.php">
                        <i class="mdi mdi-cart-plus"></i><span>Purchase Orders</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Purchases order list')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('purchase_orders_list.php'); ?>" href="purchase_orders_list.php">
                        <i class="mdi mdi-format-list-bulleted-square"></i><span>Purchase Order List</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Supplier')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('suppliers.php'); ?>" href="suppliers.php">
                        <i class="mdi mdi-truck-outline"></i><span>Supplier</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-section">ONLINE</li>

                <?php if (echotech_sidebar_can('Online manager')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('online_manager.php'); ?>" href="online_manager.php">
                        <i class="mdi mdi-web"></i><span>Online Manager</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Online orders')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('online_orders.php'); ?>" href="online_orders.php">
                        <i class="mdi mdi-cart-arrow-down"></i><span>Online Orders</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-section">REPORTS & OPERATIONS</li>

                <?php if (echotech_sidebar_can('Sales report')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('sales_report.php'); ?>" href="sales_report.php">
                        <i class="mdi mdi-chart-line"></i><span>Sales Report</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Expenses sales trend')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('expenses.php'); ?>" href="expenses.php">
                        <i class="mdi mdi-wallet-outline"></i><span>Expenses</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('sales_trend.php'); ?>" href="sales_trend.php">
                        <i class="mdi mdi-trending-up"></i><span>Sales Trend</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Shift log')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('shift_log.php'); ?>" href="shift_log.php">
                        <i class="mdi mdi-clock-outline"></i><span>Shift Log</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (echotech_sidebar_can('Settings')): ?>
                <li class="sidebar-section">SYSTEM</li>
                <li class="sidebar-item">
                    <a class="sidebar-link <?= echotech_sidebar_active('settings.php'); ?>" href="settings.php">
                        <i class="mdi mdi-cog-outline"></i><span>Settings</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="sidebar-item online-app-link">
                    <a class="sidebar-link" href="../api/login_client.php">
                        <i class="mdi mdi-cellphone-link"></i><span>Online App</span>
                    </a>
                </li>

            </ul>
        </nav>
    </div>

    <div class="logout-btn-container">
        <a href="../logout.php" class="btn logout-btn w-100 text-center text-decoration-none">
            <i class="mdi mdi-logout me-1"></i> Logout
        </a>
    </div>
</aside>

<style>
.left-sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:#1e293b;z-index:1000;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;overflow:hidden}
.sidebar-inner{flex:1;overflow-y:auto;padding:1rem .75rem .5rem;box-sizing:border-box}
.sidebar-inner::-webkit-scrollbar{width:0;background:transparent}
.user-profile-box{display:flex;align-items:center;gap:10px;padding:.75rem;background:#0f172a;border-radius:8px;margin-bottom:.75rem}
.user-avatar{width:36px;height:36px;border-radius:50%;background:#334155;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.sidebar-role{color:#92a4b5!important;font-size:.76rem}
.sidebar-section{padding:9px 10px 5px;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.08em}
.sidebar-link{display:flex;align-items:center;gap:10px;padding:.55rem .8rem;color:#94a3b8;text-decoration:none;border-radius:7px;font-size:.84rem;transition:all .16s ease}
.sidebar-link i{width:18px;text-align:center;font-size:16px;flex-shrink:0}
.sidebar-link:hover,.sidebar-link.active{color:#fff;background:#334155}
.sidebar-link.active{box-shadow:inset 3px 0 #60a5fa}
.online-app-link{margin-top:5px}
.logout-btn-container{padding:.75rem;background:#1e293b}
.logout-btn{background:#ff3b5c;color:#fff!important;font-weight:700;font-size:.95rem;border-radius:9px;padding:.65rem 1rem;border:none;display:block}
.logout-btn:hover{background:#e02d4c}
@media(max-width:991.98px){.left-sidebar{transform:translateX(-100%);transition:transform .2s ease}.left-sidebar.open{transform:translateX(0)}}
</style>
