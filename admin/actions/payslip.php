<?php
declare(strict_types=1);

/*
 * Change this whenever the actual payslip PDF renderer/layout changes.
 * Existing stored PDFs with an older version are regenerated once so the
 * official document matches the current payslip template.
 */
const PAYSLIP_PDF_RENDER_VERSION = '2026-08-30-v4';

/*
 * EchoTech POS â€” dedicated Payslip module.
 *
 * This file owns ALL payslip presentation, PDF generation, verification
 * identity and payslip email attachment logic. Payroll only owns the payroll
 * register and calls this module when an employee needs a payslip.
 */

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* Direct access and inclusion from payroll both use the same auth/database. */
foreach ([
    __DIR__ . '/../../includes/auth.php',
    __DIR__ . '/../../includes/auth_helpers.php',
    __DIR__ . '/../../auth.php'
] as $f) {
    if (is_file($f)) { require_once $f; break; }
}

foreach ([
    __DIR__ . '/../../includes/conn.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../db.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        if (isset($conn) && $conn instanceof mysqli) break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('require_login') && !function_exists('require_admin')) {
    /* Existing deployments normally provide these through auth.php. */
}
if (function_exists('require_login')) require_login();
if (function_exists('require_admin')) require_admin();
elseif (($_SESSION['role'] ?? '') !== 'Admin') { http_response_code(403); exit('Access denied.'); }

if (!function_exists('payroll_complete_h')) {
    function payroll_complete_h(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('payroll_complete_money')) {
    function payroll_complete_money(float $v): string {
        return 'K' . number_format($v, 2);
    }
}

/* Payslip-specific helpers must always be available, even when payroll.php
 * has already provided the generic payroll helpers. */
if (!function_exists('payroll_verification_token')) {
    function payroll_verification_token(): string {
        try {
            return strtoupper(bin2hex(random_bytes(24)));
        } catch (Throwable $e) {
            return strtoupper(hash('sha256', uniqid((string)mt_rand(), true) . microtime(true)));
        }
    }
}

if (!function_exists('payroll_verification_hash')) {
    function payroll_verification_hash(array $row, string $token): string {
        $parts = [
            (string)($row['pharmacy_id'] ?? ''),
            (string)($row['staff_id'] ?? ''),
            (string)($row['payroll_period'] ?? ''),
            (string)($row['basic_salary'] ?? 0),
            (string)($row['allowances'] ?? 0),
            (string)($row['bonus'] ?? 0),
            (string)($row['overtime'] ?? 0),
            (string)($row['other_earnings'] ?? 0),
            (string)($row['paye'] ?? 0),
            (string)($row['napsa'] ?? 0),
            (string)($row['nhima'] ?? 0),
            (string)($row['loan_deduction'] ?? 0),
            (string)($row['salary_advance'] ?? 0),
            (string)($row['other_deductions'] ?? 0),
            (string)($row['gross_salary'] ?? 0),
            (string)($row['total_deductions'] ?? 0),
            (string)($row['net_salary'] ?? 0),
            (string)($row['employer_napsa'] ?? 0),
            (string)($row['employer_nhima'] ?? 0),
            $token,
        ];
        return hash('sha256', implode('|', $parts));
    }
}

if (!function_exists('payroll_public_base_url')) {
    function payroll_public_base_url(): string {
        $forwarded = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || $forwarded === 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'echotechpos.onrender.com');
        $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $host) ?: 'echotechpos.onrender.com';
        return ($https ? 'https://' : 'http://') . $host;
    }
}

if (!function_exists('payroll_complete_table')) {
    function payroll_complete_table(mysqli $db, string $table): bool {
        $safe = $db->real_escape_string($table);
        $r = @$db->query("SHOW TABLES LIKE '{$safe}'");
        return $r instanceof mysqli_result && $r->num_rows > 0;
    }
}

if (!function_exists('payroll_complete_col')) {
    function payroll_complete_col(mysqli $db, string $table, string $column): bool {
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = $db->real_escape_string($column);
        $r = @$db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $r instanceof mysqli_result && $r->num_rows > 0;
    }
}

