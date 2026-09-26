<?php
declare(strict_types=1);

/*
 * EchoTech POS - shared password reset service.
 * Safe for the production conn.php which enables MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT.
 */

const ECHOTECH_RESET_MINUTES = 30;
const ECHOTECH_RESET_COOLDOWN = 60;
const ECHOTECH_RESET_HOURLY_LIMIT = 5;

function echotech_reset_log(string $message): void
{
    error_log('EchoTech password reset: ' . $message);
}

function echotech_reset_bootstrap(mysqli $db): bool
{
    try {
        $sql = "CREATE TABLE IF NOT EXISTS password_reset_tokens (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->query($sql);
        return true;
    } catch (Throwable $e) {
        echotech_reset_log('Token table bootstrap failed: ' . $e->getMessage());
        return false;
    }
}

function echotech_reset_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function echotech_reset_csrf(): string
{
    if (empty($_SESSION['echotech_reset_csrf'])) {
        $_SESSION['echotech_reset_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['echotech_reset_csrf'];
}

function echotech_reset_check_csrf(): void
{
    $sessionToken = (string)($_SESSION['echotech_reset_csrf'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(419);
        exit('Security check failed. Please go back and try again.');
    }
}

function echotech_reset_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function echotech_reset_base_url(): string
{
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'echotechpos.onrender.com');
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host) ?: 'echotechpos.onrender.com';

    return ($https ? 'https://' : 'http://') . $host;
}

function echotech_reset_mail_config(): array
{
    /*
     * EchoTech uses Brevo's HTTPS transactional-email API.
     * This is intentional: Render deployments can block/restrict outbound
     * SMTP ports, while HTTPS/443 is the normal application egress path.
     */
    return [
        'api_key'    => trim((string)(getenv('BREVO_API_KEY') ?: '')),
        'from_email' => trim((string)(getenv('MAIL_FROM_EMAIL') ?: '')),
        'from_name'  => trim((string)(getenv('MAIL_FROM_NAME') ?: 'EchoTech POS')),
        'timeout'    => max(5, min(60, (int)(getenv('MAIL_TIMEOUT') ?: 20))),
        'api_url'    => 'https://api.brevo.com/v3/smtp/email',
    ];
}

function echotech_reset_send_mail(
    string $to,
    string $name,
    string $subject,
    string $html,
    string $text
): bool {
    $cfg = echotech_reset_mail_config();

    if ($cfg['api_key'] === '' || $cfg['from_email'] === '') {
        echotech_reset_log('Brevo email configuration is incomplete. BREVO_API_KEY and MAIL_FROM_EMAIL are required.');
        return false;
    }

    if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
        echotech_reset_log('Configured MAIL_FROM_EMAIL is invalid.');
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echotech_reset_log('Password reset recipient email is invalid.');
        return false;
    }

    $safeName = trim(preg_replace('/[\r\n]+/', ' ', $name));
    $safeSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject));

    $payload = [
        'sender' => [
            'name' => $cfg['from_name'] !== '' ? $cfg['from_name'] : 'EchoTech POS',
            'email' => $cfg['from_email'],
        ],
        'to' => [[
            'email' => $to,
            'name' => $safeName !== '' ? $safeName : $to,
        ]],
        'subject' => $safeSubject,
        'htmlContent' => $html,
        'textContent' => $text,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        echotech_reset_log('Could not encode Brevo password-reset request.');
        return false;
    }

    $headers = [
        'accept: application/json',
        'api-key: ' . $cfg['api_key'],
        'content-type: application/json',
    ];

    echotech_reset_log('Sending password reset through Brevo HTTPS API to ' . $to . ' from ' . $cfg['from_email'] . '.');

    /* Preferred path: PHP cURL over HTTPS/443. */
    if (function_exists('curl_init')) {
        $ch = curl_init($cfg['api_url']);
        if ($ch === false) {
            echotech_reset_log('Could not initialize cURL.');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(15, $cfg['timeout']),
            CURLOPT_TIMEOUT => $cfg['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            echotech_reset_log('Brevo HTTPS request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
            return false;
        }

        $decoded = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            $messageId = is_array($decoded) ? (string)($decoded['messageId'] ?? '') : '';
            echotech_reset_log('Brevo accepted password-reset email. HTTP ' . $httpCode . ($messageId !== '' ? ' messageId=' . $messageId : ''));
            return true;
        }

        $message = '';
        if (is_array($decoded)) {
            $message = trim((string)($decoded['message'] ?? $decoded['code'] ?? ''));
        }
        if ($message === '') {
            $message = 'Brevo returned HTTP ' . $httpCode . '.';
        }

        echotech_reset_log('Brevo rejected password-reset email: ' . $message);
        return false;
    }

    /* Fallback for a PHP build without cURL. */
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $json,
            'timeout' => $cfg['timeout'],
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $response = @file_get_contents($cfg['api_url'], false, $context);
    $statusLine = (string)($http_response_header[0] ?? '');
    $httpCode = 0;

    if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
        $httpCode = (int)$m[1];
    }

    if ($response === false) {
        echotech_reset_log('Brevo HTTPS fallback request failed.');
        return false;
    }

    $decoded = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        $messageId = is_array($decoded) ? (string)($decoded['messageId'] ?? '') : '';
        echotech_reset_log('Brevo accepted password-reset email through HTTPS fallback. HTTP ' . $httpCode . ($messageId !== '' ? ' messageId=' . $messageId : ''));
        return true;
    }

    $message = is_array($decoded)
        ? trim((string)($decoded['message'] ?? $decoded['code'] ?? ''))
        : '';

    echotech_reset_log(
        'Brevo HTTPS fallback rejected password-reset email: '
        . ($message !== '' ? $message : 'HTTP ' . $httpCode)
    );

    return false;
}

