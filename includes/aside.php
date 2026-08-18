<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "conn.php";
// Use the correct session variables
$role = $_SESSION['role'] ?? 'guest';
$username = htmlspecialchars($_SESSION['sessionUsername'] ?? 'Guest'); // <--- corrected
$address = htmlspecialchars($_SESSION['sessionAddress'] ?? '');

// Dynamically set display
switch (strtolower($role)) {
    case 'admin':
        $displayUser = "Admin: $username";
        break;
    case 'user':
        $displayUser = "User: $username";
        break;
    case 'pharmacist':
        $displayUser = "Staff: $username";
        break;
    case 'cashier':
        $displayUser = "Cashier: $username";
        break;
    default:
        $displayUser = "Guest: $username";
        break;
}
?>
<style>
    /* 1. Force the sidebar to a fixed width */
    .left-sidebar {
        width: 250px !important;
        position: fixed !important;
        height: 100vh !important;
        z-index: 1000; /* Sidebar stays behind the header dropdowns */
    }

    /* 2. Force ALL page content to start after the sidebar */
    .page-wrapper {
        margin-left: 250px !important;
        min-height: 100vh !important;
        display: block !important;
        background-color: #f4f7f6 !important;
    }

    /* 3. Mobile Responsive: Ensure everything stacks on small screens */
    @media (max-width: 768px) {
        .page-wrapper {
            margin-left: 0 !important;
        }
        .left-sidebar {
            width: 100% !important;
            position: relative !important;
            height: auto !important;
        }
    }

    <style>
    /* 1. Remove wasted space at the very top of the list */
    #sidebarnav {
        padding-top: 0 !important;
        margin-top: 0 !important;
    }

    /* 2. Shrink the height of each menu item so more fit on screen */
    .sidebar-nav ul li a {
        padding: 8px 15px !important; /* Smaller vertical padding */
    }

    /* 3. Make the user profile section more compact */
    .user-profile {
        padding: 10px 15px !important;
    }

    /* 4. Ensure the sidebar is scrollable if items still overflow */
    .scroll-sidebar {
        height: calc(100vh - 64px) !important;
        overflow-y: auto !important;
    }

    /* 5. Fix the Logout button visibility */
    .upgrade-btn {
        padding: 10px !important;
        margin-top: 10px !important;
    }
</style>
</style>

<aside class="left-sidebar bg-dark">
    <div class="scroll-sidebar">
        <nav class="sidebar-nav">
            <ul id="sidebarnav">
                <!-- User info -->
                <li>
                    <div class="user-profile d-flex no-block dropdown">
                        <div class="user-pic">
                            <img src="assets/images/users/1.jpg" alt="users" class="rounded-circle" width="40" />
                        </div>
                        <div class="user-content hide-menu m-l-10">
                            <h5 class="m-b-0 user-name font-medium text-white"><?php echo $displayUser; ?></h5>
                            <span class="op-5 user-email text-white"><?php echo ucfirst($role); ?></span><br>
                            <span class="op-5 user-email text-white"><?php echo $address; ?></span>
                        </div>
                    </div>
                </li>

                <!-- Quick actions -->
                <<li class="p-10" style="font-weight:700;">
                    <a href="pharmacy_stock.php" class="btn btn-block create-btn text-white no-block d-flex align-items-center">
                        <i class="fa fa-plus-square"></i>
                        <span class="hide-menu m-l-5" style="font-weight:700;">Top up Pharmacy</span>
                    </a>
                </li>

                <!-- Navigation -->
                <li class="sidebar-item" style="font-weight:700;">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link text-white" href="dashboard.php">
                        <i class="mdi mdi-view-dashboard"></i><span class="hide-menu" style="font-weight:700;">Dashboard</span>
                    </a>
                </li>

                <li class="sidebar-item" style="font-weight:700;">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link text-white" href="pharmacy_stock.php">
                        <i class="mdi mdi-clipboard-text"></i><span class="hide-menu" style="font-weight:700;">Pharmacy Stock</span>
                    </a>
                </li>

                <li class="sidebar-item" style="font-weight:700;">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link text-white" href="purchases-orders.php">
                        <i class="mdi mdi-home"></i><span class="hide-menu" style="font-weight:700;">Purchases-orders</span>
                    </a>
                </li>

                <li class="sidebar-item" style="font-weight:700;">
                    <a class="sidebar-link waves-effect waves-red sidebar-link text-white" href="suppliers.php">
                        <i class="mdi mdi-account"></i><span class="hide-menu" style="font-weight:700;">Suppliers</span>
                    </a>
                </li>

                <li class="sidebar-item" style="font-weight:700;">
                    <a href="add_product.php" class="sidebar-link waves-effect waves-red sidebar-link text-white">
                        <i class="mdi mdi-plus-circle"></i><span class="hide-menu" style="font-weight:700;">Add Product</span>
                    </a>
                </li>

                <!-- Register User (admin only) -->
                <?php if (strtolower($role) === 'admin'): ?>
                <li class="sidebar-item" style="font-weight:900;">
                    <a href="register_user.php" class="sidebar-link waves-effect waves-red sidebar-link text-white">
                        <i class="mdi mdi-account-plus"></i><span class="hide-menu" style="font-weight:900;">Register User</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Logout -->
                <li class="text-center p-10 upgrade-btn" style="font-weight:900;">
                <a href="../logout.php" class="btn btn-block btn-danger text-white" style="font-weight:900;">Logout</a>
            </li>
            </ul>
        </nav>
    </div>
</aside>