if (!function_exists('payroll_complete_rows')) {
    function payroll_complete_rows(mysqli $db, string $sql, string $types = '', array $params = []): array {
        $stmt = @$db->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') @$stmt->bind_param($types, ...$params);
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $r = @$stmt->get_result();
        $rows = [];
        if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('payroll_mail_config')) {
function payroll_mail_config(): array {
    /*
     * Render Free blocks outbound SMTP ports such as 587.
     * Payroll therefore uses Brevo's HTTPS transactional-email API.
     * The API key must live only in the server environment.
     */
    return [
        'api_key' => trim((string)(getenv('BREVO_API_KEY') ?: '')),
        'from_email' => trim((string)(getenv('MAIL_FROM_EMAIL') ?: '')),
        'from_name' => trim((string)(getenv('MAIL_FROM_NAME') ?: 'PHARMANOVA Payroll')),
        'timeout' => max(5, min(60, (int)(getenv('MAIL_TIMEOUT') ?: 20))),
        'api_url' => 'https://api.brevo.com/v3/smtp/email',
    ];
}

function payroll_mail_send_html(
    string $to,
    string $toName,
    string $subject,
    string $html,
    string $text,
    array $attachments = []
): array {
    $cfg = payroll_mail_config();

    if ($cfg['api_key'] === '' || $cfg['from_email'] === '') {
        return [
            'ok' => false,
            'error' => 'Payroll email is not configured. Set BREVO_API_KEY and MAIL_FROM_EMAIL in the server environment.'
        ];
    }

    if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The configured payroll sender email address is invalid.'];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Employee email address is invalid.'];
    }

    $safeName = trim(preg_replace('/[\\r\\n]+/', ' ', $toName));
    $safeSubject = trim(preg_replace('/[\\r\\n]+/', ' ', $subject));

    $payload = [
        'sender' => [
            'name' => $cfg['from_name'] !== '' ? $cfg['from_name'] : 'PHARMANOVA Payroll',
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

    /*
     * Payslips MUST be delivered as real file attachments.
     * Brevo expects the field name "attachment" and each item must contain
     * a filename plus base64-encoded file content.
     */
    if (empty($attachments)) {
        return [
            'ok' => false,
            'error' => 'The payslip PDF was not supplied to the email service.'
        ];
    }

    $payload['attachment'] = [];
    foreach ($attachments as $attachment) {
        $name = trim((string)($attachment['name'] ?? ''));
        $content = $attachment['content'] ?? null;

        if ($name === '' || !is_string($content) || $content === '') {
            return [
                'ok' => false,
                'error' => 'The payslip attachment is empty or has no filename.'
            ];
        }

        /* Only allow the payroll PDF attachment through this sender. */
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return [
                'ok' => false,
                'error' => 'The payslip attachment must be a PDF file.'
            ];
        }

        if (strncmp($content, '%PDF-', 5) !== 0) {
            return [
                'ok' => false,
                'error' => 'The generated payslip is not a valid PDF document.'
            ];
        }

        $payload['attachment'][] = [
            'name' => $name,
            'content' => base64_encode($content),
        ];
    }

    if (count($payload['attachment']) < 1) {
        return [
            'ok' => false,
            'error' => 'No payslip PDF attachment was prepared.'
        ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Could not encode the payroll email request.'];
    }

    $headers = [
        'accept: application/json',
        'api-key: ' . $cfg['api_key'],
        'content-type: application/json',
    ];

    /* Preferred path: PHP cURL over HTTPS/443. */
    if (function_exists('curl_init')) {
        $ch = curl_init($cfg['api_url']);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not initialize the HTTPS email client.'];
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
        $curlErrno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $detail = $curlError !== '' ? $curlError : 'HTTPS request failed.';
            return ['ok' => false, 'error' => 'Could not connect to the email service: ' . $detail];
        }

        $decoded = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'ok' => true,
                'message_id' => is_array($decoded) ? (string)($decoded['messageId'] ?? '') : '',
            ];
        }

        $message = '';
        if (is_array($decoded)) {
            $message = trim((string)($decoded['message'] ?? $decoded['code'] ?? ''));
        }
        if ($message === '') {
            $message = 'Brevo returned HTTP ' . $httpCode . '.';
        }

        /* Never expose the API key in an error returned to the browser. */
        return ['ok' => false, 'error' => 'Email service rejected the request: ' . $message];
    }

    /* Fallback for hosts without the PHP cURL extension. */
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
    if (preg_match('/\\s(\\d{3})\\s/', $statusLine, $m)) {
        $httpCode = (int)$m[1];
    }

    if ($response === false) {
        return ['ok' => false, 'error' => 'Could not connect to the email service over HTTPS.'];
    }

    $decoded = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'ok' => true,
            'message_id' => is_array($decoded) ? (string)($decoded['messageId'] ?? '') : '',
        ];
    }

    $message = is_array($decoded) ? trim((string)($decoded['message'] ?? $decoded['code'] ?? '')) : '';
    return [
        'ok' => false,
        'error' => $message !== '' ? 'Email service rejected the request: ' . $message : 'Email service returned HTTP ' . $httpCode . '.',
    ];
}

}
function payroll_payslip_email_message(array $row, string $companyName, string $verificationUrl, string $periodLabel): array {
    $name = trim((string)($row['staff_name'] ?? 'Employee'));
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html><body style="margin:0;background:#f3f6fa;font-family:Arial,sans-serif;color:#202831;">'
        . '<div style="max-width:680px;margin:24px auto;background:#fff;border:1px solid #dfe6ee;border-radius:12px;overflow:hidden;">'
        . '<div style="padding:22px;background:#1f6fff;color:#fff;"><h1 style="margin:0;font-size:21px;">' . $e($companyName) . '</h1>'
        . '<div style="margin-top:5px;font-size:13px;">Official Payroll Payslip</div></div>'
        . '<div style="padding:22px;">'
        . '<p style="font-size:15px;">Dear <strong>' . $e($name) . '</strong>,</p>'
        . '<p>Your official payslip for <strong>' . $e($periodLabel) . '</strong> is attached to this email as a PDF.</p>'
        . '<p style="font-size:13px;color:#5d6b79;">The attached PDF is the same official payslip displayed in the Admin Payroll system. Use the verification address below to confirm its authenticity.</p>'
        . '<p><a href="' . $e($verificationUrl) . '" style="display:inline-block;padding:10px 15px;background:#1f6fff;color:#fff;text-decoration:none;border-radius:7px;font-weight:bold;">Verify Payslip</a></p>'
        . '<p style="margin-top:22px;font-size:11px;color:#7a8794;">Please keep the attached PDF for your records.</p>'
        . '</div></div></body></html>';

    $text = $companyName . " - Official Payroll Payslip\n\n"
        . "Dear " . $name . ",\n\n"
        . "Your official payslip for " . $periodLabel . " is attached to this email as a PDF.\n\n"
        . "Verify this payslip:\n" . $verificationUrl . "\n";

    return ['html'=>$html,'text'=>$text];
}

function payroll_ensure_payslip_identity(mysqli $conn, int $pharmacyId, array &$row): bool {
    // Payslip verification depends on both fields being available in payroll_records.
    if (!payroll_complete_col($conn, 'payroll_records', 'verification_token')) return false;
    if (!payroll_complete_col($conn, 'payroll_records', 'document_hash')) return false;

    $token = trim((string)($row['verification_token'] ?? ''));
    if ($token === '') {
        $token = payroll_verification_token();
        $stmt = $conn->prepare(
            "UPDATE payroll_records SET verification_token=? WHERE id=? AND pharmacy_id=? AND (verification_token IS NULL OR verification_token='')"
        );
        if (!$stmt) return false;
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) return false;
        $stmt->bind_param('sii', $token, $id, $pharmacyId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $stmt->close();

        // Re-read the value in case another request populated it first.
        $check = $conn->prepare("SELECT verification_token FROM payroll_records WHERE id=? AND pharmacy_id=? LIMIT 1");
        if ($check) {
            $check->bind_param('ii', $id, $pharmacyId);
            if ($check->execute()) {
                $res = $check->get_result();
                $saved = $res ? $res->fetch_assoc() : null;
                if (!empty($saved['verification_token'])) $token = (string)$saved['verification_token'];
            }
            $check->close();
        }
        $row['verification_token'] = $token;
    }

    $hash = trim((string)($row['document_hash'] ?? ''));
    if ($hash === '') {
        $hash = payroll_verification_hash($row, $token);
        $stmt = $conn->prepare(
            "UPDATE payroll_records SET document_hash=? WHERE id=? AND pharmacy_id=? AND (document_hash IS NULL OR document_hash='')"
        );
        if (!$stmt) return false;
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) return false;
        $stmt->bind_param('sii', $hash, $id, $pharmacyId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $stmt->close();
        $row['document_hash'] = $hash;
    }

    return $token !== '' && $hash !== '';
}

