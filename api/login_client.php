<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Dynamic Database Connection Inclusion
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    die("Database connection file (conn.php) not found.");
}

// 2. Folder Context Resolution
$is_in_api_folder = (basename(__DIR__) === 'api');
$path_prefix = $is_in_api_folder ? '' : 'api/';

$msg = "";

// Capture preset branch ID if passed in URL or Session
$preset_bid = isset($_GET['bid']) ? intval($_GET['bid']) : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 0);
$preset_pid = 0;

if ($preset_bid > 0) {
    $stmt_p = $conn->prepare("SELECT pharmacy_id FROM branches WHERE id = ? AND is_active = 1");
    if ($stmt_p) {
        $stmt_p->bind_param("i", $preset_bid);
        $stmt_p->execute();
        $res_p = $stmt_p->get_result();
        if ($row_p = $res_p->fetch_assoc()) {
            $preset_pid = intval($row_p['pharmacy_id']);
        }
        $stmt_p->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $bid   = intval($_POST['branch_id'] ?? 0);

    if (!empty($email) && !empty($pass)) {
        $stmt_user = $conn->prepare("SELECT id, full_name, password FROM clients WHERE email = ?");
        if ($stmt_user) {
            $stmt_user->bind_param("s", $email);
            $stmt_user->execute();
            $result = $stmt_user->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                if (password_verify($pass, $user['password'])) {
                    $_SESSION['client_id']   = $user['id'];
                    $_SESSION['client_name'] = $user['full_name'];
                    
                    if ($bid > 0) {
                        $_SESSION['current_branch_id'] = $bid;
                    }

                    // Redirect to Online Store
                    $target_store = "online_store.php?bid=" . ($bid > 0 ? $bid : $preset_bid);
                    header("Location: " . $target_store);
                    exit();
                } else { 
                    $msg = "Invalid password!"; 
                }
            } else { 
                $msg = "User account not found!"; 
            }
            $stmt_user->close();
        }
    } else {
        $msg = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Client Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root {
            --echo-teal: #003339;
            --echo-green: #00b386;
        }
        body {
            background-color: #f4f6f8;
            font-family: 'IBM Plex Sans', sans-serif;
        }
        .login-card {
            border: none;
            border-top: 4px solid var(--echo-teal);
            border-radius: 12px;
        }
        .btn-theme {
            background-color: var(--echo-teal);
            color: #fff;
            border: none;
        }
        .btn-theme:hover {
            background-color: #00252a;
            color: #fff;
        }
    </style>
</head>
<body class="d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-8 col-md-5 col-lg-4">
            <div class="card login-card shadow-lg p-4 bg-white">
                <div class="text-center mb-3">
                    <i class="mdi mdi-account-circle-outline text-success display-4"></i>
                    <h4 class="fw-bold mb-1" style="color: var(--echo-teal);">Welcome Back</h4>
                    <p class="text-muted small">Access your account & manage orders</p>
                </div>
                
                <?php if($msg): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="mdi mdi-alert-circle me-1"></i> <?php echo htmlspecialchars($msg); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-lg fs-6" placeholder="name@example.com" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Password</label>
                        <input type="password" name="password" class="form-control form-control-lg fs-6" placeholder="••••••••" required>
                    </div>
                    
                    <hr class="my-3">
                    
                    <label class="small fw-bold mb-2 text-uppercase text-muted" style="font-size: 11px;">Select Branch Location</label>
                    
                    <div class="mb-2">
                        <select id="pharmacy_select" class="form-select form-select-sm" required>
                            <option value="">-- Select Pharmacy Group --</option>
                            <?php 
                            $ph = $conn->query("SELECT id, name FROM pharmacies ORDER BY name ASC");
                            if($ph) {
                                while($p = $ph->fetch_assoc()) {
                                    $selected = ($preset_pid == $p['id']) ? 'selected' : '';
                                    echo "<option value='{$p['id']}' {$selected}>" . htmlspecialchars($p['name']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <select name="branch_id" id="branch_select" class="form-select form-select-sm" required disabled>
                            <option value="">-- Select Branch --</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-theme w-100 py-2 fw-bold shadow-sm">
                        <i class="mdi mdi-login me-1"></i> Login & Start Shopping
                    </button>
                </form>

                <div class="text-center mt-3">
                    <small class="text-muted">Don't have an account? 
                        <a href="<?php echo $path_prefix; ?>register_client.php<?php echo $preset_bid > 0 ? '?bid=' . $preset_bid : ''; ?>" class="text-success fw-bold text-decoration-none">Register</a>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    var presetBranchId = <?php echo $preset_bid; ?>;
    var apiPrefix = "<?php echo $path_prefix; ?>";

    function loadBranches(pharmacyId, selectBranchId) {
        if(pharmacyId) {
            $.ajax({
                url: apiPrefix + 'get_branches.php',
                method: 'GET',
                data: { pharmacy_id: pharmacyId },
                success: function(html) {
                    $('#branch_select').html(html).prop('disabled', false);
                    if(selectBranchId > 0) {
                        $('#branch_select').val(selectBranchId);
                    }
                }
            });
        } else {
            $('#branch_select').prop('disabled', true).html('<option value="">-- Select Branch --</option>');
        }
    }

    // Dynamic Branch loading on selection
    $('#pharmacy_select').on('change', function() {
        var pid = $(this).val();
        loadBranches(pid, 0);
    });

    // Auto-trigger load if pharmacy is pre-selected
    var initialPid = $('#pharmacy_select').val();
    if(initialPid) {
        loadBranches(initialPid, presetBranchId);
    }
});
</script>
</body>
</html>
