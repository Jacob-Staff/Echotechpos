<?php 
// Start session if not started
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
} 

require "conn.php"; 

// 🔹 1. Get IDs from Session
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$user_id = intval($_SESSION['user_id'] ?? 0);
$branch_id = intval($_SESSION['branch_id'] ?? 0);

// 🔹 2. Fetch Pharmacy Name (Dynamic)
$pharmacy_name = "SYSTEM POS"; 
if ($pharmacy_id > 0) {
    $p_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
    $p_stmt->bind_param("i", $pharmacy_id);
    $p_stmt->execute();
    $p_res = $p_stmt->get_result();
    if ($row = $p_res->fetch_assoc()) {
        $pharmacy_name = $row['name']; 
    }
}

// 🔹 3. Fetch current branch name (Dynamic)
$branch_name = "Branch Not Set";
if ($branch_id > 0) {
    $q = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
    $q->bind_param("i", $branch_id);
    $q->execute();
    $res = $q->get_result();
    if ($row = $res->fetch_assoc()) {
        $branch_name = $row['branch_name'];
        $_SESSION['branch_name'] = $branch_name;
    }
}

// 🔑 ENFORCED LOCK LOGIC
$is_branch_locked = false;
$locked_branch_id = null;

if ($user_id > 0) {
    $lock_query = $conn->query("SELECT branch_id FROM users WHERE id = $user_id LIMIT 1");
    if ($lock_query && $lock_query->num_rows > 0) {
        $user_row = $lock_query->fetch_assoc();
        $db_lock_value = $user_row['branch_id'];

        if ((int) $db_lock_value > 0) {
            $is_branch_locked = true;
            $locked_branch_id = (int) $db_lock_value;
            
            if ($locked_branch_id != $branch_id) {
                $locked_b_query = $conn->query("SELECT branch_name FROM branches WHERE id = $locked_branch_id LIMIT 1");
                if ($locked_b_query && $locked_b_query->num_rows > 0) {
                    $locked_b_row = $locked_b_query->fetch_assoc();
                    $_SESSION['branch_id'] = $locked_branch_id;
                    $_SESSION['branch_name'] = $locked_b_row['branch_name'];
                    $branch_name = $locked_b_row['branch_name'];
                    $branch_id = $locked_branch_id; 
                }
            }
        }
    }
}

// 🔹 SAFE USER DATA FETCH
$username = 'Guest'; 
$display_full_name = 'Guest';
$user_mobile = "";

if ($user_id > 0) {
    $u_res = $conn->query("SELECT * FROM users WHERE id = $user_id LIMIT 1");
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        $username = $u_row['username'];
        $display_full_name = !empty($u_row['full_name']) ? $u_row['full_name'] : $u_row['username'];
        $user_mobile = $u_row['mobile_number'] ?? '';
        $_SESSION['username'] = $username;
    }
}

// 🔹 Fetch POS settings
$settings = [];
$settings_sql = "SELECT setting_key, setting_value FROM pos_settings";
$result = $conn->query($settings_sql);
if($result) {
    while($row = $result->fetch_assoc()){
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// 🔹 Fetch ONLY branches belonging to THIS pharmacy
$branches = [];
if ($pharmacy_id > 0) {
    $b_stmt = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? ORDER BY branch_name ASC");
    $b_stmt->bind_param("i", $pharmacy_id);
    $b_stmt->execute();
    $b_res = $b_stmt->get_result();
    while ($b_row = $b_res->fetch_assoc()) {
        $branches[] = $b_row;
    }
}
?> 

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* SOLID ICON BUTTON STYLE */
.icon-btn {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: #fff !important;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    transition: all 0.2s ease-in-out;
}

.icon-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0,0,0,0.3);
}

.navbar-nav.mx-2 {
    align-items: center;
}
</style>