function payroll_prepare_payslip_view_data(mysqli $conn, array &$row, string $companyName, string $periodLabel): array {
    $pharmacyId = (int)($row['pharmacy_id'] ?? 0);
    $staffId = (int)($row['staff_id'] ?? 0);

    foreach ([
        'basic_salary','allowances','bonus','overtime','other_earnings',
        'paye','napsa','nhima','loan_deduction','salary_advance',
        'other_deductions','gross_salary','total_deductions','net_salary',
        'employer_napsa','employer_nhima'
    ] as $col) {
        $row[$col] = (float)($row[$col] ?? 0);
    }

    if (trim((string)($row['branch_name'] ?? '')) === '') {
        $row['branch_name'] = 'Main Branch';
        $branchId = (int)($row['branch_id'] ?? 0);
        if ($branchId > 0 && payroll_complete_table($conn, 'branches')) {
            $b = payroll_complete_rows(
                $conn,
                "SELECT branch_name FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1",
                'ii',
                [$branchId, $pharmacyId]
            );
            if (!empty($b[0]['branch_name'])) $row['branch_name'] = $b[0]['branch_name'];
        }
    }

    if (!payroll_ensure_payslip_identity($conn, $pharmacyId, $row)) {
        throw new RuntimeException('Could not create the official payslip verification identity.');
    }

    $verificationToken = trim((string)($row['verification_token'] ?? ''));
    $verificationUrl = payroll_public_base_url()
        . '/verify-payslip.php?token=' . rawurlencode($verificationToken);

    $template = null;
    if ($staffId > 0 && payroll_complete_table($conn, 'payroll_salary_templates')) {
        $templates = payroll_complete_rows(
            $conn,
            "SELECT * FROM payroll_salary_templates WHERE pharmacy_id=? AND staff_id=? LIMIT 1",
            'ii',
            [$pharmacyId, $staffId]
        );
        $template = $templates[0] ?? null;
    }

    $allowanceItems = [];
    $deductionItems = [];
    if (is_array($template)) {
        $decodedAllowances = json_decode((string)($template['allowances_json'] ?? '[]'), true);
        $decodedDeductions = json_decode((string)($template['deductions_json'] ?? '[]'), true);

        foreach (is_array($decodedAllowances) ? $decodedAllowances : [] as $item) {
            if (!is_array($item)) continue;
            $name = trim((string)($item['name'] ?? $item['component'] ?? ''));
            $amount = max(0, (float)($item['amount'] ?? 0));
            if ($name !== '' && $amount > 0) $allowanceItems[] = ['name'=>$name,'amount'=>$amount];
        }
        foreach (is_array($decodedDeductions) ? $decodedDeductions : [] as $item) {
            if (!is_array($item)) continue;
            $name = trim((string)($item['name'] ?? $item['component'] ?? ''));
            $amount = max(0, (float)($item['amount'] ?? 0));
            if ($name !== '' && $amount > 0) $deductionItems[] = ['name'=>$name,'amount'=>$amount];
        }
    }

    $periodValue = trim((string)($row['payroll_period'] ?? ''));
    $date = DateTime::createFromFormat('!Y-m-d', $periodValue . '-01');
    if (!$date) {
        $timestamp = strtotime('1 ' . $periodLabel);
        $days = $timestamp !== false ? (int)date('t', $timestamp) : 30;
    } else {
        $days = (int)$date->format('t');
    }

    $currency = is_array($template) && trim((string)($template['currency'] ?? '')) !== ''
        ? trim((string)$template['currency']) : 'ZMW';
    $bankName = is_array($template) ? trim((string)($template['bank_name'] ?? '')) : '';
    $accountName = is_array($template) ? trim((string)($template['account_name'] ?? '')) : '';
    $accountNumber = is_array($template) ? trim((string)($template['account_number'] ?? '')) : '';
    $grade = is_array($template) ? trim((string)($template['grade_name'] ?? '')) : '';

    $isFinal = in_array(strtolower((string)($row['status'] ?? '')), ['approved','paid','locked'], true);

    return [
        'payslip' => $row,
        'pharmacyName' => $companyName,
        'periodLabel' => $periodLabel,
        'payslipAllowanceItems' => $allowanceItems,
        'payslipDeductionItems' => $deductionItems,
        'payslipDaysInMonth' => $days,
        'payslipCurrency' => $currency,
        'payslipBankName' => $bankName,
        'payslipAccountName' => $accountName,
        'payslipAccountNumber' => $accountNumber,
        'payslipGrade' => $grade,
        'payslipVerificationToken' => $isFinal ? $verificationToken : '',
        'payslipVerificationUrl' => $isFinal ? $verificationUrl : '',
        'payslipIsFinal' => $isFinal,
    ];
}

