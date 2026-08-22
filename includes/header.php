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
        
        <!-- 1. LEFT BRAND / SIDEBAR HEADER ZONE -->
        <div class="navbar-header d-flex align-items-center">
            <a class="nav-toggler waves-effect waves-light d-block d-md-none text-white me-2 ps-2" id="sidebarToggle" href="javascript:void(0)">
                <i class="fas fa-bars fs-5"></i>
            </a>
            <a class="navbar-brand ms-2" href="../dashboard/dashboard.php">
                <span class="logo-text fw-bold text-white fs-5"><?php echo htmlspecialchars($pharmacy_name); ?></span>
            </a>
            <!-- Mobile Settings Button Trigger -->
            <button class="btn btn-link text-white d-md-none ms-auto p-1 pe-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas" aria-controls="settingsOffcanvas">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <!-- 2. RIGHT CONTENT / SEARCH / QUICK ACTIONS ZONE -->
        <div class="navbar-collapse collapse" id="navbarSupportedContent">
            <!-- Search Bar -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <div class="search-box py-2 px-3" style="min-width: 300px;">
                        <input type="text" class="form-control form-control-sm bg-white text-dark border-0" placeholder="Search products..." style="height: 34px; border-radius: 4px;">
                    </div>
                </li>
            </ul>

            <!-- Right Actions & Navigation Icons -->
            <ul class="navbar-nav my-lg-0 d-flex align-items-center gap-2 pe-3">
                <li class="nav-item">
                    <a href="../dashboard/dashboard.php" class="btn btn-primary btn-sm px-2 py-1" title="Home"><i class="fas fa-home"></i></a>
                </li>
                <li class="nav-item text-white small px-1">
                    <i class="fas fa-building text-info me-1"></i> <?php echo htmlspecialchars($branch_name); ?>
                </li>
                <li class="nav-item text-white small px-1">
                    <i class="far fa-calendar-alt me-1"></i> <?php echo $today_date; ?>
                </li>
                <li class="nav-item">
                    <a href="sell_now.php" class="btn btn-success btn-sm px-2 py-1" title="Sell Now"><i class="fas fa-plus"></i></a>
                </li>
                <li class="nav-item">
                    <!-- Offcanvas Drawer Trigger -->
                    <button class="btn btn-outline-light btn-sm px-2 py-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas" aria-controls="settingsOffcanvas" title="Settings">
                        <i class="fas fa-cog"></i>
                    </button>
                </li>
            </ul>
        </div>

    </nav>
</header>

<!-- Global Settings Offcanvas Element (Available to every page using header.php) -->
<div class="offcanvas offcanvas-end text-dark" tabindex="-1" id="settingsOffcanvas" aria-labelledby="settingsOffcanvasLabel">
  <div class="offcanvas-header bg-dark text-white">
    <h5 class="offcanvas-title" id="settingsOffcanvasLabel"><i class="fas fa-cog me-2"></i>Settings & Preferences</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="mb-3">
        <label class="form-label fw-bold">Active Branch</label>
        <p class="text-muted small mb-1">Currently operating in <?php echo htmlspecialchars($branch_name); ?></p>
    </div>
    <hr>
    <div class="mb-3">
        <label class="form-label fw-bold">System Quick Links</label>
        <ul class="list-group list-group-flush small">
            <li class="list-group-item"><a href="../dashboard/dashboard.php" class="text-decoration-none text-dark"><i class="fas fa-tachometer-alt me-2 text-primary"></i> Main Dashboard</a></li>
            <li class="list-group-item"><a href="pharmacy_stock.php" class="text-decoration-none text-dark"><i class="fas fa-boxes me-2 text-success"></i> Stock Overview</a></li>
            <li class="list-group-item"><a href="shift_log.php" class="text-decoration-none text-dark"><i class="fas fa-user-clock me-2 text-warning"></i> Duty Log</a></li>
        </ul>
    </div>
  </div>
</div>

<!-- Embedded Global JS Handler for Navigation & Settings -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    // 1. Sidebar Mobile Toggle Handler
    const sidebarToggle = document.getElementById("sidebarToggle");
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", function (e) {
            e.preventDefault();
            const sidebar = document.querySelector(".left-sidebar");
            if (sidebar) {
                sidebar.classList.toggle("show-sidebar");
            }
        });
    }

    // 2. Offcanvas Fallback Handler for Pages Missing Direct Bootstrap Triggers
    const settingsButtons = document.querySelectorAll('[data-bs-target="#settingsOffcanvas"]');
    const settingsOffcanvasEl = document.getElementById('settingsOffcanvas');
    
    if (settingsOffcanvasEl) {
        settingsButtons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                    const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(settingsOffcanvasEl);
                    bsOffcanvas.toggle();
                }
            });
        });
    }
});
</script>
