<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/password_reset.php';

$type  = strtolower(trim((string)($_GET['type'] ?? $_POST['type'] ?? '')));
$token = strtolower(trim((string)($_GET['token'] ?? $_POST['token'] ?? '')));

$message = '';
$error = false;
$validReset = false;
$reset = null;

if (!in_array($type, ['staff', 'client'], true)) {
    $message = 'This password reset link is invalid.';
    $error = true;
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $message = 'This password reset link is invalid or incomplete.';
    $error = true;
} else {
    $serviceReady = echotech_reset_bootstrap($conn);
    if (!$serviceReady) {
        $message = 'The password reset service is temporarily unavailable. Please try again later.';
        $error = true;
    } else {
        $reset = echotech_reset_consume($conn, $type, $token);
        if (!$reset) {
            $message = 'This password reset link is invalid, expired, or has already been used.';
            $error = true;
        } else {
            $validReset = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validReset) {
    $csrf = (string)($_SESSION['echotech_reset_csrf'] ?? '');
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');

    if ($csrf === '' || $postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $message = 'Security check failed. Please reopen the reset link from your email.';
        $error = true;
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (strlen($password) < 8) {
            $message = 'Your new password must be at least 8 characters long.';
            $error = true;
        } elseif ($password !== $confirm) {
            $message = 'The passwords do not match.';
            $error = true;
        } else {
            if (echotech_reset_set_password($conn, $type, $reset, $password)) {
                $message = 'Your password has been reset successfully. You can now sign in with your new password.';
                $validReset = false;
                $error = false;
            } else {
                $message = 'We could not reset your password. Please request a new reset link and try again.';
                $error = true;
            }
        }
    }
}

if (empty($_SESSION['echotech_reset_csrf'])) {
    $_SESSION['echotech_reset_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['echotech_reset_csrf'];

$loginUrl = $type === 'client' ? '/api/login_client.php' : '/index.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password | EchoTech</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,Arial,sans-serif}
body{min-height:100vh;background:#0f111a;color:#fff;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:min(440px,100%);background:#161b22;border:1px solid #30363d;border-radius:18px;padding:30px;box-shadow:0 15px 45px rgba(0,0,0,.35)}
.brand{font-size:25px;font-weight:800;margin-bottom:6px}
.subtitle{font-size:14px;color:#9aa7b5;line-height:1.55;margin:0 0 22px}
.notice{padding:12px 14px;border-radius:10px;background:#123b2c;color:#a7f3d0;font-size:13px;line-height:1.5;margin-bottom:18px}
.notice.error{background:#3b171b;color:#fecaca}
.label{display:block;color:#c9d1d9;font-size:13px;margin:16px 0 7px}
.input{width:100%;background:#0d1117;border:1px solid #30363d;color:#fff;border-radius:10px;padding:13px;outline:none;font-size:15px}
.input:focus{border-color:#3a7bd5;box-shadow:0 0 0 3px rgba(58,123,213,.12)}
.btn{width:100%;margin-top:20px;padding:13px;border:0;border-radius:10px;background:linear-gradient(45deg,#00d2ff,#3a7bd5);font-weight:800;cursor:pointer;color:#fff;font-size:14px}
.btn:hover{opacity:.94}
.back{display:block;text-align:center;margin-top:18px;color:#8b949e;text-decoration:none;font-size:13px}
.back:hover{color:#fff}
.small{margin-top:14px;color:#7f8b99;font-size:12px;text-align:center;line-height:1.5}
</style>
</head>
<body>
<main class="card">
    <div class="brand">EchoTech</div>
    <p class="subtitle">Create a new password for your account.</p>

    <?php if ($message !== ''): ?>
        <div class="notice<?= $error ? ' error' : '' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($validReset): ?>
        <form method="post" novalidate>
            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

            <label class="label" for="password">New Password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>

            <label class="label" for="confirm_password">Confirm New Password</label>
            <input class="input" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>

            <button class="btn" type="submit">RESET PASSWORD</button>
        </form>
        <p class="small">Your reset link is valid for 30 minutes and can only be used once.</p>
    <?php else: ?>
        <a class="back" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">â† Back to Login</a>
    <?php endif; ?>
</main>
</body>
</html>
