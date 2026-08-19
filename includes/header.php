<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure connection exists
if (!isset($conn) && file_exists(__DIR__ . "/conn.php")) {
    require_once __DIR__ . "/conn.php";
}

// Fallback user values
$user_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$branch_name = $_SESSION['branch_name'] ?? 'Nova Lsk';
$today_date = date('d M Y');
?>

<header class="topbar">
    <nav class="navbar top-navbar navbar-expand-md navbar-dark">
        <div class="navbar-header">
            <a class="navbar-brand" href="../dashboard/dashboard.php">
                <span class="logo-icon">PHARMANOVA</span>
            </a>
        </div>
        <div class="navbar-collapse collapse d-flex justify-content-between align-items-center px-3">
            <div class="search-box me-auto" style="max-width: 400px; width: 100%;">
                <input type="text" class="form-control" placeholder="Search products...">
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="../dashboard/dashboard.php" class="btn btn-primary btn-sm"><i class="fas fa-home"></i></a>
                <span class="badge bg-info text-white p-2"><i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                <span class="text-white small"><i class="far fa-calendar-alt me-1"></i> <?php echo $today_date; ?></span>
                <a href="sell_now.php" class="btn btn-success btn-sm"><i class="fas fa-plus"></i></a>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-cog"></i></a>
            </div>
        </div>
    </nav>
</header>
