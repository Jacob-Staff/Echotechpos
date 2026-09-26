<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Lusaka');

/*
 * EchoTech POS - Staff Forgot Password
 *
 * This page is intentionally self-contained at the entry point so a failure
 * in the optional reset-mail layer cannot produce a blank page before HTML.
 */

require_once __DIR__ . '/includes/conn.php';

function echotech_forgot_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function echotech_forgot_log(string $message): void
{
    error_log('EchoTech forgot password: ' . $message);
}

function echotech_forgot_csrf(): string
{
    if (empty($_SESSION['echotech_forgot_csrf'])) {
        $_SESSION['echotech_forgot_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['echotech_forgot_csrf'];
}

function echotech_forgot_check_csrf(): bool
{
    $session = (string)($_SESSION['echotech_forgot_csrf'] ?? '');
    $posted = (string)($_POST['csrf_token'] ?? '');
    return $session !== '' && $posted !== '' && hash_equals($session, $posted);
}

function echotech_forgot_bootstrap(mysqli $db): bool
{
    try {
        $db->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_type VARCHAR(20) NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            requested_ip VARCHAR(45) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_token_hash (token_hash),
            KEY idx_password_reset_account (account_type, account_id),
            KEY idx_password_reset_email (account_type, email),
            KEY idx_password_reset_expiry (expires_at),
            KEY idx_password_reset_ip (requested_ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (Throwable $e) {
        echotech_forgot_log('Token table bootstrap failed: ' . $e->getMessage());
        return false;
    }
}

function echotech_forgot_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function echotech_forgot_base_url(): string
{
    $forwarded = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = $forwarded === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'echotechpos.onrender.com'));
    return ($https ? 'https://' : 'http://') . ($host ?: 'echotechpos.onrender.com');
}

function echotech_forgot_mail(string $to, string $name, string $url): bool
{
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $fromEmail = trim((string)(getenv('MAIL_FROM_EMAIL') ?: ''));
    $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: 'EchoTech POS')) ?: 'EchoTech POS';

    if ($apiKey === '' || $fromEmail === '') {
        echotech_forgot_log('Brevo configuration missing: BREVO_API_KEY or MAIL_FROM_EMAIL.');
        return false;
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echotech_forgot_log('Invalid sender or recipient email.');
        return false;
    }

    $safeName = trim(preg_replace('/[\r\n]+/', ' ', $name));
    $subject = 'EchoTech POS Password Reset';
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px">'
        . '<h2>EchoTech POS Password Reset</h2>'
        . '<p>Hello ' . echotech_forgot_h($safeName !== '' ? $safeName : 'there') . ',</p>'
        . '<p>We received a request to reset your EchoTech POS password.</p>'
        . '<p><a href="' . echotech_forgot_h($url) . '" style="display:inline-block;padding:12px 18px;background:#246bfe;color:#fff;text-decoration:none;border-radius:8px">Reset Password</a></p>'
        . '<p>This link expires in 30 minutes and can only be used once.</p>'
        . '<p>If you did not request this, you can safely ignore this email.</p>'
        . '</div>';
    $text = "EchoTech POS Password Reset\n\n"
        . 'Hello ' . ($safeName !== '' ? $safeName : 'there') . ",\n\n"
        . "Reset your password here:\n" . $url . "\n\n"
        . "This link expires in 30 minutes and can only be used once.\n";

    $payload = json_encode([
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $to, 'name' => $safeName !== '' ? $safeName : $to]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        echotech_forgot_log('Could not encode Brevo payload.');
        return false;
    }

    $headers = [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json',
    ];

    $timeout = max(5, min(30, (int)(getenv('MAIL_TIMEOUT') ?: 20)));

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            if ($ch === false) {
                echotech_forgot_log('Could not initialize cURL.');
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                echotech_forgot_log('Brevo HTTPS failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
                return false;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $payload,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
            $statusLine = (string)($http_response_header[0] ?? '');
            $httpCode = 0;
            if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
                $httpCode = (int)$m[1];
            }
            if ($response === false) {
                echotech_forgot_log('Brevo HTTPS fallback failed.');
                return false;
            }
        }

        $decoded = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $messageId = is_array($decoded) ? (string)($decoded['messageId'] ?? '') : '';
            echotech_forgot_log('Brevo accepted password reset. HTTP ' . $httpCode . ($messageId !== '' ? ' messageId=' . $messageId : ''));
            return true;
        }

        $reason = is_array($decoded) ? trim((string)($decoded['message'] ?? $decoded['code'] ?? '')) : '';
        echotech_forgot_log('Brevo rejected password reset. HTTP ' . $httpCode . ($reason !== '' ? ': ' . $reason : ''));
        return false;
    } catch (Throwable $e) {
        echotech_forgot_log('Brevo exception: ' . $e->getMessage());
        return false;
    }
}

