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
        <div class="navbar-header">
            <a class="nav-toggler waves-effect waves-light d-block d-md-none text-white me-2" id="sidebarToggle" href="javascript:void(0)">
                <i class="fas fa-bars fs-5"></i>
            </a>
            <a class="navbar-brand ms-2" href="../dashboard/dashboard.php">
                <span class="logo-text fw-bold text-white fs-5"><?php echo htmlspecialchars($pharmacy_name); ?></span>
            </a>
            <!-- Mobile Settings Button Trigger -->
            <button class="btn btn-link text-white d-md-none ms-auto p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas" aria-controls="settingsOffcanvas">
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

<!-- Global Settings Offcanvas Drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="settingsOffcanvas" aria-labelledby="settingsOffcanvasLabel" style="width: 320px;">
    <div class="offcanvas-header border-bottom py-3">
        <div class="w-100 text-center">
            <h5 class="offcanvas-title fw-bold mb-0 text-dark" id="settingsOffcanvasLabel">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Jac'); ?></h5>
            <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.5px;"><?php echo htmlspecialchars($pharmacy_name); ?></small>
        </div>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body p-3">
        <!-- Assigned Branch Info Box -->
        <div class="card bg-light border-primary mb-4">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 text-primary fw-semibold mb-1 small">
                    <i class="fas fa-lock"></i> Assigned Branch
                </div>
                <div class="fw-bold text-dark"><?php echo htmlspecialchars($branch_name); ?></div>
            </div>
        </div>

        <!-- User Configuration Form -->
        <form action="../update_settings.php" method="POST">
            <h6 class="fw-bold text-primary mb-3 small">Configure User Details</h6>

            <div class="mb-3">
                <label class="form-label text-muted small fw-semibold mb-1">Mobile Number</label>
                <input type="text" name="mobile" class="form-control form-control-sm bg-light" value="0974140989">
            </div>

            <div class="mb-3">
                <label class="form-label text-muted small fw-semibold mb-1">New Password</label>
                <input type="password" name="password" class="form-control form-control-sm bg-light" placeholder="Leave blank to keep current">
            </div>

            <button type="submit" class="btn btn-primary btn-sm w-100 py-2 fw-semibold mb-4" style="background-color: #6c5ce7; border: none;">Update Profile</button>

            <!-- POS Configuration Section -->
            <h6 class="fw-bold text-dark mb-3 small">POS Configuration</h6>

            <div class="mb-3">
                <label class="form-label text-muted small fw-semibold mb-1">Currency</label>
                <input type="text" name="currency" class="form-control form-control-sm bg-light" value="ZMW">
            </div>

            <div class="mb-3">
                <label class="form-label text-muted small fw-semibold mb-1">Tax Rate (%)</label>
                <input type="number" name="tax_rate" class="form-control form-control-sm bg-light" value="16">
            </div>
        </form>
    </div>
</div>

<!-- Global Mobile Navigation Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            document.body.classList.toggle('mobile-nav-open');
        });
    }

    document.addEventListener('click', function(e) {
        if (document.body.classList.contains('mobile-nav-open') && 
            !e.target.closest('.left-sidebar') && 
            !e.target.closest('#sidebarToggle')) {
            document.body.classList.remove('mobile-nav-open');
        }
    });
});
</script>
