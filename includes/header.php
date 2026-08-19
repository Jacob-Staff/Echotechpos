<?php 
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
} 

require_once "conn.php"; 

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$user_id = intval($_SESSION['user_id'] ?? 0);
$branch_id = intval($_SESSION['branch_id'] ?? 0);

// Dynamic Pharmacy Name
$pharmacy_name = "PHARMANOVA"; 
if ($pharmacy_id > 0) {
    $p_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
    if ($p_stmt) {
        $p_stmt->bind_param("i", $pharmacy_id);
        $p_stmt->execute();
        $p_res = $p_stmt->get_result();
        if ($row = $p_res->fetch_assoc()) {
            $pharmacy_name = $row['name']; 
        }
    }
}

// Dynamic Branch Name
$branch_name = "Nova Lsk";
if ($branch_id > 0) {
    $q = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
    if ($q) {
        $q->bind_param("i", $branch_id);
        $q->execute();
        $res = $q->get_result();
        if ($row = $res->fetch_assoc()) {
            $branch_name = $row['branch_name'];
            $_SESSION['branch_name'] = $branch_name;
        }
    }
}

// User details
$display_full_name = 'Guest';
$user_mobile = "";
if ($user_id > 0) {
    $u_res = $conn->query("SELECT * FROM users WHERE id = $user_id LIMIT 1");
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        $display_full_name = !empty($u_row['full_name']) ? $u_row['full_name'] : $u_row['username'];
        $user_mobile = $u_row['mobile_number'] ?? '';
    }
}

// Fetch branches for selection
$branches = [];
if ($pharmacy_id > 0) {
    $b_stmt = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? ORDER BY branch_name ASC");
    if ($b_stmt) {
        $b_stmt->bind_param("i", $pharmacy_id);
        $b_stmt->execute();
        $b_res = $b_stmt->get_result();
        while ($b_row = $b_res->fetch_assoc()) {
            $branches[] = $b_row;
        }
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
.icon-btn {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #007bff;
    color: #fff !important;
    text-decoration: none;
}
.icon-btn:hover {
    background: #0056b3;
}
</style>

<header class="topbar shadow-sm"> 
    <nav class="navbar top-navbar navbar-expand-md navbar-dark p-0"> 
        <div class="navbar-header"> 
            <b class="logo-icon text-uppercase">
                <?= htmlspecialchars($pharmacy_name); ?>
            </b> 
        </div> 

        <div class="navbar-collapse collapse d-flex justify-content-between px-3" id="navbarSupportedContent"> 
            <div class="d-flex align-items-center w-50"> 
                <input type="text" id="live-search-input" name="q" class="form-control border-0 rounded bg-white" placeholder="Search products..." autocomplete="off"> 
            </div> 

            <div class="d-flex align-items-center gap-3">
                <a href="dashboard.php" class="icon-btn" title="Home">
                    <i class="mdi mdi-home"></i>
                </a>

                <div class="text-white fw-bold d-flex align-items-center border-start border-end px-3">
                    <i class="mdi mdi-hospital-building me-1 text-info"></i> 
                    <span><?= htmlspecialchars($branch_name); ?></span>
                </div>

                <div class="text-white d-flex align-items-center fw-bold"> 
                    <i class="mdi mdi-calendar-today me-2"></i> 
                    <span><?php echo date('j M Y'); ?></span> 
                </div> 

                <a href="add_product.php" class="btn btn-success btn-sm rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px;" title="Add Product"> 
                    <i class="mdi mdi-plus fs-5"></i> 
                </a> 

                <a href="#" class="btn btn-outline-light btn-sm rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px;" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"> 
                    <i class="mdi mdi-cog fs-5"></i> 
                </a> 
            </div> 
        </div> 
    </nav> 
</header>

<div class="offcanvas offcanvas-end" tabindex="-1" id="settingsOffcanvas">
    <div class="offcanvas-header bg-dark text-white">
        <h5 class="offcanvas-title">System Settings</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="mb-3 text-center border-bottom pb-3">
            <h5>Welcome, <strong><?= htmlspecialchars($display_full_name); ?></strong></h5>
            <small class="text-muted"><?= htmlspecialchars($pharmacy_name); ?></small>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold mb-2">Switch Branch</h6>
            <select id="branchSelect" class="form-select">
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id']; ?>" <?= ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($b['branch_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