function payroll_payslip_css(): string {
    return <<<'PAYSLIP_CSS'
.payslip-sheet{
    display:none;
    width:190mm;
    max-width:100%;
    margin:0 auto;
    background:#fff;
    color:#111;
    padding:0;
    font-family:Arial,Helvetica,sans-serif;
    font-size:10px;
    line-height:1.25;
}
.payslip-sheet *{box-sizing:border-box}
.payslip-header{
    width:100%;
    border:1px solid #111;
    text-align:center;
}
.payslip-company{
    padding:7px 8px;
    font-size:15px;
    line-height:1.1;
    font-weight:800;
    text-transform:uppercase;
    border-bottom:1px solid #111;
}
.payslip-title{
    padding:5px 8px;
    font-size:11px;
    line-height:1.1;
    font-weight:700;
}
.payslip-employee{
    display:grid;
    grid-template-columns:1fr 1fr;
    width:100%;
    border:1px solid #111;
    border-top:0;
}
.payslip-employee-column{min-width:0}
.payslip-employee-column + .payslip-employee-column{border-left:1px solid #111}
.payslip-info-row{
    display:grid;
    grid-template-columns:31% 69%;
    min-height:22px;
}
.payslip-info-row + .payslip-info-row{border-top:1px solid #b8b8b8}
.payslip-info-label{
    padding:4px 6px;
    font-weight:700;
    white-space:nowrap;
}
.payslip-info-value{
    padding:4px 6px;
    word-break:break-word;
}
.payslip-tables{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:8px;
    width:100%;
    margin-top:8px;
}
.payslip-table-wrap{
    min-width:0;
    border:1px solid #111;
    overflow:hidden;
}
.payslip-table-title{
    text-align:center;
    font-weight:800;
    padding:5px 6px;
    border-bottom:1px solid #111;
    font-size:11px;
    line-height:1.1;
}
.payslip-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}
.payslip-table th,.payslip-table td{
    border-right:1px solid #b8b8b8;
    border-bottom:1px solid #cfcfcf;
    padding:4px 5px;
    vertical-align:middle;
    font-size:9px;
    line-height:1.2;
}
.payslip-table th:last-child,.payslip-table td:last-child{border-right:0}
.payslip-table th{
    font-weight:800;
    background:#fafafa;
    text-align:left;
    font-size:8.5px;
    text-transform:uppercase;
}
.payslip-table th:first-child,.payslip-table td:first-child{
    width:13%;
    text-align:center;
}
.payslip-table th:nth-child(2),.payslip-table td:nth-child(2){width:57%}
.payslip-table th:last-child,.payslip-table td:last-child{
    width:30%;
    text-align:right;
}
.payslip-table .amount{text-align:right;white-space:nowrap}
.payslip-table .total-row td{
    border-top:1px solid #111;
    border-bottom:0;
    font-weight:800;
    font-size:9.5px;
}
.payslip-summary{
    margin-top:8px;
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:6px 8px;
    width:100%;
}
.payslip-summary-row{
    display:grid;
    grid-template-columns:62% 38%;
    min-height:23px;
    border:1px solid #111;
}
.payslip-summary-label{padding:4px 6px;font-weight:700}
.payslip-summary-value{
    padding:4px 6px;
    text-align:right;
    font-weight:700;
    white-space:nowrap;
}
.payslip-summary-row.full{
    grid-column:1 / -1;
    width:50%;
}
.payslip-net-row{
    margin-top:6px;
    border:1px solid #111;
    display:grid;
    grid-template-columns:62% 38%;
    width:50%;
    min-height:26px;
}
.payslip-net-row .payslip-summary-label{font-size:10px;font-weight:800}
.payslip-net-row .payslip-summary-value{font-size:12px;font-weight:800}
.payslip-authentication{
    margin-top:7px;
    border:1px solid #111;
    display:grid;
    grid-template-columns:minmax(0,1fr) 34mm;
    min-height:29mm;
    page-break-inside:avoid;
}
.payslip-auth-copy{
    padding:6px 8px;
    min-width:0;
}
.payslip-auth-title{
    font-size:9px;
    font-weight:800;
    letter-spacing:.35px;
}
.payslip-auth-status{
    margin-top:2px;
    font-size:8px;
    font-weight:700;
}
.payslip-auth-id{
    margin-top:4px;
    font-size:8px;
}
.payslip-auth-instruction{
    margin-top:3px;
    max-width:145mm;
    font-size:7.5px;
    line-height:1.25;
}
.payslip-auth-url{
    margin-top:3px;
    font-size:6.5px;
    line-height:1.15;
    word-break:break-all;
    color:#333;
}
.payslip-qr{
    border-left:1px solid #111;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:3px;
}
.payslip-qr img{
    display:block;
    width:25mm;
    height:25mm;
    object-fit:contain;
}
.payslip-qr span{
    margin-top:1px;
    font-size:6px;
    font-weight:800;
}
.payslip-footer{
    margin-top:8px;
    padding-top:6px;
    border-top:1px solid #111;
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:10px;
    font-size:8.5px;
}
.payslip-footer > div:nth-child(2){text-align:center}
.payslip-footer > div:nth-child(3){text-align:right}
.payslip-note{margin-top:6px;font-size:8px;color:#4b5563;text-align:center}
@media(max-width:900px){
    .payslip-sheet{width:100%}
}
@media(max-width:650px){
    .payslip-tables{grid-template-columns:1fr}
    .payslip-summary{grid-template-columns:1fr}
    .payslip-summary-row.full,.payslip-net-row{width:100%}
    .payslip-authentication{grid-template-columns:1fr}
    .payslip-qr{border-left:0;border-top:1px solid #111;padding:5px}
    .payslip-footer{grid-template-columns:1fr}
    .payslip-footer > div{text-align:left!important}
}@media print{
    @page{
        size:Letter portrait;
        margin:10mm;
    }
    html,body{
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }
    body.print-payslip .app{
        display:none!important;
    }
    body.print-payslip .payslip-sheet{
        display:block!important;
        visibility:visible!important;
        width:190mm!important;
        max-width:190mm!important;
        margin:0 auto!important;
        padding:0!important;
        background:#fff!important;
        color:#111!important;
        font-size:10px!important;
    }
    body.print-payslip .payslip-sheet *{
        visibility:visible!important;
    }
    body.print-payslip .payslip-tables,
    body.print-payslip .payslip-summary,
    body.print-payslip .payslip-footer{
        page-break-inside:avoid;
    }
}

PAYSLIP_CSS;
}

function payroll_render_payslip_sheet(array $data): string {
    $payslip = $data['payslip'];
    $pharmacyName = (string)$data['pharmacyName'];
    $periodLabel = (string)$data['periodLabel'];
    $payslipAllowanceItems = $data['payslipAllowanceItems'] ?? [];
    $payslipDeductionItems = $data['payslipDeductionItems'] ?? [];
    $payslipDaysInMonth = (int)($data['payslipDaysInMonth'] ?? 30);
    $payslipCurrency = (string)($data['payslipCurrency'] ?? 'ZMW');
    $payslipBankName = (string)($data['payslipBankName'] ?? '');
    $payslipAccountName = (string)($data['payslipAccountName'] ?? '');
    $payslipAccountNumber = (string)($data['payslipAccountNumber'] ?? '');
    $payslipGrade = (string)($data['payslipGrade'] ?? '');
    $payslipVerificationToken = (string)($data['payslipVerificationToken'] ?? '');
    $payslipVerificationUrl = (string)($data['payslipVerificationUrl'] ?? '');
    $payslipIsFinal = (bool)($data['payslipIsFinal'] ?? false);

    ob_start();
?>
<!-- PAYSLIP_TEMPLATE_START -->
<section class="payslip-sheet" id="payslipSheet">

    <div class="payslip-header">
        <div class="payslip-company">
            <?= payroll_complete_h($pharmacyName) ?>
        </div>
        <div class="payslip-title">
            Salary Slip for <?= payroll_complete_h($periodLabel) ?>
        </div>
    </div>

    <div class="payslip-employee">
        <div class="payslip-employee-column">
            <div class="payslip-info-row"><div class="payslip-info-label">Name</div><div class="payslip-info-value"><?= payroll_complete_h($payslip['staff_name']) ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Designation</div><div class="payslip-info-value"><?= payroll_complete_h($payslip['staff_role']) ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Location</div><div class="payslip-info-value"><?= payroll_complete_h($payslip['branch_name']) ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Employee No.</div><div class="payslip-info-value"><?= $payslip['employee_number'] !== '' ? payroll_complete_h($payslip['employee_number']) : (int)$payslip['staff_id'] ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Salary Grade</div><div class="payslip-info-value"><?= payroll_complete_h($payslipGrade !== '' ? $payslipGrade : 'â€”') ?></div></div>
        </div>
        <div class="payslip-employee-column">
            <div class="payslip-info-row"><div class="payslip-info-label">Department</div><div class="payslip-info-value">â€”</div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Bank Name</div><div class="payslip-info-value"><?= payroll_complete_h($payslipBankName !== '' ? $payslipBankName : 'â€”') ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Account Name</div><div class="payslip-info-value"><?= payroll_complete_h($payslipAccountName !== '' ? $payslipAccountName : $payslip['staff_name']) ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Bank Account No.</div><div class="payslip-info-value"><?= payroll_complete_h($payslipAccountNumber !== '' ? $payslipAccountNumber : 'â€”') ?></div></div>
            <div class="payslip-info-row"><div class="payslip-info-label">Currency</div><div class="payslip-info-value"><?= payroll_complete_h($payslipCurrency) ?></div></div>
        </div>
    </div>

    <div class="payslip-tables">
        <div class="payslip-table-wrap">
            <div class="payslip-table-title">Earnings</div>
            <table class="payslip-table"><thead><tr><th>Serial<br>No.</th><th>Salary Head</th><th>Amount (<?= payroll_complete_h($payslipCurrency) ?>)</th></tr></thead><tbody>
<?php $earningSerial = 1; ?>
            <tr><td><?= $earningSerial++ ?></td><td>Basic Salary</td><td class="amount"><?= payroll_complete_money((float)$payslip['basic_salary']) ?></td></tr>
<?php $aggregateNamedAllowances = 0.0; foreach ($payslipAllowanceItems as $allowance): $aggregateNamedAllowances += (float)$allowance['amount']; ?>
            <tr><td><?= $earningSerial++ ?></td><td><?= payroll_complete_h($allowance['name']) ?></td><td class="amount"><?= payroll_complete_money((float)$allowance['amount']) ?></td></tr>
<?php endforeach; $remainingAllowances = max(0,(float)$payslip['allowances']-$aggregateNamedAllowances); if ($remainingAllowances > 0.004): ?>
            <tr><td><?= $earningSerial++ ?></td><td>Other Allowances</td><td class="amount"><?= payroll_complete_money($remainingAllowances) ?></td></tr>
<?php endif; if ((float)$payslip['bonus'] > 0): ?>
            <tr><td><?= $earningSerial++ ?></td><td>Bonus</td><td class="amount"><?= payroll_complete_money((float)$payslip['bonus']) ?></td></tr>
<?php endif; if ((float)$payslip['overtime'] > 0): ?>
            <tr><td><?= $earningSerial++ ?></td><td>Overtime</td><td class="amount"><?= payroll_complete_money((float)$payslip['overtime']) ?></td></tr>
<?php endif; if ((float)$payslip['other_earnings'] > 0): ?>
            <tr><td><?= $earningSerial++ ?></td><td>Other Earnings / Reimbursement</td><td class="amount"><?= payroll_complete_money((float)$payslip['other_earnings']) ?></td></tr>
<?php endif; ?>
            <tr class="total-row"><td></td><td>Salary (Gross) / PM</td><td class="amount"><?= payroll_complete_money((float)$payslip['gross_salary']) ?></td></tr>
            </tbody></table>
        </div>

        <div class="payslip-table-wrap">
            <div class="payslip-table-title">Deductions</div>
            <table class="payslip-table"><thead><tr><th>Serial<br>No.</th><th>Salary Head</th><th>Amount (<?= payroll_complete_h($payslipCurrency) ?>)</th></tr></thead><tbody>
<?php $deductionSerial = 1; ?>
            <tr><td><?= $deductionSerial++ ?></td><td>PAYE</td><td class="amount"><?= payroll_complete_money((float)$payslip['paye']) ?></td></tr>
            <tr><td><?= $deductionSerial++ ?></td><td>NAPSA - Employee</td><td class="amount"><?= payroll_complete_money((float)$payslip['napsa']) ?></td></tr>
            <tr><td><?= $deductionSerial++ ?></td><td>NHIMA - Employee</td><td class="amount"><?= payroll_complete_money((float)$payslip['nhima']) ?></td></tr>
<?php $aggregateNamedDeductions = 0.0; foreach ($payslipDeductionItems as $deduction): $aggregateNamedDeductions += (float)$deduction['amount']; ?>
            <tr><td><?= $deductionSerial++ ?></td><td><?= payroll_complete_h($deduction['name']) ?></td><td class="amount"><?= payroll_complete_money((float)$deduction['amount']) ?></td></tr>
<?php endforeach; if ((float)$payslip['loan_deduction'] > 0): ?>
            <tr><td><?= $deductionSerial++ ?></td><td>Loan</td><td class="amount"><?= payroll_complete_money((float)$payslip['loan_deduction']) ?></td></tr>
<?php endif; if ((float)$payslip['salary_advance'] > 0): ?>
            <tr><td><?= $deductionSerial++ ?></td><td>Salary Advance</td><td class="amount"><?= payroll_complete_money((float)$payslip['salary_advance']) ?></td></tr>
<?php endif; if ((float)$payslip['other_deductions'] > 0): ?>
            <tr><td><?= $deductionSerial++ ?></td><td>Other Deductions</td><td class="amount"><?= payroll_complete_money((float)$payslip['other_deductions']) ?></td></tr>
<?php endif; ?>
            <tr class="total-row"><td></td><td>Total Deduction</td><td class="amount"><?= payroll_complete_money((float)$payslip['total_deductions']) ?></td></tr>
            </tbody></table>
        </div>
    </div>

    <div class="payslip-summary">
        <div class="payslip-summary-row"><div class="payslip-summary-label">Salary (Gross) / PM</div><div class="payslip-summary-value"><?= payroll_complete_money((float)$payslip['gross_salary']) ?></div></div>
        <div class="payslip-summary-row"><div class="payslip-summary-label">Total Deduction</div><div class="payslip-summary-value"><?= payroll_complete_money((float)$payslip['total_deductions']) ?></div></div>
        <div class="payslip-summary-row"><div class="payslip-summary-label">Salary (CTC) / PM</div><div class="payslip-summary-value"><?= payroll_complete_money((float)$payslip['gross_salary']+(float)$payslip['employer_napsa']+(float)$payslip['employer_nhima']) ?></div></div>
        <div class="payslip-summary-row"><div class="payslip-summary-label">Payment Status</div><div class="payslip-summary-value"><?= payroll_complete_h(ucfirst((string)($payslip['status'] ?? 'draft'))) ?></div></div>
        <div class="payslip-summary-row full"><div class="payslip-summary-label">Total Number of Days</div><div class="payslip-summary-value"><?= $payslipDaysInMonth ?></div></div>
    </div>

    <div class="payslip-net-row"><div class="payslip-summary-label">NET SALARY</div><div class="payslip-summary-value"><?= payroll_complete_money((float)$payslip['net_salary']) ?></div></div>

<?php if ($payslipIsFinal && $payslipVerificationToken !== ''): ?>
    <div class="payslip-authentication">
        <div class="payslip-auth-copy">
            <div class="payslip-auth-title">OFFICIAL ELECTRONIC PAYSLIP</div>
            <div class="payslip-auth-status">âœ“ Issued by <?= payroll_complete_h($pharmacyName) ?> Payroll</div>
            <div class="payslip-auth-id">Payslip ID: <strong><?= payroll_complete_h('PS-' . substr($payslipVerificationToken,0,8) . '-' . substr($payslipVerificationToken,8,8)) ?></strong></div>
            <div class="payslip-auth-instruction">Scan the QR code or visit the verification address to confirm this payslip against the official payroll record.</div>
            <div class="payslip-auth-url"><?= payroll_complete_h($payslipVerificationUrl) ?></div>
        </div>
        <div class="payslip-qr"><img src="https://quickchart.io/qr?text=<?= rawurlencode($payslipVerificationUrl) ?>&size=150&margin=1" alt="Payslip verification QR code"><span>SCAN TO VERIFY</span></div>
    </div>
<?php endif; ?>

    <div class="payslip-footer">
        <div>Payment: <strong><?= payroll_complete_h($payslip['payment_method'] ?? 'Not paid') ?></strong></div>
        <div>Payment Reference: <strong><?= payroll_complete_h($payslip['payment_reference'] ?? 'â€”') ?></strong></div>
        <div>Generated: <strong><?= payroll_complete_h(date('d M Y H:i')) ?></strong></div>
    </div>
    <div class="payslip-note">This payslip is generated from the Payroll register for <?= payroll_complete_h($periodLabel) ?>.</div>

</section>
<!-- PAYSLIP_TEMPLATE_END -->
<?php
    return (string)ob_get_clean();
}

function payroll_payslip_pdf_content(array $data): string {
    /*
     * SINGLE SOURCE OF TRUTH:
     *
     * The administrator's printable payslip is produced by
     * payroll_render_payslip_sheet() + payroll_payslip_css().
     *
     * The email attachment MUST use those exact same two functions.
     * Do not add a second payslip layout, replacement fonts, replacement
     * dimensions, A4 overrides, or email-specific payslip CSS here.
     */

    $chromiumCandidates = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/opt/google/chrome/google-chrome',
    ];

    $chromium = '';
    foreach ($chromiumCandidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $chromium = $candidate;
            break;
        }
    }

    if ($chromium === '') {
        $which = @shell_exec(
            'command -v chromium 2>/dev/null || '
            . 'command -v chromium-browser 2>/dev/null || '
            . 'command -v google-chrome 2>/dev/null || '
            . 'command -v google-chrome-stable 2>/dev/null'
        );
        $which = trim((string)$which);
        if ($which !== '' && is_executable($which)) {
            $chromium = $which;
        }
    }

    if ($chromium === '') {
        throw new RuntimeException(
            'The browser PDF engine is not installed on the server. '
            . 'Install Chromium in the Docker image and redeploy.'
        );
    }

    /* EXACT SAME PAYSLIP HTML AND EXACT SAME PAYSLIP CSS AS ADMIN PRINT. */
    $sheet = payroll_render_payslip_sheet($data);
    $css = payroll_payslip_css();

    /*
     * No visual overrides are intentionally added here.
     * payroll_payslip_css() already contains the @media print rules used by
     * the Admin print view. Chromium is instructed to emulate print media.
     */
    $html = '<!doctype html>'
        . '<html><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>' . $css . '</style>'
        . '<style>html,body{margin:0!important;padding:0!important;background:#fff!important;}body{width:190mm!important;}body.print-payslip .payslip-sheet{display:block!important;visibility:visible!important;width:190mm!important;max-width:190mm!important;margin:0 auto!important;}</style>'
        . '</head><body class="print-payslip">'
        . $sheet
        . '</body></html>';

    $tmpDir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'echotech_payslip_' . bin2hex(random_bytes(8));

    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not create a temporary directory for the payslip PDF.');
    }

    $htmlFile = $tmpDir . DIRECTORY_SEPARATOR . 'payslip.html';
    $pdfFile = $tmpDir . DIRECTORY_SEPARATOR . 'payslip.pdf';
    $profileDir = $tmpDir . DIRECTORY_SEPARATOR . 'chrome-profile';

    try {
        if (@file_put_contents($htmlFile, $html, LOCK_EX) === false) {
            throw new RuntimeException('Could not create the temporary payslip document.');
        }

        if (!@mkdir($profileDir, 0700, true) && !is_dir($profileDir)) {
            throw new RuntimeException('Could not create the temporary browser profile.');
        }

        /*
         * Chromium uses print media for --print-to-pdf, so the exact
         * @media print rules from payroll_payslip_css() are applied.
         * There is deliberately NO second payslip renderer here.
         */
        $command = escapeshellarg($chromium)
            . ' --headless=new'
            . ' --no-sandbox'
            . ' --disable-gpu'
            . ' --disable-dev-shm-usage'
            . ' --no-first-run'
            . ' --no-default-browser-check'
            . ' --user-data-dir=' . escapeshellarg($profileDir)
            . ' --run-all-compositor-stages-before-draw'
            . ' --virtual-time-budget=5000'
            . ' --no-pdf-header-footer'
            . ' --print-to-pdf-no-header'
            . ' --print-to-pdf=' . escapeshellarg($pdfFile)
            . ' ' . escapeshellarg('file://' . $htmlFile)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($pdfFile)) {
            $detail = trim(implode("\n", $output));
            throw new RuntimeException(
                'Chromium could not generate the payslip PDF.'
                . ($detail !== '' ? ' ' . mb_substr($detail, 0, 500) : '')
            );
        }

        $pdf = @file_get_contents($pdfFile);
        if (!is_string($pdf) || strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Chromium returned an invalid payslip PDF document.');
        }

        return $pdf;
    } finally {
        foreach ([$pdfFile, $htmlFile] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (is_dir($profileDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $profileDir,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($profileDir);
        }

        if (is_dir($tmpDir)) {
            @rmdir($tmpDir);
        }
    }
}


function payroll_payslip_ensure_document_table(mysqli $conn): void {
    if (payroll_complete_table($conn, 'payroll_payslip_documents')) return;

    $create = "
        CREATE TABLE `payroll_payslip_documents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payroll_record_id` INT UNSIGNED NOT NULL,
            `pharmacy_id` INT UNSIGNED NOT NULL,
            `pdf_hash` CHAR(64) NOT NULL,
            `renderer_version` VARCHAR(40) NOT NULL DEFAULT '',
            `pdf_blob` LONGBLOB NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_payslip_document_record` (`payroll_record_id`,`pharmacy_id`),
            KEY `idx_payslip_document_pharmacy` (`pharmacy_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!$conn->query($create) && !payroll_complete_table($conn, 'payroll_payslip_documents')) {
        throw new RuntimeException('Could not create official payslip document table: ' . $conn->error);
    }

    if (!payroll_complete_col($conn, 'payroll_payslip_documents', 'renderer_version')) {
        @$conn->query("ALTER TABLE payroll_payslip_documents ADD COLUMN renderer_version VARCHAR(40) NOT NULL DEFAULT '' AFTER pdf_hash");
    }
}