<header class="topbar shadow-sm"> 
    <nav class="navbar top-navbar navbar-expand-md navbar-dark"> 
        <div class="navbar-header d-flex align-items-center"> 
            <b class="logo-icon text-uppercase">
                <?= htmlspecialchars($pharmacy_name); ?>
            </b> 
            <a class="nav-toggler waves-effect waves-light d-block d-md-none ml-auto" href="javascript:void(0)"> 
                <i class="ti-menu ti-close"></i> 
            </a> 
        </div> 

        <div class="navbar-collapse collapse" id="navbarSupportedContent" data-navbarbg="skin5"> 
            <ul class="navbar-nav float-left mr-auto w-50 position-relative"> 
                <li class="nav-item w-100">
                    <div class="position-relative w-100">
                        <input type="text" id="live-search-input" name="q" class="form-control border-0 rounded" placeholder="Search products..." autocomplete="off"> 
                        <div id="live-search-results" class="position-absolute w-100 mt-1 shadow-lg bg-white rounded" style="z-index: 1000; max-height: 400px; overflow-y: auto; display: none;"></div>
                    </div>
                </li> 
            </ul> 

            <ul class="navbar-nav mx-2">
                <li class="nav-item">
                    <a href="dashboard.php" class="icon-btn" title="Home">
                        <i class="mdi mdi-home"></i>
                    </a>
                </li>
            </ul>

            <div class="d-none d-md-flex align-items-center text-white font-weight-bold mx-3 px-3 border-start border-end">
                <i class="mdi mdi-hospital-building mr-2 text-info"></i> 
                <span><?= htmlspecialchars($branch_name); ?></span>
            </div>

            <ul class="navbar-nav float-right d-flex align-items-center"> 
                <li class="nav-item mx-2 d-flex align-items-center text-white"> 
                    <i class="mdi mdi-calendar-today font-20 mr-2"></i> 
                    <span class="font-weight-bold d-none d-lg-block"><?php echo date('j M Y'); ?></span> 
                </li> 

                <li class="nav-item mx-2"> 
                    <a href="add_product.php" class="btn btn-success rounded-circle d-flex align-items-center justify-content-center" style="width:40px; height:40px;" title="Add Product"> 
                        <i class="mdi mdi-plus font-weight-bold"></i> 
                    </a> 
                </li>

                <li class="nav-item mx-2"> 
                    <a href="#" class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center" style="width:40px; height:40px;" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"> 
                        <i class="mdi mdi-settings font-20"></i> 
                    </a> 
                </li> 
            </ul> 
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

        <?php if (!$is_branch_locked): ?>
        <div class="mb-4">
            <h6 class="fw-bold mb-2">Switch Branch</h6>
            <select id="branchSelect" class="form-control">
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id']; ?>" <?= ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($b['branch_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <div class="mb-4 border border-info p-3 rounded bg-light">
            <h6 class="fw-bold mb-2 text-info"><i class="mdi mdi-lock-outline me-2"></i> Assigned Branch</h6>
            <p class="mb-0 fw-bold"><?= htmlspecialchars($branch_name); ?></p>
        </div>
        <?php endif; ?>

        <hr>

        <div class="mb-4">
            <h6 class="fw-bold mb-3 text-primary">Configure User Details</h6>
            <form id="user_details_form">
                <div class="mb-2">
                    <label class="form-label small">Mobile Number</label>
                    <input type="text" class="form-control" name="mobile_number" value="<?= htmlspecialchars($user_mobile); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">New Password</label>
                    <input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep current">
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100">Update Profile</button>
            </form>
        </div>

        <hr>

        <form id="settings_form">
            <h6 class="mb-3 fw-bold">POS Configuration</h6>
            <div class="mb-3">
                <label class="form-label">Currency</label>
                <input type="text" class="form-control" name="currency" value="<?= htmlspecialchars($settings['currency'] ?? 'ZMW'); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Tax Rate (%)</label>
                <input type="number" step="0.01" class="form-control" name="tax_rate" value="<?= htmlspecialchars($settings['tax_rate'] ?? 16); ?>">
            </div>
            <button type="submit" class="btn btn-success w-100">Save Settings</button>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    function showCustomAlert(message, type) {
        Swal.fire({
            text: message,
            icon: type, 
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    }

    $('#user_details_form').on('submit', function(e){
        e.preventDefault();
        $.ajax({
            url: '../includes/update_profile.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(resp){ showCustomAlert('Profile updated!', 'success'); },
            error: function(){ showCustomAlert('Update failed.', 'error'); }
        });
    });

    $('#settings_form').on('submit', function(e){
        e.preventDefault();
        $.ajax({
            url: '../includes/save_settings.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(resp){ showCustomAlert('Settings saved!', 'success'); },
            error: function(){ showCustomAlert('Save failed.', 'error'); }
        });
    });

    <?php if (!$is_branch_locked): ?>
    $('#branchSelect').on('change', function(){
        let b_id = $(this).val();
        $.ajax({
            url: '../includes/switch_branch.php',
            type: 'POST',
            data: { branch_id: b_id },
            success: function(resp){
                if (resp.trim() === 'ok') { window.location.reload(); }
            }
        });
    });
    <?php endif; ?>
});
</script>
