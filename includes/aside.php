<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? 19;

// Fetch User Profile Details Dynamically from Table 'users'
$display_name = $_SESSION['username'] ?? 'Jac';
$display_role = 'Pharmacist';

if (isset($conn) && $conn) {
    $user_q = mysqli_query($conn, "SELECT full_name, username, role FROM users WHERE id = '$user_id'");
    if ($user_q && $user_data = mysqli_fetch_assoc($user_q)) {
        if (!empty($user_data['full_name'])) {
            $display_name = $user_data['full_name'];
        } elseif (!empty($user_data['username'])) {
            $display_name = $user_data['username'];
        }
        
        if (!empty($user_data['role'])) {
            $display_role = $user_data['role'];
        }
    }
}
?>

<aside class="left-sidebar">
    <div>
        <!-- High Contrast Dynamic Staff Profile Container -->
        <div class="user-profile-box">
            <div class="user-avatar">
                <i class="fas fa-user-tie"></i>
            </div>
            <div>
                <div class="fw-bold small text-white">Staff: <?php echo htmlspecialchars($display_name); ?></div>
                <div class="extra-small" style="color: #92a4b5 !important; font-size: 0.78rem;"><?php echo htmlspecialchars($display_role); ?></div>
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
                    <a class="sidebar-link" href="purchase_orders_list.php">
                        <i class="mdi mdi-cart-outline"></i><span>Purchase-orders</span>
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
                <a href="../../stock_transfer.php" class="sidebar-link">
    <i class="fas fa-exchange-alt"></i> Stock Transfers
</a>
            </ul>
        </nav>
    </div>

    <!-- Fixed Bright Red Logout Button -->
    <div class="logout-btn-container">
        <a href="../logout.php" class="btn logout-btn w-100 text-center text-decoration-none">Logout</a>
    </div>
</aside>
