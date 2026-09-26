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
    return [
        'host'       => trim((string)(getenv('MAIL_HOST') ?: '')),
        'port'       => (int)(getenv('MAIL_PORT') ?: 587),
        'username'   => trim((string)(getenv('MAIL_USERNAME') ?: '')),
        'password'   => (string)(getenv('MAIL_PASSWORD') ?: ''),
        'from_email' => trim((string)(getenv('MAIL_FROM_EMAIL') ?: '')),
        'from_name'  => trim((string)(getenv('MAIL_FROM_NAME') ?: 'EchoTech POS')),
        'encryption' => strtolower(trim((string)(getenv('MAIL_ENCRYPTION') ?: 'tls'))),
        'timeout'    => max(5, min(60, (int)(getenv('MAIL_TIMEOUT') ?: 20))),
    ];
}

function echotech_reset_smtp_read($fp, int $timeout): string
{
    stream_set_timeout($fp, $timeout);
    $data = '';

    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return trim($data);
}

function echotech_reset_smtp_expect($fp, array $codes, int $timeout): bool
{
    $response = echotech_reset_smtp_read($fp, $timeout);
    if ($response === '') {
        return false;
    }

    return in_array((int)substr($response, 0, 3), $codes, true);
}

function echotech_reset_smtp_command($fp, string $command, array $codes, int $timeout): bool
{
    return fwrite($fp, $command . "\r\n") !== false
        && echotech_reset_smtp_expect($fp, $codes, $timeout);
}

function echotech_reset_send_mail(
    string $to,
    string $name,
    string $subject,
    string $html,
    string $text
): bool {
    $cfg = echotech_reset_mail_config();

    if ($cfg['host'] === '' || $cfg['username'] === '' || $cfg['password'] === '' || $cfg['from_email'] === '') {
        echotech_reset_log('SMTP is not configured.');
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echotech_reset_log('Invalid recipient email supplied.');
        return false;
    }

    $remote = ($cfg['encryption'] === 'ssl' || $cfg['port'] === 465)
        ? 'ssl://' . $cfg['host'] . ':' . $cfg['port']
        : $cfg['host'] . ':' . $cfg['port'];

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $cfg['timeout'],
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        echotech_reset_log('SMTP connection failed: ' . $errstr);
        return false;
    }

    try {
        $helo = preg_replace(
            '/[^a-zA-Z0-9.-]/',
            '',
            (string)($_SERVER['SERVER_NAME'] ?? 'echotechpos.onrender.com')
        ) ?: 'echotechpos.onrender.com';

        if (!echotech_reset_smtp_expect($fp, [220], $cfg['timeout'])) {
            throw new RuntimeException('SMTP greeting failed');
        }

        if (!echotech_reset_smtp_command($fp, 'EHLO ' . $helo, [250], $cfg['timeout'])) {
            throw new RuntimeException('SMTP EHLO failed');
        }

        if ($cfg['encryption'] === 'tls' && $cfg['port'] !== 465) {
            if (!echotech_reset_smtp_command($fp, 'STARTTLS', [220], $cfg['timeout'])) {
                throw new RuntimeException('SMTP STARTTLS failed');
            }

            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed');
            }

            if (!echotech_reset_smtp_command($fp, 'EHLO ' . $helo, [250], $cfg['timeout'])) {
                throw new RuntimeException('SMTP EHLO after TLS failed');
            }
        }

        if (!echotech_reset_smtp_command($fp, 'AUTH LOGIN', [334], $cfg['timeout'])) {
            throw new RuntimeException('SMTP authentication command failed');
        }
        if (!echotech_reset_smtp_command($fp, base64_encode($cfg['username']), [334], $cfg['timeout'])) {
            throw new RuntimeException('SMTP username rejected');
        }
        if (!echotech_reset_smtp_command($fp, base64_encode($cfg['password']), [235], $cfg['timeout'])) {
            throw new RuntimeException('SMTP password rejected');
        }
        if (!echotech_reset_smtp_command($fp, 'MAIL FROM:<' . $cfg['from_email'] . '>', [250], $cfg['timeout'])) {
            throw new RuntimeException('SMTP sender rejected');
        }
        if (!echotech_reset_smtp_command($fp, 'RCPT TO:<' . $to . '>', [250, 251], $cfg['timeout'])) {
            throw new RuntimeException('SMTP recipient rejected');
        }
        if (!echotech_reset_smtp_command($fp, 'DATA', [354], $cfg['timeout'])) {
            throw new RuntimeException('SMTP DATA rejected');
        }

        $safeName = trim(preg_replace('/[\r\n]+/', ' ', $name));
        $safeSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $boundary = '=_EchoTechReset_' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>',
            'To: ' . ($safeName !== '' ? $safeName . ' <' . $to . '>' : '<' . $to . '>'),
            'Subject: ' . $safeSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $text . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $html . "\r\n\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $body = preg_replace('/^\./m', '..', $body);

        if (fwrite($fp, $body . "\r\n.\r\n") === false) {
            throw new RuntimeException('SMTP message write failed');
        }

        if (!echotech_reset_smtp_expect($fp, [250], $cfg['timeout'])) {
            throw new RuntimeException('SMTP message rejected');
        }

        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return true;
    } catch (Throwable $e) {
        echotech_reset_log('SMTP send failed: ' . $e->getMessage());
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return false;
    }
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
