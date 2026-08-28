<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? 19);

// Fetch User Profile Details Safely
$display_name = $_SESSION['username'] ?? 'Jac';
$display_role = $_SESSION['role'] ?? 'Pharmacist';

if (isset($conn) && $conn) {
    $user_stmt = $conn->prepare("SELECT full_name, username, role FROM users WHERE id = ? LIMIT 1");
    if ($user_stmt) {
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_res = $user_stmt->get_result();
        if ($user_data = $user_res->fetch_assoc()) {
            if (!empty($user_data['full_name'])) {
                $display_name = $user_data['full_name'];
            } elseif (!empty($user_data['username'])) {
                $display_name = $user_data['username'];
            }
            if (!empty($user_data['role'])) {
                $display_role = $user_data['role'];
            }
        }
        $user_stmt->close();
    }
}

// Active link helper function
$current_page = basename($_SERVER['PHP_SELF']);
function is_active_menu($page_name, $current_page) {
    return ($current_page === $page_name) ? 'active' : '';
}

/*
 * Permission-aware link state.
 * IMPORTANT: links NEVER disappear. When Access is OFF they remain
 * visible but become dormant/disabled. The actual page itself is
 * protected by auth.php.
 */
function sidebar_page_allowed(string $page_name): bool {
    if (function_exists('has_page_access')) {
        return has_page_access($page_name);
    }
    return true;
}

function sidebar_link_class(string $page_name): string {
    return sidebar_page_allowed($page_name) ? '' : ' dormant';
}

function sidebar_link_href(string $page_name, string $href): string {
    return sidebar_page_allowed($page_name) ? $href : '#';
}

function sidebar_link_attrs(string $page_name): string {
    if (sidebar_page_allowed($page_name)) {
        return '';
    }

    return ' aria-disabled="true" title="Access disabled for your role"';
}
?>

<aside class="left-sidebar">
    <div class="sidebar-inner">
        <!-- Dynamic Staff Profile Box -->
        <div class="user-profile-box">
            <div class="user-avatar">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="user-info text-truncate">
                <div class="fw-bold small text-white text-truncate">Staff: <?= htmlspecialchars($display_name); ?></div>
                <div class="extra-small text-truncate" style="color: #92a4b5 !important; font-size: 0.78rem;"><?= htmlspecialchars($display_role); ?></div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <nav class="sidebar-nav pt-2">
            <ul id="sidebarnav" class="list-unstyled mb-0">

                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('dashboard.php', $current_page); ?>" href="../dashboard/dashboard.php">
                        <i class="mdi mdi-view-dashboard me-2"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('pharmacy_stock.php', $current_page); ?><?= sidebar_link_class('Pharmacy stock'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Pharmacy stock', 'pharmacy_stock.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Pharmacy stock'); ?>>
                        <i class="mdi mdi-package-variant me-2"></i><span>Pharmacy Stock</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('purchase_orders_list.php', $current_page); ?><?= sidebar_link_class('Purchases order list'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Purchases order list', 'purchase_orders_list.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Purchases order list'); ?>>
                        <i class="mdi mdi-cart-outline me-2"></i><span>Purchase-orders</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('suppliers.php', $current_page); ?><?= sidebar_link_class('Supplier'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Supplier', 'suppliers.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Supplier'); ?>>
                        <i class="mdi mdi-account-group me-2"></i><span>Suppliers</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('add_product.php', $current_page); ?><?= sidebar_link_class('Add Product'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Add Product', 'add_product.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Add Product'); ?>>
                        <i class="mdi mdi-plus-circle-outline me-2"></i><span>Add Product</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('stock_transfer.php', $current_page); ?><?= sidebar_link_class('Stock exchange'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Stock exchange', 'stock_transfer.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Stock exchange'); ?>>
                        <i class="fas fa-exchange-alt me-2"></i><span>Stock Transfers</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link<?= is_active_menu('shift_log.php', $current_page); ?><?= sidebar_link_class('Shift log'); ?>" href="<?= htmlspecialchars(sidebar_link_href('Shift log', 'shift_log.php'), ENT_QUOTES, 'UTF-8'); ?>"<?= sidebar_link_attrs('Shift log'); ?>>
                        <i class="fas fa-user-clock me-2"></i><span>Duty & Shift Log</span>
                    </a>
                </li>
                                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('login_client.php', $current_page); ?>" href="../api/login_client.php">
                        <i class="mdi mdi-plus-box-outline me-2"></i><span>Online App</span>
                    </a> 
                </li>
            </ul>
        </nav>
    </div>

    <!-- Original Fixed Bright Red Logout Button -->
    <div class="logout-btn-container">
        <a href="../logout.php" class="btn logout-btn w-100 text-center text-decoration-none">Logout</a>
    </div>
</aside>

<style>
/* CSS Reset for Sidebar Isolation */
.left-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 240px;
    height: 100vh;
    background-color: #1e293b;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
}

.sidebar-inner {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 0.75rem 0.5rem 0.75rem;
    box-sizing: border-box;
}

/* Hide scrollbar visually while retaining smooth scrolling */
.sidebar-inner::-webkit-scrollbar {
    width: 0px;
    background: transparent;
}

.user-profile-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0.75rem;
    background-color: #0f172a;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #334155;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.sidebar-link {
    display: flex;
    align-items: center;
    padding: 0.6rem 0.85rem;
    color: #94a3b8;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

.sidebar-link:hover, .sidebar-link.active {
    color: #ffffff;
    background-color: #334155;
}

/* Dormant links remain visible when Access is OFF */
.sidebar-link.dormant {
    color: #566575 !important;
    background: transparent !important;
    opacity: 0.55;
    cursor: not-allowed;
}
.sidebar-link.dormant i {
    color: #536171 !important;
}
.sidebar-link.dormant:hover {
    color: #566575 !important;
    background: transparent !important;
}

/* Restored Original Logout Button Styling */
.logout-btn-container {
    padding: 0.75rem;
    background-color: #1e293b;
    box-sizing: border-box;
}

.logout-btn {
    background-color: #ff3b5c;
    color: #ffffff !important;
    font-weight: 700;
    font-size: 1.05rem;
    border-radius: 10px;
    padding: 0.75rem 1rem;
    border: none;
    display: block;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.logout-btn:hover {
    background-color: #e02d4c;
}
</style>
