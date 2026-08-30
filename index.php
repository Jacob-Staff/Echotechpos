<?php
session_start();
// Database connection is in the includes folder
require_once 'includes/conn.php';

// --- PHP LOGIC: Handle the Login POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($username) || empty($password)) {
        header("Location: index.php?error=empty_fields");
        exit();
    }

    // 1. Fetch user data
    $stmt = $conn->prepare("SELECT id, pharmacy_id, username, password, role, branch_id, status FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        
        // 2. CHECK ACCOUNT STATUS: If Frozen, stop them here
        if ($user['status'] === 'Frozen') {
            header("Location: index.php?error=account_frozen");
            exit();
        }

        // 3. PASSWORD VERIFICATION
        if (password_verify($password, $user['password'])) {
            
            // 4. SECURITY: Regenerate ID to prevent session fixation
            session_regenerate_id(true);

            // 5. SET SESSION VARIABLES
            $_SESSION['user_id']         = $user['id'];
            $_SESSION['pharmacy_id']     = $user['pharmacy_id'];
            $_SESSION['sessionUsername'] = $user['username'];
            $_SESSION['role']            = $user['role'];      
            $_SESSION['branch_id']       = $user['branch_id']; 

            // 6. ROLE-BASED ROUTING
            // IMPORTANT: Human Resource must land in the HR portal,
            // not the normal POS dashboard.
            $role = trim((string)($user['role'] ?? ''));

            if (strcasecmp($role, 'Human Resource') === 0) {
                header("Location: admin/employee_management.php");
                exit();
            }

            if (strcasecmp($role, 'Admin') === 0) {
                header("Location: admin/admin_dashboard.php");
                exit();
            }

            // All other POS staff retain the normal dashboard.
            header("Location: dashboard/dashboard.php");
            exit();

        } else {
            // Wrong Password
            header("Location: index.php?error=wrong_password");
            exit();
        }
    } else {
        // User not found
        header("Location: index.php?error=user_not_found");
        exit();
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | EchoTech POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { 
            --accent: #00d2ff; 
            --bg-dark: #0f111a; 
            --card-bg: #161b22; 
        }
        body { 
            background-color: var(--bg-dark); 
            color: #fff; 
            font-family: 'Inter', sans-serif; 
            height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0; 
        }
        .login-card { 
            background: var(--card-bg); 
            border: 1px solid #30363d; 
            border-radius: 20px; 
            padding: 2.5rem; 
            width: 100%; 
            max-width: 400px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
        }
        .brand-icon { 
            font-size: 3rem; 
            color: var(--accent); 
            margin-bottom: 1rem; 
        }
        .form-control { 
            background: #0d1117; 
            border: 1px solid #30363d; 
            color: #ffffff !important; 
            padding: 12px 15px; 
            border-radius: 10px; 
        }
        .form-label {
            color: #c9d1d9 !important;
            font-weight: 500;
        }
        .form-control::placeholder {
            color: #8b949e !important;
            opacity: 1 !important;
        }
        .form-control:focus { 
            background: #0d1117; 
            border-color: var(--accent); 
            color: #fff; 
            box-shadow: none; 
        }
        .btn-login { 
            background: linear-gradient(45deg, #00d2ff, #3a7bd5); 
            border: none; 
            font-weight: bold; 
            padding: 12px; 
            border-radius: 10px; 
            transition: 0.3s; 
        }
        .btn-login:hover { 
            opacity: 0.9; 
            transform: translateY(-2px); 
            color: #fff; 
        }
        .alert { 
            border-radius: 10px; 
            border: none; 
            font-size: 0.9rem; 
            margin-bottom: 1.5rem; 
        }
        .subtitle-text {
            color: #a3b1c2 !important;
        }
    </style>
</head>
<body>

<div class="login-card text-center">
    <div class="brand-icon"><i class="fas fa-capsules"></i></div>
    <h3 class="fw-bold mb-1">EchoTech POS</h3>
    <p class="subtitle-text mb-4">Secure Access</p>

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger text-start">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php
                $err = $_GET['error'];
                if($err == "empty_fields") echo "Please fill in all fields.";
                elseif($err == "account_frozen") echo "Account is suspended. Contact owner.";
                elseif($err == "wrong_password") echo "Incorrect password.";
                elseif($err == "user_not_found") echo "Username not recognized.";
                else echo "Access Denied.";
            ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['status']) && $_GET['status'] == 'logged_out'): ?>
        <div class="alert alert-success text-start">
            <i class="fas fa-check-circle me-2"></i> Successfully logged out.
        </div>
    <?php endif; ?>

    <form action="index.php" method="POST">
        <div class="mb-3 text-start">
            <label class="form-label small">Username</label>
            <input type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus>
        </div>
        <div class="mb-4 text-start">
            <label class="form-label small">Password</label>
            <input type="password" name="password" class="form-control" placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢" required>
        </div>
        <button type="submit" class="btn btn-login w-100 text-white">
            Login to Dashboard <i class="fas fa-arrow-right ms-2"></i>
        </button>
    </form>

    <div class="mt-4 pt-3 border-top border-secondary">
        <p class="small text-muted mb-0">System Location: Zambia</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
