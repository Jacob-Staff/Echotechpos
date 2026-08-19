<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Retrieve user info safely[cite: 9]
$role = $_SESSION['role'] ?? 'guest';[cite: 9]
$username = htmlspecialchars($_SESSION['sessionUsername'] ?? $_SESSION['username'] ?? 'Guest');[cite: 9]

// Helper function to set active tab link
function is_active($page_name) {
    $current_page = basename($_SERVER['PHP_SELF']);
    return ($current_page == $page_name) ? 'active' : '';
}

// Formatted display user label
switch (strtolower($role)) {
    case 'admin':
        $displayUser = "Admin: $username";[cite: 9]
        break;
    case 'pharmacist':
        $displayUser = "Staff: $username";[cite: 9]
        break;
    case 'cashier':
        $displayUser = "Cashier: $username";[cite: 9]
        break;
    default:
        $displayUser = "Staff: $username";[cite: 9]
        break;
}
?>

<aside class="left-sidebar bg-dark">[cite: 9]
    <div class="scroll-sidebar p-3">
        
        <!-- User Profile Info Header -->
        <div class="user-profile d-flex align-items-center mb-3 p-2 rounded" style="background: rgba(255, 255, 255, 0.05);">
            <div class="me-2">
                <img src="assets/images/users/1.jpg" alt="users" class="rounded-circle" width="40" onError="this.style.display='none';" />[cite: 8, 9]
            </div>
            <div class="user-content text-white">
                <h6 class="mb-0 fw-bold text-truncate" style="max-width: 150px;"><?= $displayUser; ?></h6>[cite: 9]
                <small class="text-white-50" style="font-size: 0.8rem;"><?= ucfirst($role); ?></small>[cite: 9]
            </div>
        </div>

        <!-- Quick Action Top-Up Button -->
        <div class="mb-3">
            <a href="pharmacy_stock.php" class="btn btn-info w-100 text-white fw-bold d-flex align-items-center justify-content-center py-2">[cite: 9]
                <i class="fa fa-plus-square me-2"></i>
                <span>Top up Pharmacy</span>[cite: 9]
            </a>
        </div>

        <!-- Sidebar Navigation List -->
        <nav class="sidebar-nav">
            <ul id="sidebarnav" class="list-unstyled mb-0">[cite: 8, 9]
                
                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('dashboard.php'); ?>" href="dashboard.php">[cite: 8, 9]
                        <i class="mdi mdi-view-dashboard fs-5 me-3" style="width: 20px;"></i>[cite: 8, 9]
                        <span class="fw-bold">Dashboard</span>[cite: 8, 9]
                    </a>
                </li>

                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('pharmacy_stock.php'); ?>" href="pharmacy_stock.php">[cite: 8, 9]
                        <i class="mdi mdi-clipboard-text fs-5 me-3" style="width: 20px;"></i>[cite: 9]
                        <span class="fw-bold">Pharmacy Stock</span>[cite: 8, 9]
                    </a>
                </li>

                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('purchases-orders.php'); ?>" href="purchases-orders.php">[cite: 9]
                        <i class="mdi mdi-home fs-5 me-3" style="width: 20px;"></i>[cite: 9]
                        <span class="fw-bold">Purchases-orders</span>[cite: 9]
                    </a>
                </li>

                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('suppliers.php'); ?>" href="suppliers.php">[cite: 9]
                        <i class="mdi mdi-account fs-5 me-3" style="width: 20px;"></i>[cite: 9]
                        <span class="fw-bold">Suppliers</span>[cite: 9]
                    </a>
                </li>

                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('add_product.php'); ?>" href="add_product.php">[cite: 8, 9]
                        <i class="mdi mdi-plus-circle fs-5 me-3" style="width: 20px;"></i>[cite: 8, 9]
                        <span class="fw-bold">Add Product</span>[cite: 8, 9]
                    </a>
                </li>

                <?php if (strtolower($role) === 'admin'): ?>[cite: 9]
                <li class="sidebar-item mb-2">
                    <a class="sidebar-link text-white text-decoration-none d-flex align-items-center p-2 rounded hover-bg <?= is_active('register_user.php'); ?>" href="register_user.php">[cite: 9]
                        <i class="mdi mdi-account-plus fs-5 me-3" style="width: 20px;"></i>[cite: 9]
                        <span class="fw-bold">Register User</span>[cite: 9]
                    </a>
                </li>
                <?php endif; ?>

                <!-- Logout -->
                <li class="sidebar-item mt-4">
                    <a href="../logout.php" class="btn btn-danger w-100 text-white fw-bold d-flex align-items-center justify-content-center py-2">[cite: 8, 9]
                        <i class="fas fa-power-off me-2"></i> Logout[cite: 8]
                    </a>
                </li>

            </ul>
        </nav>

    </div>
</aside>

<style>
.sidebar-nav a.hover-bg:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #00ffd0 !important;
}
.sidebar-nav a.active {
    background: rgba(255, 255, 255, 0.15) !important;
    color: #00ffd0 !important;
}
</style>
