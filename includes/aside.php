<?php
$user_id = $_SESSION['user_id'] ?? 19;
$user_q = mysqli_query($conn, "SELECT full_name, username, role, profile_pic FROM users WHERE id = '$user_id'");
$user_data = mysqli_fetch_assoc($user_q);

$display_name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'User');
$display_role = $user_data['role'] ?? 'Staff';
?>

<div class="user-profile-box">
    <div class="user-avatar">
        <i class="fas fa-user"></i>
    </div>
    <div class="user-info">
        <h6 class="mb-0 text-white fw-bold"><?php echo htmlspecialchars($display_name); ?></h6>
        <small class="text-muted"><?php echo htmlspecialchars($display_role); ?></small>
    </div>
</div>

<aside class="left-sidebar">
    <div>
        <!-- High Contrast Staff Profile Container -->
        <div class="user-profile-box">
            <div class="user-avatar">
                <i class="fas fa-user-tie"></i>
            </div>
            <div>
                <div class="fw-bold small" style="color: #ffffff !important;">Staff: <?php echo htmlspecialchars($_SESSION['username'] ?? 'Jac'); ?></div>
                <div class="extra-small" style="color: #92a4b5 !important; font-size: 0.78rem;">Pharmacist</div>
            </div>
        </div>

        <nav class="sidebar-nav pt-2">
            <ul id="sidebarnav" class="list-unstyled mb-0">
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link" href="top_up_pharmacy.php">
                        <i class="mdi mdi-plus-box-outline"></i><span>Top up Pharmacy</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link active" href="../dashboard/dashboard.php">
                        <i class="mdi mdi-view-dashboard"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link" href="pharmacy_stock.php">
                        <i class="mdi mdi-package-variant"></i><span>Pharmacy Stock</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link" href="purchase_orders.php">
                        <i class="mdi mdi-cart-outline"></i><span>Purchases-orders</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link" href="suppliers.php">
                        <i class="mdi mdi-account-group"></i><span>Suppliers</span>
                    </a>
                </li>
                <li class="sidebar-item mb-1">
                    <a class="sidebar-link" href="add_product.php">
                        <i class="mdi mdi-plus-circle-outline"></i><span>Add Product</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- Fixed Bright Red Logout Button -->
    <div class="logout-btn-container">
        <a href="../logout.php" class="btn logout-btn w-100 text-center text-decoration-none">Logout</a>
    </div>
</aside>