function echotech_reset_find_account(mysqli $db, string $type, string $identifier): ?array
{
    try {
        if ($type === 'client') {
            $stmt = $db->prepare(
                "SELECT id, full_name, email
                 FROM clients
                 WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                 LIMIT 1"
            );
        } else {
            $stmt = $db->prepare(
                "SELECT id, full_name, username, email, status, is_frozen
                 FROM users
                 WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                    OR LOWER(TRIM(username)) = LOWER(TRIM(?))
                 LIMIT 1"
            );
        }

        if (!$stmt) {
            return null;
        }

        if ($type === 'client') {
            $stmt->bind_param('s', $identifier);
        } else {
            $stmt->bind_param('ss', $identifier, $identifier);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    } catch (Throwable $e) {
        echotech_reset_log('Account lookup failed: ' . $e->getMessage());
        return null;
    }
}

function echotech_reset_allowed(mysqli $db, string $type, int $accountId, string $email, string $ip): bool
{
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM password_reset_tokens
             WHERE account_type = ?
               AND account_id = ?
               AND created_at >= (NOW() - INTERVAL 1 MINUTE)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $type, $accountId);
        $stmt->execute();
        $recent = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($recent > 0) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM password_reset_tokens
             WHERE requested_ip = ?
               AND created_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $hourly = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $hourly < ECHOTECH_RESET_HOURLY_LIMIT;
    } catch (Throwable $e) {
        echotech_reset_log('Rate-limit check failed: ' . $e->getMessage());
        return false;
    }
}

function echotech_reset_issue(mysqli $db, string $type, array $account, string $ip): ?string
{
    try {
        if (!in_array($type, ['staff', 'client'], true)) {
            return null;
        }

        $email = strtolower(trim((string)($account['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $id = (int)($account['id'] ?? 0);
        if ($id <= 0 || !echotech_reset_allowed($db, $type, $id, $email, $ip)) {
            return null;
        }

        $stmt = $db->prepare(
            "UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE account_type = ?
               AND account_id = ?
               AND used_at IS NULL"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('si', $type, $id);
        $stmt->execute();
        $stmt->close();

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $stmt = $db->prepare(
            "INSERT INTO password_reset_tokens
                (account_type, account_id, email, token_hash, expires_at, requested_ip)
             VALUES
                (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('sisss', $type, $id, $email, $hash, $ip);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? $token : null;
    } catch (Throwable $e) {
        echotech_reset_log('Token issuance failed: ' . $e->getMessage());
        return null;
    }
}

function echotech_reset_consume(mysqli $db, string $type, string $token): ?array
{
    try {
        if (!in_array($type, ['staff', 'client'], true) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = $db->prepare(
            "SELECT id, account_id, email
             FROM password_reset_tokens
             WHERE account_type = ?
               AND token_hash = ?
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ss', $type, $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    } catch (Throwable $e) {
        echotech_reset_log('Token validation failed: ' . $e->getMessage());
        return null;
    }
}

function echotech_reset_set_password(mysqli $db, string $type, array $reset, string $password): bool
{
    try {
        if (!in_array($type, ['staff', 'client'], true)) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = (int)($reset['account_id'] ?? 0);
        $rid = (int)($reset['id'] ?? 0);

        if ($id <= 0 || $rid <= 0 || $hash === false) {
            return false;
        }

        if ($type === 'client') {
            $stmt = $db->prepare('UPDATE clients SET password=? WHERE id=? LIMIT 1');
        } else {
            $stmt = $db->prepare('UPDATE users SET password=? WHERE id=? LIMIT 1');
        }

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            'UPDATE password_reset_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $rid);
        $stmt->execute();
        $used = $stmt->affected_rows === 1;
        $stmt->close();

        return $used;
    } catch (Throwable $e) {
        echotech_reset_log('Password update failed: ' . $e->getMessage());
        return false;
    }
}
