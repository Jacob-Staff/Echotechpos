<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/password_reset.php';
echotech_reset_bootstrap($conn);
$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echotech_reset_check_csrf();
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    if ($identifier === '') {
        $message = 'Enter your username or registered email address.';
    } else {
        $account = echotech_reset_find_account($conn, 'staff', $identifier);
        if ($account) {
            $status = strtolower(trim((string)($account['status'] ?? 'active')));
            if ($status !== 'active' || (int)($account['is_frozen'] ?? 0) === 1) {
                $account = null;
            }
        }
        if ($account) {
            $token = echotech_reset_issue($conn, 'staff', $account, echotech_reset_ip());
            if ($token) {
                $url = echotech_reset_base_url() . '/reset_password.php?type=staff&token=' . rawurlencode($token);
                $name = trim((string)($account['full_name'] ?? $account['username'] ?? 'there')) ?: 'there';
                $html = '<div style="font-family:Arial,sans-serif;max-width:600px"><h2>EchoTech POS Password Reset</h2><p>Hello ' . echotech_reset_h($name) . ',</p><p>We received a request to reset your EchoTech POS password.</p><p><a href="' . echotech_reset_h($url) . '" style="display:inline-block;padding:12px 18px;background:#246bfe;color:#fff;text-decoration:none;border-radius:8px">Reset Password</a></p><p>This link expires in 30 minutes and can only be used once.</p><p>If you did not request this, you can safely ignore this email.</p></div>';
                $text = "EchoTech POS Password Reset\n\nHello {$name},\n\nReset your password here:\n{$url}\n\nThis link expires in 30 minutes and can only be used once.\n";
                echotech_reset_send_mail((string)$account['email'], $name, 'EchoTech POS Password Reset', $html, $text);
            }
        }
        $success = true;
        $message = 'If the account exists and has a valid email address, a password reset link has been sent.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot Password | EchoTech POS"><style>
body{margin:0;background:#0f111a;color:#fff;font-family:Inter,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:min(430px,100%);background:#161b22;border:1px solid #30363d;border-radius:18px;padding:32px;box-shadow:0 15px 45px rgba(0,0,0,.35)}h1{margin:0 0 8px;font-size:25px}p{color:#a3b1c2;line-height:1.6;font-size:14px}.label{display:block;color:#c9d1d9;font-size:13px;margin:22px 0 7px}.input{width:100%;box-sizing:border-box;background:#0d1117;border:1px solid #30363d;color:#fff;border-radius:10px;padding:13px}.btn{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:linear-gradient(45deg,#00d2ff,#3a7bd5);font-weight:800;cursor:pointer}.alert{padding:12px;border-radius:9px;background:#123b2c;color:#a7f3d0;font-size:13px}.back{display:block;text-align:center;margin-top:18px;color:#8b949e;text-decoration:none;font-size:13px}
</style></head><body><main class="card"><h1>Forgot your password?</h1><p>Enter your EchoTech username or registered email address. If the account is eligible, we will send a secure reset link.</p><?php if($message): ?><div class="alert"><?=echotech_reset_h($message)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=echotech_reset_h(echotech_reset_csrf())?>"><label class="label">Username or Email</label><input class="input" name="identifier" autocomplete="username email" required><button class="btn" type="submit">SEND RESET LINK</button></form><a class="back" href="/index.php">← Back to Login</a></main></body></html>