$message = '';
$success = false;
$serviceReady = echotech_forgot_bootstrap($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!echotech_forgot_check_csrf()) {
        http_response_code(419);
        $message = 'Security check failed. Please refresh the page and try again.';
    } elseif (!$serviceReady) {
        $message = 'The password reset service is temporarily unavailable. Please try again later.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));

        if ($identifier === '') {
            $message = 'Enter your username or registered email address.';
        } else {
            try {
                $stmt = $conn->prepare(
                    "SELECT id, full_name, username, email, status, is_frozen
                     FROM users
                     WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                        OR LOWER(TRIM(username)) = LOWER(TRIM(?))
                     LIMIT 1"
                );
                $stmt->bind_param('ss', $identifier, $identifier);
                $stmt->execute();
                $account = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                if ($account) {
                    $status = strtolower(trim((string)($account['status'] ?? 'active')));
                    if ($status === 'active' && (int)($account['is_frozen'] ?? 0) === 0) {
                        $email = strtolower(trim((string)($account['email'] ?? '')));

                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            /* Rate limits: one per account/minute, max five per IP/hour. */
                            $id = (int)$account['id'];
                            $ip = echotech_forgot_ip();

                            $rateStmt = $conn->prepare(
                                "SELECT COUNT(*) total
                                 FROM password_reset_tokens
                                 WHERE account_type='staff' AND account_id=?
                                   AND created_at >= (NOW() - INTERVAL 1 MINUTE)"
                            );
                            $rateStmt->bind_param('i', $id);
                            $rateStmt->execute();
                            $recent = (int)($rateStmt->get_result()->fetch_assoc()['total'] ?? 0);
                            $rateStmt->close();

                            $ipStmt = $conn->prepare(
                                "SELECT COUNT(*) total
                                 FROM password_reset_tokens
                                 WHERE requested_ip=?
                                   AND created_at >= (NOW() - INTERVAL 1 HOUR)"
                            );
                            $ipStmt->bind_param('s', $ip);
                            $ipStmt->execute();
                            $hourly = (int)($ipStmt->get_result()->fetch_assoc()['total'] ?? 0);
                            $ipStmt->close();

                            if ($recent === 0 && $hourly < 5) {
                                $token = bin2hex(random_bytes(32));
                                $hash = hash('sha256', $token);

                                $invalidate = $conn->prepare(
                                    "UPDATE password_reset_tokens
                                     SET used_at=NOW()
                                     WHERE account_type='staff' AND account_id=? AND used_at IS NULL"
                                );
                                $invalidate->bind_param('i', $id);
                                $invalidate->execute();
                                $invalidate->close();

                                $insert = $conn->prepare(
                                    "INSERT INTO password_reset_tokens
                                     (account_type, account_id, email, token_hash, expires_at, requested_ip)
                                     VALUES ('staff', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)"
                                );
                                $insert->bind_param('isss', $id, $email, $hash, $ip);
                                $insert->execute();
                                $insert->close();

                                $url = echotech_forgot_base_url()
                                    . '/reset_password.php?type=staff&token=' . rawurlencode($token);

                                $name = trim((string)($account['full_name'] ?? $account['username'] ?? 'there')) ?: 'there';
                                if (!echotech_forgot_mail($email, $name, $url)) {
                                    echotech_forgot_log('Token created but email delivery failed for staff account ID ' . $id);
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                /* Do not expose database details to the user. */
                echotech_forgot_log('Request processing failed: ' . $e->getMessage());
            }

            /* Deliberately generic to prevent account enumeration. */
            $success = true;
            $message = 'If the account exists and has a valid email address, a password reset link has been sent.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password | EchoTech POS</title>
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
    <p>Enter your EchoTech username or registered email address. If the account is eligible, we will send a secure reset link.</p>

    <?php if ($message !== ''): ?>
        <div class="alert<?= $success ? '' : ' error' ?>"><?= echotech_forgot_h($message) ?></div>
    <?php endif; ?>

    <?php if ($serviceReady): ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= echotech_forgot_h(echotech_forgot_csrf()) ?>">
            <label class="label" for="identifier">Username or Email</label>
            <input class="input" id="identifier" name="identifier" autocomplete="username email" required>
            <button class="btn" type="submit">SEND RESET LINK</button>
        </form>
    <?php else: ?>
        <div class="alert error">The password reset service is temporarily unavailable. Please try again later.</div>
    <?php endif; ?>

    <a class="back" href="/index.php">â† Back to Login</a>
</main>
</body>
</html>