function payroll_get_official_payslip_pdf(mysqli $conn, int $pharmacyId, array &$row, array $data): string {
    $recordId = (int)($row['id'] ?? 0);
    if ($recordId <= 0 || $pharmacyId <= 0) {
        throw new RuntimeException('Invalid payroll record for official payslip generation.');
    }

    payroll_payslip_ensure_document_table($conn);

    /* Once approved/paid/locked, the issued PDF must never be silently rebuilt. */
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if (!in_array($status, ['approved', 'paid', 'locked'], true)) {
        throw new RuntimeException('An official payslip can only be issued after payroll approval.');
    }

    $existing = payroll_complete_rows(
        $conn,
        "SELECT pdf_hash, renderer_version, pdf_blob
         FROM payroll_payslip_documents
         WHERE payroll_record_id=? AND pharmacy_id=?
         LIMIT 1",
        'ii',
        [$recordId, $pharmacyId]
    );

    if (!empty($existing)) {
        $blob = $existing[0]['pdf_blob'] ?? null;
        $storedVersion = trim((string)($existing[0]['renderer_version'] ?? ''));
        if ($storedVersion === PAYSLIP_PDF_RENDER_VERSION
            && is_string($blob)
            && strncmp($blob, '%PDF-', 5) === 0) {
            return $blob;
        }
        /* Older/broken artifacts are intentionally regenerated with this renderer. */
        @$conn->query(
            "DELETE FROM payroll_payslip_documents
             WHERE payroll_record_id=" . $recordId . " AND pharmacy_id=" . $pharmacyId
        );
    }

    $pdf = payroll_payslip_pdf_content($data);
    if (strncmp($pdf, '%PDF-', 5) !== 0) {
        throw new RuntimeException('The generated official payslip is not a valid PDF document.');
    }

    $pdfHash = hash('sha256', $pdf);

    /* INSERT IGNORE makes the first issued PDF authoritative under concurrency. */
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO payroll_payslip_documents
            (payroll_record_id, pharmacy_id, pdf_hash, renderer_version, pdf_blob)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new RuntimeException('Could not prepare official payslip storage.');
    }

    $rendererVersion = PAYSLIP_PDF_RENDER_VERSION;
    $stmt->bind_param('iissb', $recordId, $pharmacyId, $pdfHash, $rendererVersion, $pdf);
    $stmt->send_long_data(4, $pdf);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not save the official payslip PDF.');
    }
    $inserted = ($stmt->affected_rows === 1);
    $stmt->close();

    /* If another request won the race, always return the already-issued PDF. */
    if (!$inserted) {
        $existing = payroll_complete_rows(
            $conn,
            "SELECT pdf_blob FROM payroll_payslip_documents
             WHERE payroll_record_id=? AND pharmacy_id=? LIMIT 1",
            'ii',
            [$recordId, $pharmacyId]
        );
        if (!empty($existing) && is_string($existing[0]['pdf_blob'] ?? null)
            && strncmp($existing[0]['pdf_blob'], '%PDF-', 5) === 0) {
            return $existing[0]['pdf_blob'];
        }
        throw new RuntimeException('The official payslip PDF could not be retrieved after storage.');
    }

    return $pdf;
}

