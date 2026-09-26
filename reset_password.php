<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/password_reset.php';
echotech_reset_bootstrap($conn);
$type = ($_GET['type'] ?? $_POST['type'] ?? 'staff') === 'client' ? 'client' : 'staff';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$done = false;
$reset = $token !== '' ? echotech_reset_consume($conn, $type, $token) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echotech_reset_check_csrf();
    if (!$reset) {
        $message = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8) $message = 'Your new password must be at least 8 characters.';
        elseif ($password !== $confirm) $message = 'The passwords do not match.';
        elseif (!echotech_reset_set_password($conn, $type, $reset, $password)) $message = 'Unable to update the password. Please request a new reset link.';
        else { $done = true; $message = 'Your password has been changed successfully.'; $reset = null; }
    }
}
$loginUrl = $type === 'client' ? '/api/login_client.php' : '/index.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Password | EchoTech POS"><style>
body{margin:0;background:#f4f6f8;color:#202831;font-family:Inter,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:min(430px,100%);background:#fff;border:1px solid #dfe4e9;border-radius:18px;padding:32px;box-shadow:0 15px 45px rgba(31,40,49,.1)}h1{margin:0 0 8px;font-size:25px}p{color:#6d7782;line-height:1.6;font-size:14px}.label{display:block;color:#39434d;font-size:13px;margin:20px 0 7px}.input{width:100%;box-sizing:border-box;border:1px solid #cfd6dd;border-radius:10px;padding:13px}.btn{width:100%;margin-top:20px;padding:13px;border:0;border-radius:10px;background:#246bfe;color:#fff;font-weight:800;cursor:pointer}.alert{padding:12px;border-radius:9px;background:#fff0f2;color:#b42335;font-size:13px}.success{background:#e8f7f0;color:#087443}.back{display:block;text-align:center;margin-top:18px;color:#6d7782;text-decoration:none;font-size:13px}
</style></head><body><main class="card"><h1><?= $done ? 'Password updated' : 'Create a new password' ?></h1><p><?= $done ? 'Your password has been changed. You can now sign in with your new password.' : 'Choose a strong password of at least 8 characters.' ?></p><?php if($message): ?><div class="alert <?= $done ? 'success' : '' ?>"><?=echotech_reset_h($message)?></div><?php endif; ?><?php if($reset && !$done): ?><form method="post"><input type="hidden" name="csrf_token" value="<?=echotech_reset_h(echotech_reset_csrf())?>"><input type="hidden" name="type" value="<?=echotech_reset_h($type)?>"><input type="hidden" name="token" value="<?=echotech_reset_h($token)?>"><label class="label">New Password</label><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required><label class="label">Confirm New Password</label><input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required><button class="btn" type="submit">CHANGE PASSWORD</button></form><?php endif; ?><a class="back" href="<?=echotech_reset_h($loginUrl)?>">← Back to Login</a></main></body></html>
