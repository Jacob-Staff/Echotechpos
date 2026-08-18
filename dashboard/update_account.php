<?php
session_start();
require "../includes/conn.php";
require "../includes/auth.php";

// 1. Multi-tenancy Safety Check
$user_id = $_SESSION['user_id'] ?? 0;
$p_id = $_SESSION['pharmacy_id'] ?? 0;
$b_id = $_SESSION['branch_id'] ?? 0;

if (!$user_id || !$p_id) {
    header("Location: login.php");
    exit();
}

// 2. HANDLE THE UPDATE ACTION (The "Engine")
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone']);
    $password  = $_POST['password'];
    $confirm   = $_POST['confirm_password'];

    // Validation
    if ($password && $password !== $confirm) {
        $error = "Passwords do not match!";
    } else {
        if ($password) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            // Secure Update: Must match ID AND Pharmacy ID (Multi-tenancy)
            $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, phone=?, password=? WHERE id=? AND pharmacy_id=?");
            $stmt->bind_param("sssssii", $full_name, $username, $email, $phone, $hashed, $user_id, $p_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, phone=? WHERE id=? AND pharmacy_id=?");
            $stmt->bind_param("ssssii", $full_name, $username, $email, $phone, $user_id, $p_id);
        }

        if ($stmt->execute()) {
            // Update Session so the Header/Sidebar changes immediately
            $_SESSION['full_name'] = $full_name;
            $_SESSION['username']  = $username;
            $success = "Profile updated successfully!";
        } else {
            $error = "Database error: Could not update profile.";
        }
        $stmt->close();
    }
}

// 3. FETCH CURRENT DATA
$query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id AND pharmacy_id = $p_id");
$user = mysqli_fetch_assoc($query);

require "../includes/head.php";
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <title>My Account | Pharmanova</title>
    <link href="dist/css/style.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        .profile-card { border-radius: 15px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .avatar-preview { width: 100px; height: 100px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: #64748b; margin: 0 auto 20px; }
        .form-control-lg { border-radius: 10px; font-size: 1rem; border: 1px solid #e2e8f0; }
        .btn-update { border-radius: 10px; padding: 12px; font-weight: 700; transition: 0.3s; }
        .btn-update:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    </style>
</head>

<body>
    <div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5" data-sidebartype="full"
        data-sidebar-position="absolute" data-header-position="absolute" data-boxed-layout="full">
        
        <?php require "../includes/header.php"; ?>
        <?php require "../includes/aside.php"; ?>

        <div class="page-wrapper">
            <div class="page-breadcrumb">
                <div class="row align-items-center">
                    <div class="col-12">
                        <h4 class="page-title">Manage Account</h4>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                                <li class="breadcrumb-item active">Profile Settings</li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-lg-8 col-xlg-9">
                        
                        <?php if(isset($success)): ?>
                            <div class="alert alert-success border-0 shadow-sm mb-4">
                                <i class="mdi mdi-check-circle me-2"></i> <?php echo $success; ?>
                            </div>
                        <?php endif; ?>
                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger border-0 shadow-sm mb-4">
                                <i class="mdi mdi-alert-circle me-2"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <div class="card profile-card">
                            <div class="card-body p-4 p-md-5">
                                <div class="text-center mb-5">
                                    <div class="avatar-preview">
                                        <i class="mdi mdi-account"></i>
                                    </div>
                                    <h4 class="fw-bold text-dark mb-1"><?php echo $user['full_name']; ?></h4>
                                    <p class="text-muted small">Branch ID: <?php echo $b_id; ?> | Role: System Administrator</p>
                                </div>

                                <form action="" method="POST" class="form-horizontal">
                                    <div class="row">
                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">Full Name</label>
                                            <input type="text" name="full_name" class="form-control form-control-lg" 
                                                value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">Username</label>
                                            <input type="text" name="username" class="form-control form-control-lg" 
                                                value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">Email Address</label>
                                            <input type="email" name="email" class="form-control form-control-lg" 
                                                value="<?php echo htmlspecialchars($user['email']); ?>">
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">Phone Number</label>
                                            <input type="text" name="phone" class="form-control form-control-lg" 
                                                value="<?php echo htmlspecialchars($user['phone']); ?>">
                                        </div>

                                        <hr class="my-4 opacity-5">
                                        <h6 class="fw-bold mb-4 text-primary"><i class="mdi mdi-lock-reset me-2"></i>Change Password (Leave blank to keep current)</h6>

                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">New Password</label>
                                            <input type="password" name="password" class="form-control form-control-lg" placeholder="••••••••">
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <label class="small fw-bold text-uppercase text-muted mb-2">Confirm New Password</label>
                                            <input type="password" name="confirm_password" class="form-control form-control-lg" placeholder="••••••••">
                                        </div>

                                        <div class="col-12 mt-3">
                                            <button type="submit" name="update_profile" class="btn btn-primary btn-update w-100 shadow-sm text-white">
                                                Update Account Information
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="footer text-center text-muted">
                &copy; <?php echo date('Y'); ?> Pharmanova LSK | Multi-tenant Secure Access.
            </footer>
        </div>
    </div>

    <script src="assets/libs/jquery/dist/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/app-style-switcher.js"></script>
    <script src="dist/js/waves.js"></script>
    <script src="dist/js/sidebarmenu.js"></script>
    <script src="dist/js/custom.js"></script>
</body>
</html>