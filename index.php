<?php
session_start();

// If user is already logged in, redirect straight to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Login | EchoTech POS</title>
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/a+.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0f111a;
            font-family: 'Poppins', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            max-width: 420px;
            width: 100%;
            padding: 3rem;
            background-color: #161b22;
            border: 1px solid #30363d;
            border-radius: 1.5rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
            color: #fff;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .form-control {
            background-color: #0d1117;
            border: 1px solid #30363d;
            color: #fff;
            padding: 0.8rem;
        }
        .form-control:focus {
            background-color: #0d1117;
            color: #fff;
            border-color: #00d2ff;
            box-shadow: 0 0 0 0.25rem rgba(0, 210, 255, 0.15);
        }
        .btn-primary {
            background: linear-gradient(45deg, #00d2ff, #0076ff);
            border: none;
            padding: 0.8rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .brand-icon {
            color: #00d2ff;
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="text-center">
            <i class="fas fa-capsules brand-icon"></i>
            <h3 class="fw-bold mb-1">EchoTech POS</h3>
            <p class="text-muted small mb-4">Secure POS Access</p>
        </div>

        <?php
        if (isset($_SESSION['status'])) {
            $alert_class = ($_SESSION['status'] == 'success') ? 'alert-success' : 'alert-danger';
            echo '<div class="alert ' . $alert_class . ' py-2 text-center small" role="alert">' . htmlspecialchars($_SESSION['message']) . '</div>';
            unset($_SESSION['status'], $_SESSION['message']);
        }
        ?>

        <form id="loginForm" method="post" action="login_inc.php" novalidate>
            <div class="mb-3">
                <label for="username" class="form-label small text-muted">Username</label>
                <input type="text" class="form-control" id="username" name="username" placeholder="enter name here.." required>
                <div class="invalid-feedback">Please enter your username.</div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label small text-muted">Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                <div class="invalid-feedback">Please enter your password.</div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me">
                    <label class="form-check-label small text-muted" for="rememberMe">Remember Session</label>
                </div>
                <a href="forgot_password.php" class="small text-decoration-none" style="color:#00d2ff;">Forgot?</a>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" name="submit" class="btn btn-primary btn-lg">SIGN IN</button>
            </div>
        </form>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
            <p class="text-center mt-4 small">
                Add new personnel? <a href="register.php" class="text-decoration-none" style="color:#00d2ff;">Register here</a>
            </p>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            'use strict';
            const form = document.getElementById('loginForm');
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        })();
    </script>
</body>
</html>
