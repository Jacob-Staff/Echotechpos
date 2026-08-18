<?php
session_start();
require('includes/conn.php');

$token = $_GET['token'] ?? '';
$isValid = false;

// Validate Token
if (!empty($token)) {
    $res = $conn->query("SELECT id FROM users WHERE reset_token='$token' AND reset_expires > NOW() LIMIT 1");
    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        $isValid = true;
    }
}

// Handle Password Update
if (isset($_POST['update_password']) && $isValid) {
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $uid = $user['id'];
    
    // Update password and CLEAR the token so it can't be used again
    $conn->query("UPDATE users SET password='$new_pass', reset_token=NULL, reset_expires=NULL WHERE id=$uid");
    
    $_SESSION['status'] = 'success';
    $_SESSION['message'] = "Password updated! You can now login.";
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | PHARMA-JACOBS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0f111a; color: #fff; display: flex; align-items: center; min-height: 100vh; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 1rem; width: 100%; max-width: 400px; margin: auto; padding: 2rem; }
        .form-control { background: #0d1117; border: 1px solid #30363d; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($isValid): ?>
            <h4 class="fw-bold mb-3">Create New Password</h4>
            <form method="POST">
                <div class="mb-3">
                    <label class="small text-muted">New Password</label>
                    <input type="password" name="password" class="form-control" minlength="6" required>
                </div>
                <button type="submit" name="update_password" class="btn btn-success w-100">Update Password</button>
            </form>
        <?php else: ?>
            <div class="text-center">
                <h4 class="text-danger">Link Expired</h4>
                <p class="text-muted small">This reset link is invalid or has expired. Please request a new one.</p>
                <a href="forgot_password.php" class="btn btn-outline-light btn-sm">Try Again</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>