function payroll_send_one_payslip_email(mysqli $conn, int $pharmacyId, array &$row, string $companyName, string $periodLabel): array {
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '') return ['ok'=>false, 'error'=>'No email address is recorded for this employee.'];

    try {
        $data = payroll_prepare_payslip_view_data($conn, $row, $companyName, $periodLabel);
        $verificationUrl = (string)($data['payslipVerificationUrl'] ?? '');
        $content = payroll_payslip_email_message($row, $companyName, $verificationUrl, $periodLabel);
        /* Email MUST attach the already-issued official PDF, never render a second copy. */
        $pdf = payroll_get_official_payslip_pdf($conn, $pharmacyId, $row, $data);

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)($row['staff_name'] ?? 'Employee')));
        $safeName = trim((string)$safeName, '._-');
        if ($safeName === '') $safeName = 'Employee';
        $filename = 'Payslip-' . $safeName . '-' . preg_replace('/[^0-9-]/', '', $periodLabel) . '.pdf';

        $subject = $companyName . ' â€” Payslip for ' . $periodLabel;
        $result = payroll_mail_send_html(
            $email,
            (string)($row['staff_name'] ?? ''),
            $subject,
            $content['html'],
            $content['text'],
            [['name'=>$filename,'content'=>$pdf]]
        );
    } catch (Throwable $e) {
        $result = ['ok'=>false, 'error'=>$e->getMessage()];
    }

    $status = $result['ok'] ? 'sent' : 'failed';
    $errorText = $result['ok'] ? null : (string)$result['error'];
    $stmt = $conn->prepare("UPDATE payroll_records SET payslip_email_status=?, payslip_email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE payslip_email_sent_at END, payslip_email_error=? WHERE id=? AND pharmacy_id=?");
    if ($stmt) {
        $id = (int)($row['id'] ?? 0);
        $stmt->bind_param('sssii', $status, $status, $errorText, $id, $pharmacyId);
        $stmt->execute();
        $stmt->close();
    }
    $row['payslip_email_status'] = $status;
    $row['payslip_email_error'] = $errorText;
    if ($result['ok']) $row['payslip_email_sent_at'] = date('Y-m-d H:i:s');
    return $result;
}

