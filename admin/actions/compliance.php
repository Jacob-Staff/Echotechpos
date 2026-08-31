<?php
/**
 * ============================================================
 * EchoTech POS - ADMIN COMPLIANCE / ZRA
 * ============================================================
 * File: /admin/actions/compliance.php
 *
 * Phase 1 only:
 * - ZRA taxpayer/compliance profile
 * - Smart Invoice/VSDC readiness
 * - Branch/device register
 * - Compliance invoices/readiness register
 * - Tax obligations
 * - Tax payments
 * - Audit trail
 *
 * IMPORTANT:
 * This file uses the EXISTING production compliance schema.
 * It does not create a second/legacy schema and it does not
 * send live invoices to ZRA/VSDC.
 * ============================================================
 */
declare(strict_types=1);

date_default_timezone_set('Africa/Lusaka');

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

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/* ------------------------------------------------------------
 | Database
 * ------------------------------------------------------------ */
$connectionCandidates = [
    __DIR__ . '/../../includes/conn.php',
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../../config.php',
];

foreach ($connectionCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
    }
    if (isset($conn) && $conn instanceof mysqli) {
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

/* ------------------------------------------------------------
 | Authentication
 * ------------------------------------------------------------ */
$authCandidates = [
    __DIR__ . '/../../includes/auth.php',
    __DIR__ . '/../../includes/auth_helpers.php',
    __DIR__ . '/../../auth.php',
];

foreach ($authCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

if (function_exists('require_login')) {
    require_login();
}

if (function_exists('require_admin')) {
    require_admin();
}

/*
 * Compliance is an ADMIN module. Do not silently turn this into
 * an HR module. HR has its own pages/aside in the new structure.
 */
$user_role = (string)($_SESSION['role']
    ?? $_SESSION['user_role']
    ?? $_SESSION['userRole']
    ?? 'Admin');

$normalizedRole = strtolower(trim($user_role));

if (!in_array($normalizedRole, ['admin', 'administrator', 'management'], true)) {
    http_response_code(403);
    exit('Access denied. Compliance is restricted to Administrative and Management staff.');
}

/* ------------------------------------------------------------
 | Tenant context
 * ------------------------------------------------------------ */
$pharmacy_id = (int)($_SESSION['pharmacy_id']
    ?? $_SESSION['pharmacyId']
    ?? 0);

$branch_id = (int)($_SESSION['branch_id']
    ?? $_SESSION['current_branch_id']
    ?? 0);

if ($pharmacy_id <= 0 && $branch_id > 0) {
    $stmt = $conn->prepare(
        'SELECT pharmacy_id FROM branches WHERE id=? LIMIT 1'
    );

    if ($stmt) {
        $stmt->bind_param('i', $branch_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $pharmacy_id = (int)($row['pharmacy_id'] ?? 0);

        if ($pharmacy_id > 0) {
            $_SESSION['pharmacy_id'] = $pharmacy_id;
        }
    }
}

if ($pharmacy_id <= 0) {
    http_response_code(401);
    exit('Your pharmacy session has expired. Please sign in again.');
}

/* ------------------------------------------------------------
 | Helpers
 * ------------------------------------------------------------ */
function compliance_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function compliance_money(mixed $value): string
{
    return 'K ' . number_format((float)$value, 2);
}

function compliance_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function compliance_columns(mysqli $db, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cache[$table] = [];

    if (!compliance_table_exists($db, $table)) {
        return [];
    }

    $safe = str_replace('`', '``', $table);
    $result = @$db->query("SHOW COLUMNS FROM `{$safe}`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $cache[$table][] = (string)$row['Field'];
        }
    }

    return $cache[$table];
}

function compliance_has_column(mysqli $db, string $table, string $column): bool
{
    return in_array($column, compliance_columns($db, $table), true);
}

function compliance_rows(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = @$db->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        @$stmt->bind_param($types, ...$params);
    }

    if (!@$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = @$stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function compliance_one(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): ?array {
    $rows = compliance_rows($db, $sql, $types, $params);
    return $rows[0] ?? null;
}

function compliance_exec(
    mysqli $db,
    string $sql,
    string $types = '',
    array $params = []
): bool {
    $stmt = @$db->prepare($sql);

    if (!$stmt) {
        return false;
    }

    if ($types !== '') {
        @$stmt->bind_param($types, ...$params);
    }

    $ok = @$stmt->execute();
    $stmt->close();

    return (bool)$ok;
}

function compliance_actor_id(): ?int
{
    foreach ([
        $_SESSION['user_id'] ?? null,
        $_SESSION['userId'] ?? null,
        $_SESSION['id'] ?? null,
        $_SESSION['sessionUserId'] ?? null,
    ] as $id) {
        if ((int)$id > 0) {
            return (int)$id;
        }
    }

    return null;
}

function compliance_actor_name(): string
{
    return (string)(
        $_SESSION['full_name']
        ?? $_SESSION['name']
        ?? $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? 'Administrator'
    );
}

function compliance_csrf(): string
{
    if (empty($_SESSION['compliance_csrf'])) {
        $_SESSION['compliance_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['compliance_csrf'];
}

function compliance_check_csrf(): void
{
    $stored = (string)($_SESSION['compliance_csrf'] ?? '');
    $posted = (string)($_POST['csrf_token'] ?? '');

    if ($stored === '' || $posted === '' || !hash_equals($stored, $posted)) {
        http_response_code(419);
        exit('Security token expired. Please reload the Compliance page.');
    }
}

function compliance_redirect(
    string $view = 'overview',
    string $message = '',
    string $type = 'success'
): never {
    $url = 'compliance.php?view=' . rawurlencode($view);

    if ($message !== '') {
        $url .= '&notice=' . rawurlencode($message)
            . '&notice_type=' . rawurlencode($type);
    }

    header('Location: ' . $url);
    exit;
}

function compliance_audit(
    mysqli $db,
    int $pharmacyId,
    string $action,
    string $entityType = 'compliance',
    ?int $entityId = null,
    string $description = ''
): void {
    if (!compliance_table_exists($db, 'compliance_audit_log')) {
        return;
    }

    $userId = compliance_actor_id();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

    compliance_exec(
        $db,
        'INSERT INTO compliance_audit_log
         (pharmacy_id,user_id,action,entity_type,entity_id,description,ip_address)
         VALUES (?,?,?,?,?,?,?)',
        'iississ',
        [
            $pharmacyId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $description,
            $ip
        ]
    );
}

/* ------------------------------------------------------------
 | Pharmacy
 * ------------------------------------------------------------ */
$pharmacy = [];

if (compliance_table_exists($conn, 'pharmacies')) {
    $pharmacy = compliance_one(
        $conn,
        'SELECT * FROM pharmacies WHERE id=? LIMIT 1',
        'i',
        [$pharmacy_id]
    ) ?? [];
}

$pharmacy_name = (string)(
    $pharmacy['name']
    ?? $pharmacy['pharmacy_name']
    ?? 'PHARMACY POS'
);

/* ------------------------------------------------------------
 | POST actions
 * ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    compliance_check_csrf();

    $action = trim((string)($_POST['action'] ?? ''));

    /* ---------- Taxpayer / ZRA profile ---------- */
    if ($action === 'save_taxpayer') {
        $businessName = trim((string)($_POST['business_name'] ?? $pharmacy_name));
        $tpin = trim((string)($_POST['tpin'] ?? ''));
        $vatRegistered = isset($_POST['vat_registered']) ? 1 : 0;
        $vatNumber = trim((string)($_POST['vat_number'] ?? ''));
        $incomeTax = isset($_POST['income_tax_registered']) ? 1 : 0;
        $turnoverTax = isset($_POST['turnover_tax_registered']) ? 1 : 0;

        $smartStatus = trim((string)($_POST['smart_invoice_status'] ?? 'not_configured'));
        $environment = trim((string)($_POST['smart_invoice_environment'] ?? 'test'));
        $cisCode = trim((string)($_POST['cis_code'] ?? ''));
        $vsdcSerial = trim((string)($_POST['vsdc_serial'] ?? ''));
        $vsdcStatus = trim((string)($_POST['vsdc_status'] ?? 'not_configured'));
        $vsdcEndpoint = trim((string)($_POST['vsdc_endpoint'] ?? ''));
        $taxAccount = trim((string)($_POST['tax_account_reference'] ?? ''));
        $contactName = trim((string)($_POST['compliance_contact_name'] ?? ''));
        $contactEmail = trim((string)($_POST['compliance_contact_email'] ?? ''));
        $contactPhone = trim((string)($_POST['compliance_contact_phone'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $allowedSmart = ['not_configured','pending','test','connected','suspended'];
        if (!in_array($smartStatus, $allowedSmart, true)) {
            $smartStatus = 'not_configured';
        }

        $environment = in_array($environment, ['test','production'], true)
            ? $environment
            : 'test';

        $allowedVsdc = ['not_configured','offline','online','error'];
        if (!in_array($vsdcStatus, $allowedVsdc, true)) {
            $vsdcStatus = 'not_configured';
        }

        if (!compliance_table_exists($conn, 'compliance_settings')) {
            compliance_redirect('taxpayer', 'The compliance_settings table is missing from the database.', 'error');
        }

        $existing = compliance_one(
            $conn,
            'SELECT id FROM compliance_settings WHERE pharmacy_id=? LIMIT 1',
            'i',
            [$pharmacy_id]
        );

        $userId = compliance_actor_id();

        if ($existing) {
            $ok = compliance_exec(
                $conn,
                'UPDATE compliance_settings SET
                    tpin=?,
                    business_name=?,
                    vat_registered=?,
                    vat_number=?,
                    income_tax_registered=?,
                    turnover_tax_registered=?,
                    smart_invoice_status=?,
                    smart_invoice_environment=?,
                    cis_code=?,
                    vsdc_serial=?,
                    vsdc_status=?,
                    vsdc_endpoint=?,
                    tax_account_reference=?,
                    compliance_contact_name=?,
                    compliance_contact_email=?,
                    compliance_contact_phone=?,
                    notes=?,
                    updated_by=?
                 WHERE pharmacy_id=?',
                'ssisisisssssssssii',
                [
                    $tpin,
                    $businessName,
                    $vatRegistered,
                    $vatNumber,
                    $incomeTax,
                    $turnoverTax,
                    $smartStatus,
                    $environment,
                    $cisCode,
                    $vsdcSerial,
                    $vsdcStatus,
                    $vsdcEndpoint,
                    $taxAccount,
                    $contactName,
                    $contactEmail,
                    $contactPhone,
                    $notes,
                    $userId,
                    $pharmacy_id
                ]
            );

            $recordId = (int)$existing['id'];
        } else {
            $ok = compliance_exec(
                $conn,
                'INSERT INTO compliance_settings
                    (pharmacy_id,tpin,business_name,vat_registered,vat_number,
                     income_tax_registered,turnover_tax_registered,
                     smart_invoice_status,smart_invoice_environment,cis_code,
                     vsdc_serial,vsdc_status,vsdc_endpoint,tax_account_reference,
                     compliance_contact_name,compliance_contact_email,
                     compliance_contact_phone,notes,updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                'issisisssssssssssii',
                [
                    $pharmacy_id,
                    $tpin,
                    $businessName,
                    $vatRegistered,
                    $vatNumber,
                    $incomeTax,
                    $turnoverTax,
                    $smartStatus,
                    $environment,
                    $cisCode,
                    $vsdcSerial,
                    $vsdcStatus,
                    $vsdcEndpoint,
                    $taxAccount,
                    $contactName,
                    $contactEmail,
                    $contactPhone,
                    $notes,
                    $userId
                ]
            );

            $recordId = (int)$conn->insert_id;
        }

        if (!$ok) {
            compliance_redirect(
                'taxpayer',
                'The taxpayer profile could not be saved. Please check the database error/log.',
                'error'
            );
        }

        compliance_audit(
            $conn,
            $pharmacy_id,
            'Updated taxpayer compliance profile',
            'compliance_settings',
            $recordId,
            'Updated ZRA/Smart Invoice readiness information.'
        );

        compliance_redirect('taxpayer', 'Taxpayer compliance profile saved.');
    }

    /* ---------- Branch/device ---------- */
    if ($action === 'save_branch_device') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $recordBranch = (int)($_POST['branch_id'] ?? 0);
        $deviceName = trim((string)($_POST['device_name'] ?? ''));
        $serial = trim((string)($_POST['vsdc_serial'] ?? ''));
        $status = trim((string)($_POST['registration_status'] ?? 'pending'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $allowedStatuses = ['pending','submitted','registered','active','offline','error'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        $validBranch = compliance_one(
            $conn,
            'SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1',
            'ii',
            [$recordBranch, $pharmacy_id]
        );

        if (!$validBranch) {
            compliance_redirect(
                'branches',
                'The selected branch is not part of this pharmacy.',
                'error'
            );
        }

        if (!compliance_table_exists($conn, 'compliance_branch_devices')) {
            compliance_redirect(
                'branches',
                'The compliance_branch_devices table is missing from the database.',
                'error'
            );
        }

        $registeredAt = in_array($status, ['registered','active'], true)
            ? date('Y-m-d H:i:s')
            : null;

        $actor = compliance_actor_name();

        if ($recordId > 0) {
            $ok = compliance_exec(
                $conn,
                'UPDATE compliance_branch_devices SET
                    branch_id=?,
                    device_name=?,
                    vsdc_serial=?,
                    registration_status=?,
                    registered_at=?,
                    notes=?,
                    updated_by=?
                 WHERE id=? AND pharmacy_id=?',
                'issssssii',
                [
                    $recordBranch,
                    $deviceName,
                    $serial,
                    $status,
                    $registeredAt,
                    $notes,
                    $actor,
                    $recordId,
                    $pharmacy_id
                ]
            );
        } else {
            $ok = compliance_exec(
                $conn,
                'INSERT INTO compliance_branch_devices
                    (pharmacy_id,branch_id,device_name,vsdc_serial,
                     registration_status,registered_at,notes,created_by,updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                'iisssssss',
                [
                    $pharmacy_id,
                    $recordBranch,
                    $deviceName,
                    $serial,
                    $status,
                    $registeredAt,
                    $notes,
                    $actor,
                    $actor
                ]
            );

            $recordId = (int)$conn->insert_id;
        }

        if (!$ok) {
            compliance_redirect(
                'branches',
                'The branch/device record could not be saved.',
                'error'
            );
        }

        compliance_audit(
            $conn,
            $pharmacy_id,
            'Updated branch/device compliance record',
            'compliance_branch_devices',
            $recordId,
            'Branch/device readiness record updated.'
        );

        compliance_redirect('branches', 'Branch/device compliance record saved.');
    }

    /* ---------- Delete branch/device ---------- */
    if ($action === 'delete_branch_device') {
        $recordId = (int)($_POST['record_id'] ?? 0);

        $ok = compliance_exec(
            $conn,
            'DELETE FROM compliance_branch_devices WHERE id=? AND pharmacy_id=?',
            'ii',
            [$recordId, $pharmacy_id]
        );

        if ($ok) {
            compliance_audit(
                $conn,
                $pharmacy_id,
                'Deleted branch/device compliance record',
                'compliance_branch_devices',
                $recordId
            );
        }

        compliance_redirect('branches', 'Branch/device record removed.');
    }

    /* ---------- Obligation ---------- */
    if ($action === 'save_obligation') {
        $id = (int)($_POST['record_id'] ?? 0);
        $taxType = trim((string)($_POST['tax_type'] ?? 'VAT'));
        $year = (int)($_POST['period_year'] ?? date('Y'));
        $monthRaw = trim((string)($_POST['period_month'] ?? ''));
        $month = $monthRaw === '' ? null : max(1, min(12, (int)$monthRaw));

        $returnDue = trim((string)($_POST['return_due_date'] ?? ''));
        $paymentDue = trim((string)($_POST['payment_due_date'] ?? ''));

        $amountDue = max(0, (float)($_POST['amount_due'] ?? 0));
        $amountPaid = max(0, (float)($_POST['amount_paid'] ?? 0));

        $returnStatus = trim((string)($_POST['return_status'] ?? 'not_started'));
        $paymentStatus = trim((string)($_POST['payment_status'] ?? 'unpaid'));

        $reference = trim((string)($_POST['reference_no'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $returnDue = $returnDue !== '' ? $returnDue : null;
        $paymentDue = $paymentDue !== '' ? $paymentDue : null;

        $allowedReturn = ['not_started','draft','filed','not_applicable'];
        $allowedPayment = ['unpaid','partial','paid','not_applicable'];

        if (!in_array($returnStatus, $allowedReturn, true)) {
            $returnStatus = 'not_started';
        }

        if (!in_array($paymentStatus, $allowedPayment, true)) {
            $paymentStatus = 'unpaid';
        }

        if ($amountPaid >= $amountDue && $amountDue > 0) {
            $paymentStatus = 'paid';
        } elseif ($amountPaid > 0 && $amountPaid < $amountDue) {
            $paymentStatus = 'partial';
        }

        if ($year < 2000 || $year > 2100) {
            $year = (int)date('Y');
        }

        if (!compliance_table_exists($conn, 'compliance_obligations')) {
            compliance_redirect(
                'obligations',
                'The compliance_obligations table is missing from the database.',
                'error'
            );
        }

        $userId = compliance_actor_id();

        if ($id > 0) {
            $ok = compliance_exec(
                $conn,
                'UPDATE compliance_obligations SET
                    tax_type=?,
                    period_year=?,
                    period_month=?,
                    return_due_date=?,
                    payment_due_date=?,
                    amount_due=?,
                    amount_paid=?,
                    return_status=?,
                    payment_status=?,
                    reference_no=?,
                    notes=?,
                    updated_by=?
                 WHERE id=? AND pharmacy_id=?',
                'siissddssssii',
                [
                    $taxType,
                    $year,
                    $month,
                    $returnDue,
                    $paymentDue,
                    $amountDue,
                    $amountPaid,
                    $returnStatus,
                    $paymentStatus,
                    $reference,
                    $notes,
                    $userId,
                    $id,
                    $pharmacy_id
                ]
            );
        } else {
            $ok = compliance_exec(
                $conn,
                'INSERT INTO compliance_obligations
                    (pharmacy_id,tax_type,period_year,period_month,
                     return_due_date,payment_due_date,amount_due,amount_paid,
                     return_status,payment_status,reference_no,notes,created_by,updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                'isiissddssssii',
                [
                    $pharmacy_id,
                    $taxType,
                    $year,
                    $month,
                    $returnDue,
                    $paymentDue,
                    $amountDue,
                    $amountPaid,
                    $returnStatus,
                    $paymentStatus,
                    $reference,
                    $notes,
                    $userId,
                    $userId
                ]
            );

            $id = (int)$conn->insert_id;
        }

        if (!$ok) {
            compliance_redirect(
                'obligations',
                'The tax obligation could not be saved.',
                'error'
            );
        }

        compliance_audit(
            $conn,
            $pharmacy_id,
            'Updated tax obligation',
            'compliance_obligations',
            $id,
            $taxType . ' obligation updated.'
        );

        compliance_redirect('obligations', 'Tax obligation saved.');
    }

    /* ---------- Tax payment ---------- */
    if ($action === 'save_payment') {
        $obligationId = (int)($_POST['obligation_id'] ?? 0);
        $taxType = trim((string)($_POST['tax_type'] ?? 'VAT'));
        $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $method = trim((string)($_POST['method'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($paymentDate === '') {
            $paymentDate = date('Y-m-d');
        }

        if (!compliance_table_exists($conn, 'compliance_payments')) {
            compliance_redirect(
                'payments',
                'The compliance_payments table is missing from the database.',
                'error'
            );
        }

        $userId = compliance_actor_id();
        $obligationValue = $obligationId > 0 ? $obligationId : null;

        $ok = compliance_exec(
            $conn,
            'INSERT INTO compliance_payments
                (pharmacy_id,obligation_id,tax_type,payment_date,amount,
                 payment_reference,method,notes,created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            'iissdsssi',
            [
                $pharmacy_id,
                $obligationValue,
                $taxType,
                $paymentDate,
                $amount,
                $reference,
                $method,
                $notes,
                $userId
            ]
        );

        if (!$ok) {
            compliance_redirect(
                'payments',
                'The tax payment could not be recorded.',
                'error'
            );
        }

        $paymentId = (int)$conn->insert_id;

        /*
         * Keep amount_paid/payment_status synchronized with the
         * canonical compliance_obligations table.
         */
        if ($obligationId > 0 && compliance_table_exists($conn, 'compliance_obligations')) {
            $obligation = compliance_one(
                $conn,
                'SELECT amount_due,amount_paid FROM compliance_obligations
                 WHERE id=? AND pharmacy_id=? LIMIT 1',
                'ii',
                [$obligationId, $pharmacy_id]
            );

            if ($obligation) {
                $newPaid = (float)$obligation['amount_paid'] + $amount;
                $due = (float)$obligation['amount_due'];

                if ($newPaid >= $due && $due > 0) {
                    $status = 'paid';
                } elseif ($newPaid > 0) {
                    $status = 'partial';
                } else {
                    $status = 'unpaid';
                }

                compliance_exec(
                    $conn,
                    'UPDATE compliance_obligations
                     SET amount_paid=?,payment_status=?,updated_by=?
                     WHERE id=? AND pharmacy_id=?',
                    'dsiii',
                    [
                        $newPaid,
                        $status,
                        $userId,
                        $obligationId,
                        $pharmacy_id
                    ]
                );
            }
        }

        compliance_audit(
            $conn,
            $pharmacy_id,
            'Recorded tax payment',
            'compliance_payments',
            $paymentId,
            'Recorded ' . $taxType . ' tax payment.'
        );

        compliance_redirect('payments', 'Tax payment recorded.');
    }
}

/* ------------------------------------------------------------
 | View
 * ------------------------------------------------------------ */
$view = (string)($_GET['view'] ?? 'overview');

$allowedViews = [
    'overview',
    'taxpayer',
    'smart_invoice',
    'branches',
    'invoices',
    'obligations',
    'payments',
    'audit'
];

if (!in_array($view, $allowedViews, true)) {
    $view = 'overview';
}

$notice = (string)($_GET['notice'] ?? '');
$noticeType = (string)($_GET['notice_type'] ?? 'success');

/* ------------------------------------------------------------
 | Settings
 * ------------------------------------------------------------ */
$settings = [];

if (compliance_table_exists($conn, 'compliance_settings')) {
    $settings = compliance_one(
        $conn,
        'SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1',
        'i',
        [$pharmacy_id]
    ) ?? [];
}

$business_name = (string)($settings['business_name'] ?? $pharmacy_name);
$tpin = (string)($settings['tpin'] ?? '');
$vat_registered = (int)($settings['vat_registered'] ?? 0);
$vat_number = (string)($settings['vat_number'] ?? '');
$income_tax_registered = (int)($settings['income_tax_registered'] ?? 1);
$turnover_tax_registered = (int)($settings['turnover_tax_registered'] ?? 0);

$smart_invoice_status = (string)($settings['smart_invoice_status'] ?? 'not_configured');
$smart_invoice_environment = (string)($settings['smart_invoice_environment'] ?? 'test');
$cis_code = (string)($settings['cis_code'] ?? '');
$vsdc_serial = (string)($settings['vsdc_serial'] ?? '');
$vsdc_status = (string)($settings['vsdc_status'] ?? 'not_configured');
$vsdc_endpoint = (string)($settings['vsdc_endpoint'] ?? '');
$tax_account_reference = (string)($settings['tax_account_reference'] ?? '');

$compliance_contact_name = (string)($settings['compliance_contact_name'] ?? '');
$compliance_contact_email = (string)($settings['compliance_contact_email'] ?? '');
$compliance_contact_phone = (string)($settings['compliance_contact_phone'] ?? '');
$compliance_notes = (string)($settings['notes'] ?? '');

/* ------------------------------------------------------------
 | Branches
 * ------------------------------------------------------------ */
$branches = [];

if (compliance_table_exists($conn, 'branches')) {
    $branches = compliance_rows(
        $conn,
        'SELECT id,branch_name,branch_code,is_active
         FROM branches
         WHERE pharmacy_id=?
         ORDER BY branch_name ASC',
        'i',
        [$pharmacy_id]
    );
}

$branch_count = count($branches);

/* ------------------------------------------------------------
 | Branch/device compliance records
 * ------------------------------------------------------------ */
$branchDevices = [];

if (compliance_table_exists($conn, 'compliance_branch_devices')) {
    $branchDevices = compliance_rows(
        $conn,
        'SELECT
            d.*,
            b.branch_name
         FROM compliance_branch_devices d
         LEFT JOIN branches b
           ON b.id=d.branch_id
          AND b.pharmacy_id=d.pharmacy_id
         WHERE d.pharmacy_id=?
         ORDER BY b.branch_name ASC,d.device_name ASC,d.id DESC',
        'i',
        [$pharmacy_id]
    );
}

/* ------------------------------------------------------------
 | Obligations
 * ------------------------------------------------------------ */
$obligations = [];

if (compliance_table_exists($conn, 'compliance_obligations')) {
    $obligations = compliance_rows(
        $conn,
        'SELECT *
         FROM compliance_obligations
         WHERE pharmacy_id=?
         ORDER BY payment_due_date IS NULL,payment_due_date ASC,id DESC',
        'i',
        [$pharmacy_id]
    );
}

/* ------------------------------------------------------------
 | Payments
 * ------------------------------------------------------------ */
$payments = [];

if (compliance_table_exists($conn, 'compliance_payments')) {
    $payments = compliance_rows(
        $conn,
        'SELECT
            p.*,
            o.tax_type AS obligation_tax_type,
            o.period_year,
            o.period_month
         FROM compliance_payments p
         LEFT JOIN compliance_obligations o
           ON o.id=p.obligation_id
          AND o.pharmacy_id=p.pharmacy_id
         WHERE p.pharmacy_id=?
         ORDER BY p.payment_date DESC,p.id DESC',
        'i',
        [$pharmacy_id]
    );
}

/* ------------------------------------------------------------
 | Compliance invoices
 * ------------------------------------------------------------ */
$zraInvoices = [];

if (compliance_table_exists($conn, 'compliance_invoices')) {
    $zraInvoices = compliance_rows(
        $conn,
        'SELECT
            ci.*,
            b.branch_name
         FROM compliance_invoices ci
         LEFT JOIN branches b
           ON b.id=ci.branch_id
          AND b.pharmacy_id=ci.pharmacy_id
         WHERE ci.pharmacy_id=?
         ORDER BY ci.created_at DESC,ci.id DESC
         LIMIT 150',
        'i',
        [$pharmacy_id]
    );
}

/* ------------------------------------------------------------
 | Audit
 * ------------------------------------------------------------ */
$auditRows = [];

if (compliance_table_exists($conn, 'compliance_audit_log')) {
    $auditRows = compliance_rows(
        $conn,
        'SELECT *
         FROM compliance_audit_log
         WHERE pharmacy_id=?
         ORDER BY created_at DESC,id DESC
         LIMIT 150',
        'i',
        [$pharmacy_id]
    );
}

/* ------------------------------------------------------------
 | Summary
 * ------------------------------------------------------------ */
$totalOutstanding = 0.0;
$overdueCount = 0;
$today = date('Y-m-d');

foreach ($obligations as $obligation) {
    $due = (string)($obligation['payment_due_date'] ?? '');
    $dueAmount = (float)($obligation['amount_due'] ?? 0);
    $paidAmount = (float)($obligation['amount_paid'] ?? 0);
    $paymentStatus = (string)($obligation['payment_status'] ?? 'unpaid');

    $balance = max(0, $dueAmount - $paidAmount);

    if ($paymentStatus !== 'paid' && $paymentStatus !== 'not_applicable') {
        $totalOutstanding += $balance;
    }

    if (
        $due !== ''
        && $due < $today
        && $balance > 0
        && $paymentStatus !== 'not_applicable'
    ) {
        $overdueCount++;
    }
}

$totalPayments = 0.0;

foreach ($payments as $payment) {
    $totalPayments += (float)($payment['amount'] ?? 0);
}

/*
 * VAT is a readiness requirement only when the business is
 * actually marked VAT registered.
 */
$readiness = [
    'tpin' => $tpin !== '',
    'vat' => !$vat_registered || $vat_number !== '',
    'tax_registration' => $income_tax_registered || $turnover_tax_registered,
    'smart_invoice' => in_array(
        $smart_invoice_status,
        ['test','connected'],
        true
    ),
    'vsdc' => in_array(
        $vsdc_status,
        ['online','offline'],
        true
    )
];

$readinessCompleted = count(array_filter($readiness));
$readinessTotal = count($readiness);
$readinessPercent = (int)round(
    ($readinessCompleted / max(1, $readinessTotal)) * 100
);

$total_orders = 0;

if (compliance_table_exists($conn, 'sales')
    && compliance_has_column($conn, 'sales', 'pharmacy_id')) {
    $salesRow = compliance_one(
        $conn,
        'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=?',
        'i',
        [$pharmacy_id]
    );

    $total_orders = (int)($salesRow['c'] ?? 0);
}

$csrf = compliance_csrf();

$current_admin_page = 'compliance.php';
$admin_page_title = 'Compliance';
$user_display_name = compliance_actor_name();

/* ------------------------------------------------------------
 | Render
 * ------------------------------------------------------------ */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compliance | <?= compliance_h($pharmacy_name) ?></title>

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
:root{
    --ink:#26313d;
    --muted:#718096;
    --line:#e4e9ef;
    --bg:#f4f7fa;
    --panel:#fff;
    --blue:#2563eb;
    --blue-soft:#eef4ff;
    --green:#138a57;
    --green-soft:#ecf9f2;
    --amber:#b7791f;
    --amber-soft:#fff7e6;
    --red:#c0392b;
    --red-soft:#fff1f0;
    --shadow:0 8px 24px rgba(31,45,61,.06);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
    background:var(--bg);
    color:var(--ink);
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
    font-size:13px;
}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
button{cursor:pointer}

.main{
    min-height:100vh;
    margin-left:250px;
}

.compliance-content{
    padding:22px 28px 40px;
    max-width:1600px;
    margin:0 auto;
}

.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:18px;
}

.eyebrow{
    color:#2563eb;
    font-size:10px;
    font-weight:800;
    letter-spacing:.12em;
    text-transform:uppercase;
}

.page-head h1{
    margin:4px 0 5px;
    font-size:28px;
    line-height:1.15;
}

.page-head p{
    margin:0;
    color:var(--muted);
    font-size:12px;
}

.page-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    border:1px solid #d7dee7;
    background:#fff;
    color:#354052;
    border-radius:8px;
    padding:10px 14px;
    font-size:11px;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    gap:7px;
}

.btn:hover{background:#f8fafc}
.btn.primary{
    background:var(--blue);
    color:#fff;
    border-color:var(--blue);
}
.btn.green{
    background:var(--green);
    color:#fff;
    border-color:var(--green);
}
.btn.danger{
    color:var(--red);
    border-color:#f1c4bf;
}

.notice{
    border:1px solid #cfe0ff;
    background:#f3f7ff;
    color:#31537e;
    padding:12px 14px;
    border-radius:9px;
    margin-bottom:15px;
}
.notice.error{
    border-color:#f0c4bf;
    background:var(--red-soft);
    color:#96382f;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:15px;
}

.summary-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:11px;
    padding:15px;
    box-shadow:var(--shadow);
}

.summary-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    color:#748094;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
}

.summary-icon{
    width:31px;
    height:31px;
    border-radius:8px;
    background:#f0f4f8;
    display:grid;
    place-items:center;
    color:#58677a;
}

.summary-value{
    font-size:25px;
    font-weight:850;
    margin-top:8px;
}

.summary-sub{
    font-size:10px;
    color:#8a95a3;
    margin-top:4px;
}

.progress{
    height:7px;
    background:#edf1f5;
    border-radius:99px;
    overflow:hidden;
    margin-top:9px;
}

.progress span{
    display:block;
    height:100%;
    background:var(--blue);
    border-radius:99px;
}

.tabs{
    display:flex;
    gap:4px;
    background:#fff;
    border:1px solid var(--line);
    border-radius:10px;
    padding:5px;
    overflow-x:auto;
    margin-bottom:15px;
    white-space:nowrap;
}

.tabs a{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:9px 12px;
    border-radius:8px;
    color:#5e6b7c;
    font-weight:750;
    font-size:11px;
}

.tabs a.active{
    background:var(--blue-soft);
    color:var(--blue);
}

.grid-2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.panel{
    background:#fff;
    border:1px solid var(--line);
    border-radius:11px;
    box-shadow:var(--shadow);
    overflow:hidden;
    margin-bottom:14px;
}

.panel-head{
    padding:15px 17px;
    border-bottom:1px solid var(--line);
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:15px;
}

.panel-head h2,
.panel-head h3{
    margin:0 0 4px;
    font-size:15px;
}

.panel-head p{
    margin:0;
    color:var(--muted);
    font-size:11px;
}

.panel-body{padding:16px}

.info-box{
    border:1px solid #d7e3f5;
    background:#f5f8fd;
    border-radius:9px;
    padding:12px;
    color:#52677f;
    font-size:11px;
    line-height:1.55;
}

.readiness-list{
    display:grid;
    gap:9px;
}

.readiness-item{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:center;
    border:1px solid var(--line);
    border-radius:9px;
    padding:12px;
}

.readiness-item strong{font-size:12px}
.readiness-item small{
    display:block;
    color:var(--muted);
    margin-top:3px;
    font-size:10px;
}

.state{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:5px 8px;
    border-radius:999px;
    font-size:9px;
    font-weight:850;
    text-transform:uppercase;
}

.state.ok{
    background:var(--green-soft);
    color:var(--green);
}

.state.warn{
    background:var(--amber-soft);
    color:var(--amber);
}

.state.bad{
    background:var(--red-soft);
    color:var(--red);
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:12px;
}

.field.full{grid-column:1/-1}

.field label{
    display:block;
    font-size:10px;
    font-weight:800;
    color:#566273;
    margin-bottom:5px;
}

.field input,
.field select,
.field textarea{
    width:100%;
    border:1px solid #d8e0e8;
    border-radius:8px;
    padding:9px 10px;
    font-size:11px;
    background:#fff;
    color:#26313d;
}

.field textarea{
    min-height:90px;
    resize:vertical;
}

.check-row{
    display:flex;
    flex-wrap:wrap;
    gap:9px;
}

.check{
    display:flex;
    align-items:center;
    gap:7px;
    border:1px solid var(--line);
    border-radius:8px;
    padding:10px;
    font-size:11px;
    font-weight:700;
}

.check input{width:auto}

.form-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:13px;
}

.table-wrap{
    overflow:auto;
}

.table{
    width:100%;
    border-collapse:collapse;
    min-width:760px;
}

.table th{
    background:#f8fafc;
    color:#718096;
    text-transform:uppercase;
    letter-spacing:.04em;
    font-size:9px;
    text-align:left;
    padding:10px 12px;
    border-bottom:1px solid var(--line);
}

.table td{
    padding:10px 12px;
    border-bottom:1px solid #edf0f4;
    font-size:10px;
    vertical-align:top;
}

.table tr:last-child td{border-bottom:0}

.muted{color:var(--muted)}

.empty{
    padding:35px;
    text-align:center;
    color:#8b96a4;
}

.metric-row{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:9px;
}

.metric{
    border:1px solid var(--line);
    border-radius:9px;
    padding:10px;
}

.metric b{
    display:block;
    font-size:15px;
}

.metric span{
    font-size:9px;
    color:var(--muted);
}

.badge{
    display:inline-block;
    padding:4px 7px;
    border-radius:999px;
    background:#f0f3f6;
    font-size:9px;
    font-weight:800;
}

.badge.green{
    background:var(--green-soft);
    color:var(--green);
}

.badge.amber{
    background:var(--amber-soft);
    color:var(--amber);
}

.badge.red{
    background:var(--red-soft);
    color:var(--red);
}

.inline-form{display:inline}
.danger-link{
    background:none;
    border:0;
    color:var(--red);
    font-size:10px;
    font-weight:750;
}

.section-link{
    color:var(--blue);
    font-size:10px;
    font-weight:800;
}

.mini-note{
    color:#8a95a3;
    font-size:9px;
    margin-top:8px;
}

@media(max-width:1100px){
    .summary-grid{grid-template-columns:repeat(2,1fr)}
    .grid-2{grid-template-columns:1fr}
}

@media(max-width:900px){
    .main{margin-left:0}
    .compliance-content{padding:18px 15px 30px}
    .page-head{flex-direction:column}
    .page-actions{width:100%}
    .page-actions .btn{
        flex:1;
        justify-content:center;
    }
}

@media(max-width:650px){
    .form-grid{grid-template-columns:1fr}
    .field.full{grid-column:auto}
    .metric-row{grid-template-columns:1fr}
}

@media(max-width:560px){
    .summary-grid{grid-template-columns:1fr}
    .page-head h1{font-size:23px}
}

@media print{
    .tabs,
    .page-actions,
    .form-actions,
    .admin-aside,
    .admin-header{
        display:none!important;
    }
    .main{margin-left:0}
    .compliance-content{padding:0}
    .panel{box-shadow:none}
}
</style>
</head>

<body>
<div class="app">

<?php
/*
 * Use the real Admin aside/header.
 * Compliance belongs to the ADMIN panel, not HR.
 */
$adminAside = __DIR__ . '/../admin_aside.php';
$adminHeader = __DIR__ . '/../admin_header.php';

if (is_file($adminAside)) {
    require $adminAside;
}
?>

<main class="main">

<?php
if (is_file($adminHeader)) {
    require $adminHeader;
}
?>

<section class="compliance-content">

<div class="page-head">
    <div>
        <div class="eyebrow">Administration / Regulatory</div>
        <h1>Compliance</h1>
        <p>
            ZRA and Smart Invoice compliance management for
            <?= compliance_h($pharmacy_name) ?>.
        </p>
    </div>

    <div class="page-actions">
        <a class="btn" href="compliance.php?view=overview">
            <i class="fas fa-arrows-rotate"></i>Refresh
        </a>
        <button class="btn" type="button" onclick="window.print()">
            <i class="fas fa-print"></i>Print
        </button>
    </div>
</div>

<?php if ($notice !== ''): ?>
<div class="notice <?= $noticeType === 'error' ? 'error' : '' ?>">
    <i class="fas <?= $noticeType === 'error'
        ? 'fa-circle-exclamation'
        : 'fa-circle-check' ?>"></i>
    <?= compliance_h($notice) ?>
</div>
<?php endif; ?>

<div class="summary-grid">

    <div class="summary-card">
        <div class="summary-top">
            <span>Compliance Readiness</span>
            <span class="summary-icon">
                <i class="fas fa-shield-halved"></i>
            </span>
        </div>
        <div class="summary-value"><?= $readinessPercent ?>%</div>
        <div class="progress">
            <span style="width:<?= $readinessPercent ?>%"></span>
        </div>
        <div class="summary-sub">
            <?= $readinessCompleted ?> of <?= $readinessTotal ?> checks complete
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-top">
            <span>Branches</span>
            <span class="summary-icon">
                <i class="fas fa-store"></i>
            </span>
        </div>
        <div class="summary-value"><?= number_format($branch_count) ?></div>
        <div class="summary-sub">Pharmacy branches</div>
    </div>

    <div class="summary-card">
        <div class="summary-top">
            <span>Outstanding</span>
            <span class="summary-icon">
                <i class="fas fa-file-invoice-dollar"></i>
            </span>
        </div>
        <div class="summary-value" style="font-size:21px">
            <?= compliance_money($totalOutstanding) ?>
        </div>
        <div class="summary-sub">Unpaid compliance obligations</div>
    </div>

    <div class="summary-card">
        <div class="summary-top">
            <span>Overdue</span>
            <span class="summary-icon">
                <i class="fas fa-calendar-xmark"></i>
            </span>
        </div>
        <div class="summary-value"><?= $overdueCount ?></div>
        <div class="summary-sub">Past payment due date</div>
    </div>

</div>

<nav class="tabs" aria-label="Compliance navigation">
<?php
$tabs = [
    'overview' => ['Overview','fa-gauge-high'],
    'taxpayer' => ['ZRA / Smart Invoice','fa-building-columns'],
    'smart_invoice' => ['Tax Configuration','fa-percent'],
    'branches' => ['Branches','fa-code-branch'],
    'invoices' => ['ZRA Invoices','fa-file-invoice'],
    'obligations' => ['Obligations','fa-calendar-check'],
    'payments' => ['Tax Payments','fa-money-bill-transfer'],
    'audit' => ['Audit Log','fa-shield-halved'],
];

foreach ($tabs as $key => $tab):
?>
<a class="<?= $view === $key ? 'active' : '' ?>"
   href="compliance.php?view=<?= rawurlencode($key) ?>">
    <i class="fas <?= $tab[1] ?>"></i>
    <?= compliance_h($tab[0]) ?>
</a>
<?php endforeach; ?>
</nav>

<?php if ($view === 'overview'): ?>

<div class="grid-2">

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Compliance readiness</h2>
            <p>
                Phase 1 prepares the compliance layer before live Smart
                Invoice/VSDC integration.
            </p>
        </div>
        <strong><?= $readinessPercent ?>%</strong>
    </div>

    <div class="panel-body">
        <div class="readiness-list">

            <?php
            $checks = [
                'tpin' => [
                    'Taxpayer TPIN',
                    'ZRA taxpayer identification number'
                ],
                'vat' => [
                    'VAT registration',
                    $vat_registered
                        ? 'VAT registration number recorded'
                        : 'Business is not marked VAT registered'
                ],
                'tax_registration' => [
                    'Tax registration',
                    'Income Tax / Turnover Tax registration recorded'
                ],
                'smart_invoice' => [
                    'Smart Invoice',
                    'Smart Invoice readiness state'
                ],
                'vsdc' => [
                    'VSDC',
                    'VSDC device/connection readiness'
                ],
            ];

            foreach ($checks as $key => $check):
                $done = $readiness[$key];
            ?>
            <div class="readiness-item">
                <div>
                    <strong><?= compliance_h($check[0]) ?></strong>
                    <small><?= compliance_h($check[1]) ?></small>
                </div>
                <span class="state <?= $done ? 'ok' : 'warn' ?>">
                    <?= $done ? 'Complete' : 'Pending' ?>
                </span>
            </div>
            <?php endforeach; ?>

        </div>

        <div style="margin-top:13px">
            <a class="section-link"
               href="compliance.php?view=taxpayer">
                Open taxpayer profile
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>ZRA workflow</h2>
            <p>Phase 1 compliance administration only.</p>
        </div>
        <i class="fas fa-route"></i>
    </div>

    <div class="panel-body">

        <div class="info-box">
            <strong>Important:</strong>
            EchoTech POS is not declaring itself ZRA-certified from this
            screen. This module stores compliance information and readiness
            records. Live ZRA/VSDC submission remains disabled in Phase 1.
        </div>

        <div class="readiness-list" style="margin-top:11px">

            <div class="readiness-item">
                <div>
                    <strong>1. Complete taxpayer profile</strong>
                    <small>TPIN, VAT and tax registration information</small>
                </div>
                <i class="fas fa-check"></i>
            </div>

            <div class="readiness-item">
                <div>
                    <strong>2. Register branches/devices</strong>
                    <small>Branch and VSDC device records</small>
                </div>
                <i class="fas fa-check"></i>
            </div>

            <div class="readiness-item">
                <div>
                    <strong>3. Track tax obligations</strong>
                    <small>Return dates, payment dates and balances</small>
                </div>
                <i class="fas fa-check"></i>
            </div>

            <div class="readiness-item">
                <div>
                    <strong>4. Preserve evidence</strong>
                    <small>Payment references and audit history</small>
                </div>
                <i class="fas fa-check"></i>
            </div>

        </div>
    </div>
</section>

</div>

<div class="grid-2">

<section class="panel">
    <div class="panel-head">
        <div>
            <h3>Taxpayer profile</h3>
            <p><?= compliance_h($business_name) ?></p>
        </div>
        <a class="section-link"
           href="compliance.php?view=taxpayer">
            Manage
        </a>
    </div>

    <div class="panel-body">
        <div class="metric-row">

            <div class="metric">
                <b><?= $tpin !== '' ? compliance_h($tpin) : 'â€”' ?></b>
                <span>TPIN</span>
            </div>

            <div class="metric">
                <b><?= $vat_registered
                    ? ($vat_number !== '' ? compliance_h($vat_number) : 'Missing')
                    : 'Not registered' ?></b>
                <span>VAT</span>
            </div>

            <div class="metric">
                <b><?= compliance_h(
                    ucwords(str_replace('_',' ',$smart_invoice_status))
                ) ?></b>
                <span>Smart Invoice</span>
            </div>

        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h3>Recent compliance activity</h3>
            <p>Latest Phase 1 changes</p>
        </div>
        <a class="section-link"
           href="compliance.php?view=audit">
            View audit
        </a>
    </div>

    <div class="panel-body">

    <?php if (!$auditRows): ?>

        <div class="empty">No compliance activity recorded yet.</div>

    <?php else: ?>

        <?php foreach (array_slice($auditRows, 0, 5) as $audit): ?>

        <div style="
            display:flex;
            justify-content:space-between;
            gap:12px;
            padding:8px 0;
            border-bottom:1px solid #edf0f4;
        ">
            <div>
                <strong style="font-size:10px">
                    <?= compliance_h($audit['action'] ?? '') ?>
                </strong>

                <div class="muted" style="font-size:9px">
                    <?= $audit['user_id']
                        ? 'User #' . (int)$audit['user_id']
                        : 'Administrator' ?>
                </div>
            </div>

            <span class="muted" style="font-size:9px">
                <?= !empty($audit['created_at'])
                    ? compliance_h(
                        date('d M Y H:i', strtotime($audit['created_at']))
                    )
                    : 'â€”' ?>
            </span>
        </div>

        <?php endforeach; ?>

    <?php endif; ?>

    </div>
</section>

</div>

<?php elseif ($view === 'taxpayer'): ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Taxpayer / ZRA registration profile</h2>
            <p>
                Store the official taxpayer and Smart Invoice readiness
                information for this pharmacy.
            </p>
        </div>
    </div>

    <div class="panel-body">

    <form method="post">

        <input type="hidden"
               name="csrf_token"
               value="<?= compliance_h($csrf) ?>">

        <input type="hidden"
               name="action"
               value="save_taxpayer">

        <div class="form-grid">

            <div class="field">
                <label>Business / Taxpayer Name</label>
                <input name="business_name"
                       value="<?= compliance_h($business_name) ?>"
                       required>
            </div>

            <div class="field">
                <label>TPIN</label>
                <input name="tpin"
                       value="<?= compliance_h($tpin) ?>"
                       placeholder="ZRA TPIN">
            </div>

            <div class="field">
                <label>VAT Number</label>
                <input name="vat_number"
                       value="<?= compliance_h($vat_number) ?>"
                       placeholder="VAT registration number">
            </div>

            <div class="field">
                <label>Smart Invoice Status</label>
                <select name="smart_invoice_status">
                <?php
                foreach (
                    ['not_configured','pending','test','connected','suspended']
                    as $status
                ):
                ?>
                    <option value="<?= $status ?>"
                        <?= $smart_invoice_status === $status
                            ? 'selected' : '' ?>>
                        <?= compliance_h(
                            ucwords(str_replace('_',' ',$status))
                        ) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Smart Invoice Environment</label>
                <select name="smart_invoice_environment">
                    <option value="test"
                        <?= $smart_invoice_environment === 'test'
                            ? 'selected' : '' ?>>
                        Test
                    </option>
                    <option value="production"
                        <?= $smart_invoice_environment === 'production'
                            ? 'selected' : '' ?>>
                        Production
                    </option>
                </select>
            </div>

            <div class="field">
                <label>CIS Code</label>
                <input name="cis_code"
                       value="<?= compliance_h($cis_code) ?>">
            </div>

            <div class="field">
                <label>VSDC Serial</label>
                <input name="vsdc_serial"
                       value="<?= compliance_h($vsdc_serial) ?>">
            </div>

            <div class="field">
                <label>VSDC Status</label>
                <select name="vsdc_status">
                <?php
                foreach (
                    ['not_configured','offline','online','error']
                    as $status
                ):
                ?>
                    <option value="<?= $status ?>"
                        <?= $vsdc_status === $status
                            ? 'selected' : '' ?>>
                        <?= compliance_h(
                            ucwords(str_replace('_',' ',$status))
                        ) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>VSDC Endpoint</label>
                <input name="vsdc_endpoint"
                       value="<?= compliance_h($vsdc_endpoint) ?>">
            </div>

            <div class="field">
                <label>Tax Account Reference</label>
                <input name="tax_account_reference"
                       value="<?= compliance_h($tax_account_reference) ?>">
            </div>

            <div class="field full">
                <label>Tax Registrations</label>

                <div class="check-row">

                    <label class="check">
                        <input type="checkbox"
                               name="vat_registered"
                               <?= $vat_registered ? 'checked' : '' ?>>
                        VAT Registered
                    </label>

                    <label class="check">
                        <input type="checkbox"
                               name="income_tax_registered"
                               <?= $income_tax_registered ? 'checked' : '' ?>>
                        Income Tax Registered
                    </label>

                    <label class="check">
                        <input type="checkbox"
                               name="turnover_tax_registered"
                               <?= $turnover_tax_registered ? 'checked' : '' ?>>
                        Turnover Tax Registered
                    </label>

                </div>
            </div>

            <div class="field">
                <label>Compliance Contact Name</label>
                <input name="compliance_contact_name"
                       value="<?= compliance_h($compliance_contact_name) ?>">
            </div>

            <div class="field">
                <label>Compliance Contact Email</label>
                <input type="email"
                       name="compliance_contact_email"
                       value="<?= compliance_h($compliance_contact_email) ?>">
            </div>

            <div class="field">
                <label>Compliance Contact Phone</label>
                <input name="compliance_contact_phone"
                       value="<?= compliance_h($compliance_contact_phone) ?>">
            </div>

            <div class="field full">
                <label>Compliance Notes</label>
                <textarea name="notes"><?= compliance_h($compliance_notes) ?></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button class="btn green" type="submit">
                <i class="fas fa-save"></i>
                Save Compliance Profile
            </button>
        </div>

    </form>

    </div>
</section>

<div class="info-box">
    <strong>Phase 1 boundary:</strong>
    This page records ZRA/tax and Smart Invoice readiness information.
    It does not submit live invoices to ZRA/VSDC.
</div>

<?php elseif ($view === 'smart_invoice'): ?>

<div class="grid-2">

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Tax configuration</h2>
            <p>Current compliance configuration.</p>
        </div>
    </div>

    <div class="panel-body">

        <div class="metric-row">

            <div class="metric">
                <b><?= $vat_registered ? 'Registered' : 'Not registered' ?></b>
                <span>VAT</span>
            </div>

            <div class="metric">
                <b><?= compliance_h(
                    ucwords(str_replace('_',' ',$smart_invoice_status))
                ) ?></b>
                <span>Smart Invoice</span>
            </div>

            <div class="metric">
                <b><?= compliance_h(
                    ucwords(str_replace('_',' ',$vsdc_status))
                ) ?></b>
                <span>VSDC</span>
            </div>

        </div>

        <div class="info-box" style="margin-top:14px">
            Live ZRA/VSDC submission is intentionally disabled in Phase 1.
            The recorded environment is:
            <strong><?= compliance_h($smart_invoice_environment) ?></strong>.
        </div>

    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Smart Invoice readiness</h2>
            <p>Configuration before live integration.</p>
        </div>
    </div>

    <div class="panel-body">

        <div class="readiness-list">

            <div class="readiness-item">
                <div>
                    <strong>Smart Invoice</strong>
                    <small>Current configuration state</small>
                </div>
                <span class="state <?= in_array(
                    $smart_invoice_status,
                    ['test','connected'],
                    true
                ) ? 'ok' : 'warn' ?>">
                    <?= compliance_h(
                        ucwords(str_replace('_',' ',$smart_invoice_status))
                    ) ?>
                </span>
            </div>

            <div class="readiness-item">
                <div>
                    <strong>VSDC</strong>
                    <small>Device/connection state</small>
                </div>
                <span class="state <?= in_array(
                    $vsdc_status,
                    ['online','offline'],
                    true
                ) ? 'ok' : 'warn' ?>">
                    <?= compliance_h(
                        ucwords(str_replace('_',' ',$vsdc_status))
                    ) ?>
                </span>
            </div>

        </div>

        <div style="margin-top:12px">
            <a class="btn primary"
               href="compliance.php?view=taxpayer">
                <i class="fas fa-pen"></i>
                Edit Configuration
            </a>
        </div>

    </div>
</section>

</div>

<?php elseif ($view === 'branches'): ?>

<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Branch / VSDC device register</h2>
            <p>
                Record branch devices and their Phase 1 compliance status.
            </p>
        </div>
    </div>

    <div class="panel-body">

    <form method="post">

        <input type="hidden"
               name="csrf_token"
               value="<?= compliance_h($csrf) ?>">

        <input type="hidden"
               name="action"
               value="save_branch_device">

        <input type="hidden"
               name="record_id"
               value="0">

        <div class="form-grid">

            <div class="field">
                <label>Branch</label>
                <select name="branch_id" required>
                    <option value="">Select branch</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>">
                        <?= compliance_h($branch['branch_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Device Name</label>
                <input name="device_name"
                       placeholder="e.g. Main Till 01"
                       required>
            </div>

            <div class="field">
                <label>VSDC Serial / Reference</label>
                <input name="vsdc_serial">
            </div>

            <div class="field">
                <label>Registration Status</label>
                <select name="registration_status">
                <?php
                foreach (
                    ['pending','submitted','registered','active','offline','error']
                    as $status
                ):
                ?>
                    <option value="<?= $status ?>">
                        <?= compliance_h(ucwords($status)) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="field full">
                <label>Notes</label>
                <textarea name="notes"></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button class="btn green">
                <i class="fas fa-plus"></i>
                Add Device Record
            </button>
        </div>

    </form>

    </div>
</section>

<section class="panel">

    <div class="panel-head">
        <div>
            <h3>Registered branch/device records</h3>
            <p><?= count($branchDevices) ?> record(s)</p>
        </div>
    </div>

    <div class="table-wrap">
    <table class="table">

        <thead>
        <tr>
            <th>Branch</th>
            <th>Device</th>
            <th>VSDC Reference</th>
            <th>Status</th>
            <th>Registered</th>
            <th>Action</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$branchDevices): ?>

            <tr>
                <td colspan="6" class="empty">
                    No branch/device compliance records yet.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($branchDevices as $device): ?>

            <?php
            $status = (string)($device['registration_status'] ?? 'pending');
            $badgeClass = in_array(
                $status,
                ['registered','active'],
                true
            ) ? 'green' : (
                in_array($status,['error'],true)
                    ? 'red'
                    : 'amber'
            );
            ?>

            <tr>

                <td>
                    <strong>
                        <?= compliance_h($device['branch_name'] ?? 'Unknown') ?>
                    </strong>
                </td>

                <td>
                    <?= compliance_h($device['device_name'] ?? '') ?>
                </td>

                <td>
                    <?= ($device['vsdc_serial'] ?? '') !== ''
                        ? compliance_h($device['vsdc_serial'])
                        : '<span class="muted">â€”</span>' ?>
                </td>

                <td>
                    <span class="badge <?= $badgeClass ?>">
                        <?= compliance_h($status) ?>
                    </span>
                </td>

                <td>
                    <?= !empty($device['registered_at'])
                        ? compliance_h(
                            date(
                                'd M Y',
                                strtotime($device['registered_at'])
                            )
                        )
                        : 'â€”' ?>
                </td>

                <td>
                    <form class="inline-form"
                          method="post"
                          onsubmit="return confirm(
                            'Remove this branch/device compliance record?'
                          )">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= compliance_h($csrf) ?>">

                        <input type="hidden"
                               name="action"
                               value="delete_branch_device">

                        <input type="hidden"
                               name="record_id"
                               value="<?= (int)$device['id'] ?>">

                        <button class="danger-link">
                            Delete
                        </button>

                    </form>
                </td>

            </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
    </table>
    </div>
</section>

<?php elseif ($view === 'invoices'): ?>

<section class="panel">

    <div class="panel-head">
        <div>
            <h2>ZRA invoice register</h2>
            <p>
                Phase 1 compliance invoice records. No live submission is
                performed by this screen.
            </p>
        </div>
    </div>

    <div class="table-wrap">
    <table class="table">

        <thead>
        <tr>
            <th>Local Invoice</th>
            <th>ZRA Invoice</th>
            <th>Branch</th>
            <th>Environment</th>
            <th>Gross</th>
            <th>VAT</th>
            <th>Status</th>
            <th>Date</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$zraInvoices): ?>

            <tr>
                <td colspan="8" class="empty">
                    No compliance invoice records found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($zraInvoices as $invoice): ?>

            <?php
            $invoiceStatus = (string)($invoice['status'] ?? 'pending');
            $invoiceBadge = $invoiceStatus === 'accepted'
                ? 'green'
                : (
                    $invoiceStatus === 'rejected'
                        ? 'red'
                        : 'amber'
                );
            ?>

            <tr>

                <td>
                    <?= compliance_h(
                        $invoice['local_invoice_no'] ?? 'â€”'
                    ) ?>
                </td>

                <td>
                    <?= compliance_h(
                        $invoice['zra_invoice_no'] ?? 'â€”'
                    ) ?>
                </td>

                <td>
                    <?= compliance_h(
                        $invoice['branch_name'] ?? 'â€”'
                    ) ?>
                </td>

                <td>
                    <?= compliance_h(
                        $invoice['environment'] ?? 'test'
                    ) ?>
                </td>

                <td>
                    <?= compliance_money(
                        $invoice['gross_amount'] ?? 0
                    ) ?>
                </td>

                <td>
                    <?= compliance_money(
                        $invoice['vat_amount'] ?? 0
                    ) ?>
                </td>

                <td>
                    <span class="badge <?= $invoiceBadge ?>">
                        <?= compliance_h($invoiceStatus) ?>
                    </span>
                </td>

                <td>
                    <?= !empty($invoice['created_at'])
                        ? compliance_h(
                            date(
                                'd M Y H:i',
                                strtotime($invoice['created_at'])
                            )
                        )
                        : 'â€”' ?>
                </td>

            </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
    </table>
    </div>
</section>

<?php elseif ($view === 'obligations'): ?>

<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Tax obligations</h2>
            <p>
                Track tax periods, return deadlines, payment deadlines
                and outstanding balances.
            </p>
        </div>
    </div>

    <div class="panel-body">

    <form method="post">

        <input type="hidden"
               name="csrf_token"
               value="<?= compliance_h($csrf) ?>">

        <input type="hidden"
               name="action"
               value="save_obligation">

        <input type="hidden"
               name="record_id"
               value="0">

        <div class="form-grid">

            <div class="field">
                <label>Tax Type</label>
                <select name="tax_type">
                    <option>VAT</option>
                    <option>PAYE</option>
                    <option>Withholding Tax</option>
                    <option>Income Tax</option>
                    <option>Turnover Tax</option>
                    <option>Other</option>
                </select>
            </div>

            <div class="field">
                <label>Period Year</label>
                <input type="number"
                       name="period_year"
                       min="2000"
                       max="2100"
                       value="<?= date('Y') ?>">
            </div>

            <div class="field">
                <label>Period Month</label>
                <select name="period_month">
                    <option value="">Annual / N/A</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>">
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="field">
                <label>Return Due Date</label>
                <input type="date" name="return_due_date">
            </div>

            <div class="field">
                <label>Payment Due Date</label>
                <input type="date" name="payment_due_date">
            </div>

            <div class="field">
                <label>Amount Due</label>
                <input type="number"
                       step="0.01"
                       min="0"
                       name="amount_due"
                       value="0">
            </div>

            <div class="field">
                <label>Amount Already Paid</label>
                <input type="number"
                       step="0.01"
                       min="0"
                       name="amount_paid"
                       value="0">
            </div>

            <div class="field">
                <label>Return Status</label>
                <select name="return_status">
                    <option value="not_started">Not Started</option>
                    <option value="draft">Draft</option>
                    <option value="filed">Filed</option>
                    <option value="not_applicable">Not Applicable</option>
                </select>
            </div>

            <div class="field">
                <label>Payment Status</label>
                <select name="payment_status">
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                    <option value="paid">Paid</option>
                    <option value="not_applicable">Not Applicable</option>
                </select>
            </div>

            <div class="field">
                <label>Reference Number</label>
                <input name="reference_no">
            </div>

            <div class="field full">
                <label>Notes</label>
                <textarea name="notes"></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button class="btn green">
                <i class="fas fa-plus"></i>
                Add Obligation
            </button>
        </div>

    </form>

    </div>
</section>

<section class="panel">

    <div class="panel-head">
        <div>
            <h3>Obligation register</h3>
            <p><?= count($obligations) ?> obligation(s)</p>
        </div>
    </div>

    <div class="table-wrap">
    <table class="table">

        <thead>
        <tr>
            <th>Tax Type</th>
            <th>Period</th>
            <th>Return Due</th>
            <th>Payment Due</th>
            <th>Amount</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Status</th>
            <th>Reference</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$obligations): ?>

            <tr>
                <td colspan="9" class="empty">
                    No tax obligations recorded.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($obligations as $obligation): ?>

            <?php
            $dueAmount = (float)($obligation['amount_due'] ?? 0);
            $paidAmount = (float)($obligation['amount_paid'] ?? 0);
            $balance = max(0, $dueAmount - $paidAmount);

            $paymentStatus = (string)(
                $obligation['payment_status'] ?? 'unpaid'
            );

            $paymentDue = (string)(
                $obligation['payment_due_date'] ?? ''
            );

            $overdue = $paymentDue !== ''
                && $paymentDue < $today
                && $balance > 0
                && $paymentStatus !== 'not_applicable';

            $badgeClass = $paymentStatus === 'paid'
                ? 'green'
                : ($overdue ? 'red' : 'amber');

            $period = (int)($obligation['period_year'] ?? 0);

            if (!empty($obligation['period_month'])) {
                $period .= ' / ' . date(
                    'M',
                    mktime(
                        0,
                        0,
                        0,
                        (int)$obligation['period_month'],
                        1
                    )
                );
            }
            ?>

            <tr>

                <td>
                    <strong>
                        <?= compliance_h(
                            $obligation['tax_type'] ?? ''
                        ) ?>
                    </strong>
                </td>

                <td><?= compliance_h($period) ?></td>

                <td>
                    <?= !empty($obligation['return_due_date'])
                        ? compliance_h(
                            date(
                                'd M Y',
                                strtotime($obligation['return_due_date'])
                            )
                        )
                        : 'â€”' ?>
                </td>

                <td class="<?= $overdue ? '' : 'muted' ?>">
                    <?= $paymentDue !== ''
                        ? compliance_h(
                            date('d M Y', strtotime($paymentDue))
                        )
                        : 'â€”' ?>
                </td>

                <td><?= compliance_money($dueAmount) ?></td>
                <td><?= compliance_money($paidAmount) ?></td>
                <td><strong><?= compliance_money($balance) ?></strong></td>

                <td>
                    <span class="badge <?= $badgeClass ?>">
                        <?= compliance_h($paymentStatus) ?>
                    </span>
                </td>

                <td>
                    <?= !empty($obligation['reference_no'])
                        ? compliance_h($obligation['reference_no'])
                        : 'â€”' ?>
                </td>

            </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
    </table>
    </div>
</section>

<?php elseif ($view === 'payments'): ?>

<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Tax payments</h2>
            <p>
                Record payments and preserve official payment references.
            </p>
        </div>
    </div>

    <div class="panel-body">

    <form method="post">

        <input type="hidden"
               name="csrf_token"
               value="<?= compliance_h($csrf) ?>">

        <input type="hidden"
               name="action"
               value="save_payment">

        <div class="form-grid">

            <div class="field">
                <label>Tax Type</label>
                <input name="tax_type" value="VAT">
            </div>

            <div class="field">
                <label>Obligation</label>
                <select name="obligation_id">
                    <option value="0">â€” Not linked â€”</option>

                    <?php foreach ($obligations as $obligation): ?>

                    <?php
                    $period = (int)(
                        $obligation['period_year'] ?? 0
                    );

                    if (!empty($obligation['period_month'])) {
                        $period .= ' / ' . date(
                            'M',
                            mktime(
                                0,
                                0,
                                0,
                                (int)$obligation['period_month'],
                                1
                            )
                        );
                    }
                    ?>

                    <option value="<?= (int)$obligation['id'] ?>">
                        <?= compliance_h(
                            ($obligation['tax_type'] ?? '') .
                            ' / ' .
                            $period
                        ) ?>
                        â€” <?= compliance_money(
                            $obligation['amount_due'] ?? 0
                        ) ?>
                    </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label>Payment Date</label>
                <input type="date"
                       name="payment_date"
                       value="<?= date('Y-m-d') ?>">
            </div>

            <div class="field">
                <label>Amount</label>
                <input type="number"
                       step="0.01"
                       min="0"
                       name="amount"
                       value="0">
            </div>

            <div class="field">
                <label>Payment Reference</label>
                <input name="payment_reference"
                       placeholder="ZRA / bank / portal reference">
            </div>

            <div class="field">
                <label>Payment Method</label>
                <input name="method"
                       placeholder="Bank / Portal / Other">
            </div>

            <div class="field full">
                <label>Notes</label>
                <textarea name="notes"></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button class="btn green">
                <i class="fas fa-save"></i>
                Record Payment
            </button>
        </div>

    </form>

    </div>
</section>

<section class="panel">

    <div class="panel-head">
        <div>
            <h3>Payment register</h3>
            <p>
                Total recorded:
                <?= compliance_money($totalPayments) ?>
            </p>
        </div>
    </div>

    <div class="table-wrap">
    <table class="table">

        <thead>
        <tr>
            <th>Tax Type</th>
            <th>Period</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Reference</th>
            <th>Method</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$payments): ?>

            <tr>
                <td colspan="6" class="empty">
                    No tax payments recorded.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($payments as $payment): ?>

            <?php
            $period = '';

            if (!empty($payment['period_year'])) {
                $period = (string)$payment['period_year'];

                if (!empty($payment['period_month'])) {
                    $period .= ' / ' . date(
                        'M',
                        mktime(
                            0,
                            0,
                            0,
                            (int)$payment['period_month'],
                            1
                        )
                    );
                }
            }
            ?>

            <tr>

                <td>
                    <?= compliance_h(
                        $payment['tax_type'] ?? ''
                    ) ?>
                </td>

                <td><?= compliance_h($period ?: 'â€”') ?></td>

                <td>
                    <?= !empty($payment['payment_date'])
                        ? compliance_h(
                            date(
                                'd M Y',
                                strtotime($payment['payment_date'])
                            )
                        )
                        : 'â€”' ?>
                </td>

                <td>
                    <strong>
                        <?= compliance_money($payment['amount'] ?? 0) ?>
                    </strong>
                </td>

                <td>
                    <?= !empty($payment['payment_reference'])
                        ? compliance_h($payment['payment_reference'])
                        : 'â€”' ?>
                </td>

                <td>
                    <?= !empty($payment['method'])
                        ? compliance_h($payment['method'])
                        : 'â€”' ?>
                </td>

            </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
    </table>
    </div>
</section>

<?php elseif ($view === 'audit'): ?>

<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Compliance audit trail</h2>
            <p>
                Administrative changes made through the Phase 1 Compliance
                module.
            </p>
        </div>
    </div>

    <div class="table-wrap">
    <table class="table">

        <thead>
        <tr>
            <th>Date</th>
            <th>User</th>
            <th>Action</th>
            <th>Entity</th>
            <th>Description</th>
            <th>IP</th>
        </tr>
        </thead>

        <tbody>

        <?php if (!$auditRows): ?>

            <tr>
                <td colspan="6" class="empty">
                    No audit records found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($auditRows as $audit): ?>

            <tr>

                <td>
                    <?= !empty($audit['created_at'])
                        ? compliance_h(
                            date(
                                'd M Y H:i:s',
                                strtotime($audit['created_at'])
                            )
                        )
                        : 'â€”' ?>
                </td>

                <td>
                    <?= $audit['user_id']
                        ? 'User #' . (int)$audit['user_id']
                        : 'Administrator' ?>
                </td>

                <td>
                    <strong>
                        <?= compliance_h($audit['action'] ?? '') ?>
                    </strong>
                </td>

                <td>
                    <?= compliance_h(
                        $audit['entity_type'] ?? 'compliance'
                    ) ?>

                    <?php if (!empty($audit['entity_id'])): ?>
                        #<?= (int)$audit['entity_id'] ?>
                    <?php endif; ?>
                </td>

                <td>
                    <?= !empty($audit['description'])
                        ? compliance_h($audit['description'])
                        : 'â€”' ?>
                </td>

                <td>
                    <?= !empty($audit['ip_address'])
                        ? compliance_h($audit['ip_address'])
                        : 'â€”' ?>
                </td>

            </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>
    </table>
    </div>
</section>

<?php endif; ?>

<div class="mini-note">
    EchoTech POS Compliance Phase 1 â€¢ Administrative compliance layer only â€¢
    Live ZRA/VSDC submission is intentionally disabled.
</div>

</section>
</main>
</div>
</body>
</html>
