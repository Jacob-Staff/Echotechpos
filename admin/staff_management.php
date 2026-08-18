<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php'; 

// PROTECTION: Only Admins can manage staff
require_admin();

$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = $_SESSION['pharmacy_id']; 

// --- LOGIC: Toggle Online Visibility ---
if (isset($_GET['toggle_online'])) {
    $target_id = intval($_GET['toggle_online']);
    $current_status = intval($_GET['current_status']);
    $new_status = ($current_status == 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE users SET is_online_visible = ? WHERE id = ? AND pharmacy_id = ?");
    $stmt->bind_param("iii", $new_status, $target_id, $pharmacy_id);
    $stmt->execute();
    header("Location: staff_management.php?updated=1");
    exit();
}

// --- LOGIC: Add Staff ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $username = $conn->real_escape_string($_POST['username']);
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $role = $_POST['role']; 
    $branch_id = intval($_POST['branch_id']);
    $salary = floatval($_POST['salary']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Image Upload Logic
    $profile_pic = "default_avatar.png";
    if (!empty($_FILES['profile_pic']['name'])) {
        $target_dir = "../uploads/staff/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $file_ext = pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION);
        $file_name = time() . '_' . uniqid() . '.' . $file_ext;
        $target_file = $target_dir . $file_name;

        if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
            $profile_pic = $file_name;
        }
    }

    $stmt = $conn->prepare("INSERT INTO users (pharmacy_id, username, full_name, email, password, role, branch_id, salary_amount, profile_pic, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
    $stmt->bind_param("isssssids", $pharmacy_id, $username, $full_name, $email, $password, $role, $branch_id, $salary, $profile_pic);
    
    if($stmt->execute()){
        header("Location: staff_management.php?success=1");
        exit();
    }
}

// --- LOGIC: Permissions Matrix ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permission'])) {
    $role = $_POST['target_role'];
    $module = $_POST['module'];
    $action = $_POST['perm_action']; 
    $new_val = intval($_POST['new_val']);

    $conn->query("INSERT INTO role_permissions (role, module_name, $action) 
                  VALUES ('$role', '$module', $new_val) 
                  ON DUPLICATE KEY UPDATE $action = $new_val");
    header("Location: staff_management.php?perm_updated=1");
    exit();
}

// --- LOGIC: Delete User ---
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    if ($delete_id != ($_SESSION['user_id'] ?? 0)) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND pharmacy_id = ?");
        $stmt->bind_param("ii", $delete_id, $pharmacy_id);
        $stmt->execute();
    }
    header("Location: staff_management.php?deleted=1");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Staff & Permissions | PHARMA-JACOBS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 280px; --accent: #00d2ff; --danger: #ff4d4d; --success: #2ecc71; --dark-card: #161b22; }
        body { background-color: #0f111a; color: #ffffff; font-family: 'Inter', sans-serif; }
        .sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; background: var(--dark-card); border-right: 1px solid #30363d; padding: 1.5rem; }
        .sidebar-brand { font-size: 1.5rem; font-weight: 800; color: var(--accent); display: block; text-decoration: none; margin-bottom: 2rem; }
        .nav-link { color: #8b949e; padding: 12px 15px; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; }
        .nav-link:hover, .nav-link.active { background: #21262d; color: #fff; }
        .nav-link.active { border-left: 4px solid var(--accent); }
        .main-content { margin-left: var(--sidebar-width); padding: 2.5rem; }
        .stat-card { background: var(--dark-card); border: 1px solid #30363d; border-radius: 16px; padding: 1.5rem; margin-bottom: 2rem; }
        .table-dark { --bs-table-bg: #161b22; border-color: #30363d; }
        .badge-allowed { background: var(--success); color: #000; font-weight: bold; }
        .badge-denied { border: 1px solid #444; color: #8b949e; }
        .staff-img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #30363d; }
        
        /* NEW STYLES FOR IMAGE UPLOAD PREVIEW */
        .preview-container { position: relative; width: 100px; height: 100px; margin: 0 auto 20px; cursor: pointer; }
        .preview-img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent); }
        .upload-icon-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent); color: #000; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid #161b22; }
    </style>
</head>
<body>

    <div class="sidebar">
        <a href="admin_dashboard.php" class="sidebar-brand"><i class="fas fa-capsules"></i> PHARMA-JACOBS</a>
        <nav class="nav flex-column">
            <div class="mb-4 px-3">
                <p class="mb-0 small text-white"><?php echo htmlspecialchars($user_display_name); ?></p>
                <span class="badge bg-info text-dark"><?php echo $user_role; ?></span>
            </div>
            <a class="nav-link" href="admin_dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a class="nav-link active" href="staff_management.php"><i class="fas fa-user-shield"></i> Staff & Permissions</a>
            <hr class="border-secondary my-4">
            <a href="../logout.php" class="nav-link text-danger"><i class="fas fa-power-off"></i> Logout</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">Staff Management</h2>
            <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i> Register Staff
            </button>
        </div>

        <div class="stat-card p-0 overflow-hidden">
            <table class="table table-dark table-hover mb-0 align-middle">
                <thead class="table-light text-dark">
                    <tr>
                        <th class="ps-4">Staff Member</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Salary</th>
                        <th class="text-center">Push Online</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $conn->prepare("SELECT u.*, b.branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.pharmacy_id = ?");
                    $stmt->bind_param("i", $pharmacy_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    
                    while($row = $res->fetch_assoc()):
                        $img_path = "../uploads/staff/" . ($row['profile_pic'] ?: 'default_avatar.png');
                        $is_online = $row['is_online_visible'] ?? 0;
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                <img src="<?php echo $img_path; ?>" class="staff-img" alt="User">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($row['full_name'] ?: $row['username']); ?></div>
                                    <small class="text-muted">@<?php echo htmlspecialchars($row['username']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge border border-info text-info"><?php echo $row['role']; ?></span></td>
                        <td><?php echo $row['branch_name'] ?? 'Unassigned'; ?></td>
                        <td>K<?php echo number_format($row['salary_amount'], 2); ?></td>
                        <td class="text-center">
                            <a href="?toggle_online=<?php echo $row['id']; ?>&current_status=<?php echo $is_online; ?>" 
                               class="btn btn-sm <?php echo $is_online ? 'btn-success' : 'btn-outline-secondary'; ?> rounded-pill px-3">
                                <i class="fas <?php echo $is_online ? 'fa-eye' : 'fa-eye-slash'; ?> me-1"></i>
                                <?php echo $is_online ? 'Live' : 'Push Online'; ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <?php if($row['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                <a href="?delete_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger mx-1" onclick="return confirm('Delete this staff member?');">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <h3 class="fw-bold mt-5 mb-3">Role Access Matrix</h3>
        <div class="stat-card p-0 overflow-hidden">
            <table class="table table-dark table-bordered text-center align-middle mb-0">
                <thead class="table-secondary text-dark">
                    <tr>
                        <th class="text-start ps-4">Module Name</th>
                        <th>Pharmacist</th><th>Manager</th><th>Cashier</th><th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $modules = ['Inventory', 'Sales', 'Suppliers', 'Expenses', 'Reports'];
                    $roles = ['Pharmacist', 'Manager', 'Cashier', 'User'];
                    foreach ($modules as $mod):
                    ?>
                    <tr>
                        <td class="text-start ps-4 fw-bold text-info"><?php echo $mod; ?></td>
                        <?php foreach ($roles as $r): 
                            $p_stmt = $conn->prepare("SELECT can_view FROM role_permissions WHERE role=? AND module_name=?");
                            $p_stmt->bind_param("ss", $r, $mod);
                            $p_stmt->execute();
                            $p_res = $p_stmt->get_result();
                            $has_access = ($p_res->num_rows > 0 && $p_res->fetch_assoc()['can_view'] == 1);
                        ?>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="target_role" value="<?php echo $r; ?>">
                                <input type="hidden" name="module" value="<?php echo $mod; ?>">
                                <input type="hidden" name="perm_action" value="can_view">
                                <input type="hidden" name="new_val" value="<?php echo $has_access ? 0 : 1; ?>">
                                <button type="submit" name="update_permission" class="btn btn-sm <?php echo $has_access ? 'badge-allowed' : 'badge-denied'; ?> rounded-pill">
                                    <?php echo $has_access ? 'ALLOWED' : 'DENIED'; ?>
                                </button>
                            </form>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title text-white">Register Staff</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-white">
                        
                        <div class="preview-container" onclick="document.getElementById('profile_pic_input').click();">
                            <img src="../uploads/staff/default_avatar.png" id="image_preview" class="preview-img">
                            <div class="upload-icon-overlay">
                                <i class="fas fa-camera"></i>
                            </div>
                        </div>
                        <input type="file" name="profile_pic" id="profile_pic_input" class="d-none" accept="image/*" onchange="previewImage(this)">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="small text-muted">Username (Login)</label>
                                <input type="text" name="username" class="form-control bg-secondary text-white border-0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="small text-muted">Full Name</label>
                                <input type="text" name="full_name" class="form-control bg-secondary text-white border-0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted">Email Address</label>
                            <input type="email" name="email" class="form-control bg-secondary text-white border-0" required>
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted">Password</label>
                            <input type="password" name="password" class="form-control bg-secondary text-white border-0" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="small text-muted">Role</label>
                                <select name="role" class="form-select bg-secondary text-white border-0">
                                    <option value="Pharmacist">Pharmacist</option>
                                    <option value="Cashier">Cashier</option>
                                    <option value="Manager">Manager</option>
                                    <option value="User">User</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="small text-muted">Monthly Salary</label>
                                <input type="number" step="0.01" name="salary" class="form-control bg-secondary text-white border-0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted">Branch Assignment</label>
                            <select name="branch_id" class="form-select bg-secondary text-white border-0">
                                <?php
                                $b_stmt = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ?");
                                $b_stmt->bind_param("i", $pharmacy_id);
                                $b_stmt->execute();
                                $branches = $b_stmt->get_result();
                                while($b = $branches->fetch_assoc()) echo "<option value='{$b['id']}'>{$b['branch_name']}</option>";
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="submit" class="btn btn-primary w-100 rounded-pill">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JavaScript to handle the image preview and click trigger
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('image_preview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>