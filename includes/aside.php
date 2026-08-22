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
                    <a class="sidebar-link <?= is_active_menu('top_up_pharmacy.php', $current_page); ?>" href="top_up_pharmacy.php">
                        <i class="mdi mdi-plus-box-outline me-2"></i><span>Top up Pharmacy</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('dashboard.php', $current_page); ?>" href="../dashboard/dashboard.php">
                        <i class="mdi mdi-view-dashboard me-2"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('pharmacy_stock.php', $current_page); ?>" href="pharmacy_stock.php">
                        <i class="mdi mdi-package-variant me-2"></i><span>Pharmacy Stock</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('purchase_orders_list.php', $current_page); ?>" href="purchase_orders_list.php">
                        <i class="mdi mdi-cart-outline me-2"></i><span>Purchase-orders</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('suppliers.php', $current_page); ?>" href="suppliers.php">
                        <i class="mdi mdi-account-group me-2"></i><span>Suppliers</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('add_product.php', $current_page); ?>" href="add_product.php">
                        <i class="mdi mdi-plus-circle-outline me-2"></i><span>Add Product</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('stock_transfer.php', $current_page); ?>" href="stock_transfer.php">
                        <i class="fas fa-exchange-alt me-2"></i><span>Stock Transfers</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link <?= is_active_menu('shift_log.php', $current_page); ?>" href="shift_log.php">
                        <i class="fas fa-user-clock me-2"></i><span>Duty & Shift Log</span>
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
