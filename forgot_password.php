<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/password_reset.php';

$message = '';
$success = false;
$serviceReady = echotech_reset_bootstrap($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $serviceReady) {
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
                $url = echotech_reset_base_url()
                    . '/reset_password.php?type=staff&token='
                    . rawurlencode($token);

                $name = trim((string)($account['full_name'] ?? $account['username'] ?? 'there')) ?: 'there';

                /*
                 * Pharmacy branding is resolved separately. It cannot prevent
                 * the reset token or Brevo email from being sent.
                 */
                $pharmacyName = echotech_reset_get_pharmacy_name(
                    $conn,
                    (int)($account['pharmacy_id'] ?? 0)
                );

                $safeName = echotech_reset_h($name);
                $safePharmacy = echotech_reset_h($pharmacyName);
                $safeUrl = echotech_reset_h($url);

                $subject = $pharmacyName . ' password reset';

                $html = '<div style="font-family:Arial,sans-serif;max-width:600px;color:#202124">'
                    . '<div style="font-size:22px;font-weight:700;margin:0 0 8px">EchoTech</div>'
                    . '<div style="font-size:18px;font-weight:600;margin:0 0 26px">'
                    . $safePharmacy . ' password reset'
                    . '</div>'
                    . '<p>Hello ' . $safeName . ',</p>'
                    . '<p>We received a request to reset your password.</p>'
                    . '<p>Use the button below to create a new password for your account.</p>'
                    . '<p style="margin:28px 0">'
                    . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;background:#246bfe;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">'
                    . 'Reset Password'
                    . '</a></p>'
                    . '<p style="font-size:14px;color:#5f6368">This link expires in 30 minutes and can only be used once.</p>'
                    . '<p style="font-size:14px;color:#5f6368">If you did not request this, you can safely ignore this email.</p>'
                    . '</div>';

                $text = "EchoTech\n"
                    . "{$pharmacyName} password reset\n\n"
                    . "Hello {$name},\n\n"
                    . "We received a request to reset your password.\n\n"
                    . "Reset your password here:\n{$url}\n\n"
                    . "This link expires in 30 minutes and can only be used once.\n"
                    . "If you did not request this, you can safely ignore this email.";

                $mailSent = echotech_reset_send_mail(
                    (string)$account['email'],
                    $name,
                    $subject,
                    $html,
                    $text,
                    'EchoTech'
                );

                if (!$mailSent) {
                    error_log(
                        'Password reset: token created but email delivery failed for staff account ID '
                        . (int)$account['id']
                    );
                }
            }
        }

        $success = true;
        $message = 'If the account exists and has a valid email address, a password reset link has been sent.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = 'The password reset service is temporarily unavailable. Please try again later.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0f111a;color:#fff;font-family:Inter,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:min(430px,100%);background:#161b22;border:1px solid #30363d;border-radius:18px;padding:32px;box-shadow:0 15px 45px rgba(0,0,0,.35)}
h1{margin:0 0 8px;font-size:25px}
p{color:#a3b1c2;line-height:1.6;font-size:14px}
.label{display:block;color:#c9d1d9;font-size:13px;margin:22px 0 7px}
.input{width:100%;background:#0d1117;border:1px solid #30363d;color:#fff;border-radius:10px;padding:13px;outline:none}
.input:focus{border-color:#3a7bd5;box-shadow:0 0 0 3px rgba(58,123,213,.12)}
.btn{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:linear-gradient(45deg,#00d2ff,#3a7bd5);font-weight:800;cursor:pointer;color:#fff}
.alert{padding:12px;border-radius:9px;background:#123b2c;color:#a7f3d0;font-size:13px;margin:15px 0}
.alert.error{background:#3b171b;color:#fecaca}
.back{display:block;text-align:center;margin-top:18px;color:#8b949e;text-decoration:none;font-size:13px}
</style>
</head>
<body>
<main class="card">
    <h1>Forgot your password?</h1>
    <p>Enter your username or registered email address. If the account is eligible, we will send a secure reset link.</p>

    <?php if ($message !== ''): ?>
        <div class="alert<?= $serviceReady && $success ? '' : ' error' ?>">
            <?= echotech_reset_h($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!$serviceReady): ?>
        <div class="alert error">The password reset service is temporarily unavailable. Please try again later.</div>
    <?php else: ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= echotech_reset_h(echotech_reset_csrf()) ?>">
            <label class="label" for="identifier">Username or Email</label>
            <input class="input" id="identifier" name="identifier" autocomplete="username email" required>
            <button class="btn" type="submit">SEND RESET LINK</button>
        </form>
    <?php endif; ?>

    <a class="back" href="/index.php">â† Back to Login</a>
</main>
</body>
</html>