function payroll_send_period_payslips(mysqli $conn, int $pharmacyId, string $period, string $companyName): array {
    $rows = payroll_complete_rows(
        $conn,
        "SELECT p.*, 
                COALESCE(NULLIF(TRIM(u.full_name),''),NULLIF(TRIM(u.username),''),CONCAT('Staff #',u.id)) AS staff_name,
                COALESCE(u.role,'Staff') AS staff_role,
                COALESCE(u.email,'') AS email,
                u.id AS employee_number
         FROM payroll_records p
         INNER JOIN users u ON u.id=p.staff_id AND u.pharmacy_id=p.pharmacy_id
         WHERE p.pharmacy_id=? AND p.payroll_period=?
         ORDER BY p.staff_id ASC",
        'is',
        [$pharmacyId, $period]
    );

    $sent = 0;
    $failed = 0;
    $details = [];

    foreach ($rows as &$row) {
        $row['branch_name'] = 'Main Branch';
        $branchId = (int)($row['branch_id'] ?? 0);
        if ($branchId > 0 && payroll_complete_table($conn, 'branches')) {
            $b = payroll_complete_rows(
                $conn,
                "SELECT branch_name FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1",
                'ii',
                [$branchId, $pharmacyId]
            );
            if (!empty($b[0]['branch_name'])) $row['branch_name'] = $b[0]['branch_name'];
        }

        foreach ([
            'basic_salary','allowances','bonus','overtime','other_earnings',
            'paye','napsa','nhima','loan_deduction','salary_advance',
            'other_deductions','gross_salary','total_deductions','net_salary',
            'employer_napsa','employer_nhima'
        ] as $col) {
            $row[$col] = (float)($row[$col] ?? 0);
        }

        $result = payroll_send_one_payslip_email(
            $conn,
            $pharmacyId,
            $row,
            $companyName,
            date('F Y', strtotime($period . '-01'))
        );

        if ($result['ok']) {
            $sent++;
        } else {
            $failed++;
            $details[] = ($row['staff_name'] ?? ('Staff #' . $row['staff_id'])) . ': ' . $result['error'];
        }
    }
    unset($row);

    return ['sent'=>$sent, 'failed'=>$failed, 'details'=>$details];
}



