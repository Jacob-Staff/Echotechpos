<?php
// A simple function to check if a menu item is active
function is_active($page_name) {
    $current_page = basename($_SERVER['PHP_SELF']);
    return ($current_page == $page_name) ? 'active' : '';
}

// Get user info from the correct session keys
$user_role = current_role(); // Using function from auth.php
$user_display_name = htmlspecialchars(current_user()); 
?>

<aside class="left-sidebar" data-sidebarbg="skin6">
    <div class="scroll-sidebar">
        <nav class="sidebar-nav">
            <ul id="sidebarnav">
                <li>
                    <div class="user-profile d-flex no-block align-items-center p-20">
                        <div class="user-pic">
                            <img src="assets/images/users/1.jpg" alt="users" class="rounded-circle" width="40" />
                        </div>
                        <div class="user-content hide-menu ml-2">
                            <h5 class="mb-0 user-name font-medium"><?php echo $user_display_name; ?></h5>
                            <small class="text-muted"><?php echo $user_role; ?></small>
                        </div>
                    </div>
                </li>
                
                <li class="sidebar-item">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link <?php echo is_active('dashboard.php'); ?>"
                        href="dashboard.php" aria-expanded="false">
                        <i class="mdi mdi-view-dashboard"></i>
                        <span class="hide-menu">Dashboard</span>
                    </a>
                </li>
                
                <?php if (has_permission('Inventory')): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link <?php echo is_active('pharmacy_stock.php'); ?>"
                        href="pharmacy_stock.php" aria-expanded="false">
                        <i class="mdi mdi-pill"></i>
                        <span class="hide-menu">Pharmacy Stock</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($user_role === 'Admin' || $user_role === 'Manager'): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link <?php echo is_active('add_product.php'); ?>"
                        href="add_product.php" aria-expanded="false">
                        <i class="mdi mdi-plus-circle"></i>
                        <span class="hide-menu">Add Product</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($user_role === 'Admin'): ?>
                <li class="sidebar-item">
                    <a class="sidebar-link waves-effect waves-dark sidebar-link <?php echo is_active('staff_management.php'); ?>"
                        href="staff_management.php" aria-expanded="false">
                        <i class="fas fa-user-shield"></i>
                        <span class="hide-menu">Staff & Permissions</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <li class="sidebar-item border-top mt-3">
                    <a href="../logout.php" class="sidebar-link waves-effect waves-dark text-danger">
                        <i class="fas fa-power-off text-danger"></i>
                        <span class="hide-menu">Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>
