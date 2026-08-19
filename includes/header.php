<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 10;
$branch_id   = $_SESSION['branch_id'] ?? 13;

// 1. Fetch Dynamic Pharmacy Name
$pharmacy_name = 'EchoTech'; // Default Fallback
if (isset($conn) && $conn) {
    $pharm_q = mysqli_query($conn, "SELECT name FROM pharmacies WHERE id = '$pharmacy_id'");
    if ($pharm_q && $pharm_data = mysqli_fetch_assoc($pharm_q)) {
        if (!empty($pharm_data['name'])) {
            $pharmacy_name = $pharm_data['name'];
        }
    }
}

// 2. Fetch Dynamic Branch Name
$branch_name = 'Main Branch'; // Default Fallback
if (isset($conn) && $conn) {
    $branch_q = mysqli_query($conn, "SELECT branch_name FROM branches WHERE id = '$branch_id'");
    if ($branch_q && $branch_data = mysqli_fetch_assoc($branch_q)) {
        if (!empty($branch_data['branch_name'])) {
            $branch_name = $branch_data['branch_name'];
        }
    }
}

$today_date = date('d M Y');
?>

<header class="topbar">
    <nav class="navbar top-navbar navbar-expand-md navbar-dark p-0">
        <div class="navbar-header">
            <div class="d-flex align-items-center gap-2">
                <!-- Mobile Hamburger Button -->
                <button class="btn text-white p-0 d-md-none me-2" id="sidebarToggle" type="button" style="font-size: 1.4rem;">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand m-0" href="../dashboard/dashboard.php">
                    <span class="logo-icon"><?php echo htmlspecialchars($pharmacy_name); ?></span>
                </a>
            </div>

            <!-- Compact Search Bar for Mobile view -->
            <div class="mobile-search-bar d-md-none">
                <input type="text" class="form-control form-control-sm bg-white text-dark border-0" placeholder="Search..." style="height: 32px; font-size: 0.8rem;">
            </div>
        </div>

        <!-- Desktop Navigation Bar -->
        <div class="navbar-collapse collapse d-none d-md-flex justify-content-between align-items-center">
            <div class="search-box me-auto" style="width: 380px;">
                <input type="text" class="form-control form-control-sm bg-white text-dark border-0" placeholder="Search products..." style="height: 36px; border-radius: 4px;">
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="../dashboard/dashboard.php" class="btn btn-primary btn-sm px-2 py-1"><i class="fas fa-home"></i></a>
                <span class="text-white small ms-2 me-2"><i class="fas fa-building me-1 text-info"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                <span class="text-white small me-2"><i class="far fa-calendar-alt me-1"></i> <?php echo $today_date; ?></span>
                <a href="sell_now.php" class="btn btn-success btn-sm px-2 py-1"><i class="fas fa-plus"></i></a>
                <a href="../settings.php" class="btn btn-outline-light btn-sm px-2 py-1"><i class="fas fa-cog"></i></a>
            </div>
        </div>
    </nav>
</header>
