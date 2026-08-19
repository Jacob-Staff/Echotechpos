<aside class="left-sidebar">
    <div>
        <!-- Staff Profile Container -->
        <div class="user-profile-box">
            <div class="user-avatar">
                <i class="fas fa-user-tie"></i>
            </div>
            <div>
                <div class="text-white fw-bold small">Staff: <?php echo htmlspecialchars($_SESSION['username'] ?? 'Jac'); ?></div>
                <div class="text-muted extra-small" style="font-size: 0.75rem;">Pharmacist</div>
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

    <!-- Fixed Logout Button -->
    <div class="logout-btn-container">
        <a href="../logout.php" class="btn btn-danger w-100 fw-bold py-2">Logout</a>
    </div>
</aside>
