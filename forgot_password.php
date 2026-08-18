<?php
session_start();
require('includes/conn.php');

if (isset($_POST['request_reset'])) {
    $email = $conn->real_escape_string($_POST['email']);
    
    // Check if user exists
    $res = $conn->query("SELECT id FROM users WHERE email='$email' LIMIT 1");
    
    if ($res->num_rows > 0) {
        // Generate a random secure token
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));
        
        // Save token to DB
        $conn->query("UPDATE users SET reset_token='$token', reset_expires='$expires' WHERE email='$email'");
        
        // Create Reset Link
        $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/pharmacy_v1/reset_password.php?token=" . $token;
        
        /* LOGIC: In a live environment, use mail() or PHPMailer here.
        For now, we will simulate the "Email Sent" success message.
        */
        $_SESSION['status'] = 'success';
        $_SESSION['message'] = "Recovery link sent! (Simulated: <a href='$resetLink'>Click here to reset</a>)";
    } else {
        $_SESSION['status'] = 'error';
        $_SESSION['message'] = "No account found with that email.";
    }
    header("Location: forgot_password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recover Account | PHARMA-JACOBS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0f111a; color: #fff; display: flex; align-items: center; min-height: 100vh; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 1rem; width: 100%; max-width: 400px; margin: auto; padding: 2rem; }
        .form-control { background: #0d1117; border: 1px solid #30363d; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <h4 class="fw-bold mb-3">Forgot Password?</h4>
        <p class="text-muted small">Enter your registered email to receive a password reset link.</p>
        
        <?php if(isset($_SESSION['status'])): ?>
            <div class="alert alert-<?php echo $_SESSION['status'] == 'success' ? 'info' : 'danger'; ?> small">
                <?php echo $_SESSION['message']; unset($_SESSION['status'], $_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <input type="email" name="email" class="form-control" placeholder="Email Address" required>
            </div>
            <button type="submit" name="request_reset" class="btn btn-primary w-100">Send Recovery Link</button>
            <div class="text-center mt-3">
                <a href="index.php" class="text-muted small text-decoration-none">Back to Login</a>
            </div>
        </form>
    </div>
</body>
</html>