/* -------------------------------------------------------------------------
 * Standalone payslip controller.
 * URL: /admin/actions/payslip.php?record_id=123&format=pdf
 * ------------------------------------------------------------------------- */
if (
    basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'payslip.php'
    || (isset($_GET['official_pdf']) && $_GET['official_pdf'] === '1')
) {
    $recordId = (int)($_GET['record_id'] ?? 0);
    $staffId = (int)($_GET['staff_id'] ?? 0);
    $month = max(1, min(12, (int)($_POST['month'] ?? $_GET['month'] ?? date('n'))));
    $year = max(2020, min(2100, (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'))));
    $period = sprintf('%04d-%02d', $year, $month);

    $pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
    if ($pharmacyId <= 0 && function_exists('require_pharmacy')) {
        require_pharmacy();
        $pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
    }
    if ($pharmacyId <= 0) { http_response_code(403); exit('Your account is not assigned to a valid pharmacy.'); }

    $companyName = 'PHARMANOVA';
    if (payroll_complete_table($conn, 'pharmacies')) {
        foreach (['name','pharmacy_name','business_name'] as $col) {
            if (payroll_complete_col($conn, 'pharmacies', $col)) {
                $r = payroll_complete_rows($conn, "SELECT `{$col}` AS name FROM pharmacies WHERE id=? LIMIT 1", 'i', [$pharmacyId]);
                if (!empty($r[0]['name'])) $companyName = (string)$r[0]['name'];
                break;
            }
        }
    }

    /* Payslip email delivery belongs entirely to this module. */
    $requestAction = (string)($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction === 'send_all_payslips') {
        $result = payroll_send_period_payslips($conn, $pharmacyId, $period, $companyName);
        $message = $result['sent'] . ' payslip(s) emailed successfully.';
        if ($result['failed'] > 0) {
            $message .= ' ' . $result['failed'] . ' failed.';
            if (!empty($result['details'])) $message .= ' ' . implode(' | ', array_slice($result['details'], 0, 3));
        }
        $key = $result['failed'] > 0 ? 'error' : 'saved';
        header('Location: /admin/payroll.php?' . http_build_query([
            'month'=>$month, 'year'=>$year, 'view'=>'payroll', $key=>$message
        ]), true, 303);
        exit;
    }

    if ($recordId <= 0 && $requestAction === 'send_payslip_email') {
        $recordId = (int)($_POST['record_id'] ?? 0);
    }

    if ($recordId > 0) {
        $rows = payroll_complete_rows($conn,
            "SELECT p.*,\n                    COALESCE(NULLIF(TRIM(u.full_name),''),NULLIF(TRIM(u.username),''),CONCAT('Staff #',u.id)) AS staff_name,\n                    COALESCE(u.role,'Staff') AS staff_role,\n                    COALESCE(u.email,'') AS email,\n                    u.id AS employee_number\n             FROM payroll_records p\n             INNER JOIN users u ON u.id=p.staff_id AND u.pharmacy_id=p.pharmacy_id\n             WHERE p.id=? AND p.pharmacy_id=? LIMIT 1",
            'ii', [$recordId,$pharmacyId]
        );
    } else {
        $rows = payroll_complete_rows($conn,
            "SELECT p.*,\n                    COALESCE(NULLIF(TRIM(u.full_name),''),NULLIF(TRIM(u.username),''),CONCAT('Staff #',u.id)) AS staff_name,\n                    COALESCE(u.role,'Staff') AS staff_role,\n                    COALESCE(u.email,'') AS email,\n                    u.id AS employee_number\n             FROM payroll_records p\n             INNER JOIN users u ON u.id=p.staff_id AND u.pharmacy_id=p.pharmacy_id\n             WHERE p.staff_id=? AND p.pharmacy_id=? AND p.payroll_period=? LIMIT 1",
            'iis', [$staffId,$pharmacyId,$period]
        );
    }

    if (!$rows) { http_response_code(404); exit('Payslip not found.'); }
    $row = $rows[0];
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if (!in_array($status, ['approved','paid','locked'], true)) {
        http_response_code(403);
        exit('This payslip is not approved for official issuance.');
    }

    $branchName = 'Main Branch';
    $branchId = (int)($row['branch_id'] ?? 0);
    if ($branchId > 0 && payroll_complete_table($conn, 'branches')) {
        $branchCol = payroll_complete_col($conn, 'branches', 'branch_name') ? 'branch_name' : (payroll_complete_col($conn, 'branches','name') ? 'name' : null);
        if ($branchCol) {
            $b = payroll_complete_rows($conn, "SELECT `{$branchCol}` AS branch_name FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1", 'ii', [$branchId,$pharmacyId]);
            if (!empty($b[0]['branch_name'])) $branchName = (string)$b[0]['branch_name'];
        }
    }
    $row['branch_name'] = $branchName;

    $period = (string)$row['payroll_period'];
    $periodLabel = date('F Y', strtotime($period . '-01'));

    foreach (['basic_salary','allowances','bonus','overtime','other_earnings','paye','napsa','nhima','loan_deduction','salary_advance','other_deductions','gross_salary','total_deductions','net_salary','employer_napsa','employer_nhima'] as $col) {
        $row[$col] = (float)($row[$col] ?? 0);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction === 'send_payslip_email') {
        $result = payroll_send_one_payslip_email(
            $conn,
            $pharmacyId,
            $row,
            $companyName,
            $periodLabel
        );
        $key = $result['ok'] ? 'saved' : 'error';
        $message = $result['ok']
            ? 'Payslip emailed successfully to ' . $row['email'] . '.'
            : 'Payslip email failed: ' . $result['error'];
        header('Location: /admin/payroll.php?' . http_build_query([
            'month'=>$month, 'year'=>$year, 'view'=>'payroll', $key=>$message
        ]), true, 303);
        exit;
    }

    try {
        $data = payroll_prepare_payslip_view_data($conn, $row, $companyName, $periodLabel);
        $pdf = payroll_get_official_payslip_pdf($conn, $pharmacyId, $row, $data);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)($row['staff_name'] ?? 'Employee')));
        $safeName = trim((string)$safeName, '._-') ?: 'Employee';
        $filename = 'Payslip-' . $safeName . '-' . preg_replace('/[^0-9-]/', '', $period) . '.pdf';
        $download = (($_GET['download'] ?? '') === '1');

        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Could not generate the official payslip PDF: ' . $e->getMessage());
    }
}
