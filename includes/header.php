<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 10);
$branch_id   = (int)($_SESSION['branch_id'] ?? 13);

// 1. Fetch Dynamic Pharmacy Name Safely
$pharmacy_name = 'EchoTech'; // Default Fallback
if (isset($conn) && $conn) {
    $pharm_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
    if ($pharm_stmt) {
        $pharm_stmt->bind_param("i", $pharmacy_id);
        $pharm_stmt->execute();
        $pharm_res = $pharm_stmt->get_result();
        if ($pharm_data = $pharm_res->fetch_assoc()) {
            if (!empty($pharm_data['name'])) {
                $pharmacy_name = $pharm_data['name'];
            }
        }
        $pharm_stmt->close();
    }
}

// 2. Fetch Dynamic Branch Name Safely
$branch_name = 'Main Branch'; // Default Fallback
if (isset($conn) && $conn) {
    $branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param("i", $branch_id);
        $branch_stmt->execute();
        $branch_res = $branch_stmt->get_result();
        if ($branch_data = $branch_res->fetch_assoc()) {
            if (!empty($branch_data['branch_name'])) {
                $branch_name = $branch_data['branch_name'];
            }
        }
        $branch_stmt->close();
    }
}

$today_date = date('d M Y');
?>

<header class="topbar">
    <nav class="navbar top-navbar navbar-expand-md navbar-dark p-0">
        
        <!-- 1. LEFT BRAND / SIDEBAR HEADER ZONE -->
        <div class="navbar-header d-flex align-items-center px-3">
            <a class="nav-toggler waves-effect waves-light d-block d-md-none text-white me-2" id="sidebarToggle" href="javascript:void(0)">
                <i class="fas fa-bars fs-5"></i>
            </a>
            <a class="navbar-brand m-0 text-truncate" href="../dashboard/dashboard.php">
                <span class="logo-text fw-bold text-white fs-5"><?php echo htmlspecialchars($pharmacy_name); ?></span>
            </a>
            <!-- Mobile Settings Button Trigger -->
            <button class="btn btn-link text-white d-md-none ms-auto p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas" aria-controls="settingsOffcanvas">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <!-- 2. RIGHT CONTENT / SEARCH / QUICK ACTIONS ZONE -->
        <div class="navbar-collapse collapse d-flex align-items-center justify-content-between px-3" id="navbarSupportedContent">
            <!-- Search Bar -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <div class="search-box py-2" style="min-width: 280px; max-width: 400px;">
                        <input type="text" class="form-control form-control-sm bg-white text-dark border-0 shadow-none" placeholder="Search products..." style="height: 34px; border-radius: 4px;">
                    </div>
                </li>
            </ul>

            <!-- Right Actions & Navigation Icons -->
            <ul class="navbar-nav my-lg-0 d-flex align-items-center gap-2 m-0">
                <li class="nav-item">
                    <a href="../dashboard/dashboard.php" class="btn btn-primary btn-sm px-2 py-1" title="Home"><i class="fas fa-home"></i></a>
                </li>
                <li class="nav-item text-white small px-1 text-nowrap">
                    <i class="fas fa-building text-info me-1"></i> <?php echo htmlspecialchars($branch_name); ?>
                </li>
                <li class="nav-item text-white small px-1 text-nowrap">
                    <i class="far fa-calendar-alt me-1"></i> <?php echo $today_date; ?>
                </li>
                <li class="nav-item">
                    <a href="sell_now.php" class="btn btn-success btn-sm px-2 py-1" title="Sell Now"><i class="fas fa-plus"></i></a>
                </li>
                <li class="nav-item">
                    <button class="btn btn-outline-light btn-sm px-2 py-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas" aria-controls="settingsOffcanvas" title="Settings">
                        <i class="fas fa-cog"></i>
                    </button>
                </li>
            </ul>
        </div>

    </nav>
</header>

<style>
/* Layout Lock Styles for Topbar Header */
.topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 60px;
    z-index: 1050;
    background-color: #334155;
}

.top-navbar {
    height: 60px;
}

.navbar-header {
    width: 240px;
    height: 60px;
    background-color: #1e293b;
    flex-shrink: 0;
}

.navbar-collapse {
    height: 60px;
    background-color: #334155;
}

/* Ensure Page Wrapper accounts for top header offset */
body {
    padding-top: 60px !important;
}
</style>
