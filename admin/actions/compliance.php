<?php
/**
 * ============================================================
 * EchoTech POS - ADMIN COMPLIANCE
 * ============================================================
 * Location: /admin/actions/compliance.php
 * Phase 1 - ZRA / Smart Invoice compliance administration.
 *
 * This module deliberately does NOT send live invoices to ZRA.
 * It provides the compliance record, readiness checks, branch/device
 * register, obligations, tax-payment register and audit trail.
 *
 * The code is defensive against differences in the existing POS schema:
 * it detects optional columns/tables before querying them and uses a
 * dedicated compliance_* record set for Phase 1 administration.
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Africa/Lusaka');

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------------
 | Connection
 * ------------------------------------------------------------ */
$connectionCandidates = [
    __DIR__ . '/../../includes/conn.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../includes/db.php',
];
foreach ($connectionCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
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
foreach ($authCandidates as $authFile) {
    if (is_file($authFile)) {
        require_once $authFile;
        break;
    }
}
if (function_exists('require_login')) {
    require_login();
}
if (function_exists('require_admin')) {
    require_admin();
}

/* ------------------------------------------------------------
 | Tenant context
 * ------------------------------------------------------------ */
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? $_SESSION['pharmacyId'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? $_SESSION['current_branch_id'] ?? 0);
$user_role   = (string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? $_SESSION['userRole'] ?? 'Admin');

if ($pharmacy_id <= 0) {
    // Resolve pharmacy from the selected branch when the session only has branch_id.
    if ($branch_id > 0) {
        $s = $conn->prepare('SELECT pharmacy_id FROM branches WHERE id=? LIMIT 1');
        if ($s) {
            $s->bind_param('i', $branch_id);
            $s->execute();
            $r = $s->get_result()->fetch_assoc();
            $s->close();
            $pharmacy_id = (int)($r['pharmacy_id'] ?? 0);
            if ($pharmacy_id > 0) {
                $_SESSION['pharmacy_id'] = $pharmacy_id;
            }
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

function compliance_money(float $value): string
{
    return 'K ' . number_format($value, 2);
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
    if (isset($cache[$table])) return $cache[$table];
    $out = [];
    if (!compliance_table_exists($db, $table)) return $out;
    $safe = str_replace('`', '``', $table);
    $r = @$db->query("SHOW COLUMNS FROM `{$safe}`");
    if ($r instanceof mysqli_result) {
        while ($row = $r->fetch_assoc()) {
            $out[] = (string)$row['Field'];
        }
    }
    return $cache[$table] = $out;
}

function compliance_has_column(mysqli $db, string $table, string $column): bool
{
    return in_array($column, compliance_columns($db, $table), true);
}

function compliance_exec(mysqli $db, string $sql, string $types = '', array $params = []): bool
{
    $stmt = @$db->prepare($sql);
    if (!$stmt) return false;
    if ($types !== '') {
        @$stmt->bind_param($types, ...$params);
    }
    $ok = @$stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function compliance_rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = @$db->prepare($sql);
    if (!$stmt) return [];
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
        while ($row = $result->fetch_assoc()) $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function compliance_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $rows = compliance_rows($db, $sql, $types, $params);
    return $rows[0] ?? null;
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
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['compliance_csrf'] ?? '');
    if ($stored === '' || !hash_equals($stored, $posted)) {
        http_response_code(419);
        exit('Security token expired. Please reload the Compliance page and try again.');
    }
}

function compliance_actor(): string
{
    return (string)(
        $_SESSION['full_name']
        ?? $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? 'Administrator'
    );
}

function compliance_redirect(string $view = 'overview', string $message = '', string $type = 'success'): never
{
    $url = 'compliance.php?view=' . rawurlencode($view);
    if ($message !== '') {
        $url .= '&notice=' . rawurlencode($message) . '&notice_type=' . rawurlencode($type);
    }
    header('Location: ' . $url);
    exit;
}

/* ------------------------------------------------------------
 | Create Phase 1 compliance tables
 * ------------------------------------------------------------ */
@$conn->query("CREATE TABLE IF NOT EXISTS compliance_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    taxpayer_name VARCHAR(255) NOT NULL DEFAULT '',
    tpin VARCHAR(80) NOT NULL DEFAULT '',
    vat_number VARCHAR(80) NOT NULL DEFAULT '',
    pacra_number VARCHAR(80) NOT NULL DEFAULT '',
    tax_registration_status VARCHAR(40) NOT NULL DEFAULT 'not_registered',
    smart_invoice_status VARCHAR(40) NOT NULL DEFAULT 'not_configured',
    vsdc_serial VARCHAR(120) NOT NULL DEFAULT '',
    vsdc_registered_at DATETIME NULL,
    default_tax_type VARCHAR(100) NOT NULL DEFAULT 'VAT',
    notes TEXT NULL,
    created_by VARCHAR(150) NULL,
    updated_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_compliance_settings_pharmacy (pharmacy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@$conn->query("CREATE TABLE IF NOT EXISTS compliance_branch_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    device_name VARCHAR(150) NOT NULL DEFAULT '',
    vsdc_serial VARCHAR(120) NOT NULL DEFAULT '',
    registration_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    registered_at DATETIME NULL,
    notes TEXT NULL,
    created_by VARCHAR(150) NULL,
    updated_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_compliance_branch_devices_pharmacy (pharmacy_id),
    KEY idx_compliance_branch_devices_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@$conn->query("CREATE TABLE IF NOT EXISTS compliance_obligations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    obligation_type VARCHAR(100) NOT NULL,
    period_label VARCHAR(50) NOT NULL,
    due_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    reference VARCHAR(150) NOT NULL DEFAULT '',
    notes TEXT NULL,
    created_by VARCHAR(150) NULL,
    updated_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_compliance_obligations_pharmacy (pharmacy_id),
    KEY idx_compliance_obligations_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@$conn->query("CREATE TABLE IF NOT EXISTS compliance_tax_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    obligation_id INT UNSIGNED NULL,
    payment_type VARCHAR(100) NOT NULL,
    payment_date DATE NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_reference VARCHAR(150) NOT NULL DEFAULT '',
    status VARCHAR(30) NOT NULL DEFAULT 'recorded',
    notes TEXT NULL,
    created_by VARCHAR(150) NULL,
    updated_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_compliance_tax_payments_pharmacy (pharmacy_id),
    KEY idx_compliance_tax_payments_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@$conn->query("CREATE TABLE IF NOT EXISTS compliance_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL DEFAULT 'compliance',
    entity_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    actor VARCHAR(150) NOT NULL DEFAULT 'Administrator',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_compliance_audit_pharmacy (pharmacy_id),
    KEY idx_compliance_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function compliance_audit(mysqli $db, int $pharmacyId, string $action, string $entityType = 'compliance', ?int $entityId = null, string $details = ''): void
{
    $actor = compliance_actor();
    compliance_exec(
        $db,
        'INSERT INTO compliance_audit_log (pharmacy_id, action, entity_type, entity_id, details, actor) VALUES (?,?,?,?,?,?)',
        'isisss',
        [$pharmacyId, $action, $entityType, $entityId, $details, $actor]
    );
}

/* ------------------------------------------------------------
 | Pharmacy information
 * ------------------------------------------------------------ */
$pharmacy = compliance_one($conn, 'SELECT * FROM pharmacies WHERE id=? LIMIT 1', 'i', [$pharmacy_id]) ?? [];
$pharmacy_name = (string)($pharmacy['name'] ?? $pharmacy['pharmacy_name'] ?? 'PHARMACY POS');

/* ------------------------------------------------------------
 | POST actions
 * ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    compliance_check_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_taxpayer') {
        $taxpayerName = trim((string)($_POST['taxpayer_name'] ?? ''));
        $tpin = trim((string)($_POST['tpin'] ?? ''));
        $vat = trim((string)($_POST['vat_number'] ?? ''));
        $pacra = trim((string)($_POST['pacra_number'] ?? ''));
        $taxStatus = trim((string)($_POST['tax_registration_status'] ?? 'not_registered'));
        $smartStatus = trim((string)($_POST['smart_invoice_status'] ?? 'not_configured'));
        $vsdc = trim((string)($_POST['vsdc_serial'] ?? ''));
        $taxType = trim((string)($_POST['default_tax_type'] ?? 'VAT'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $existing = compliance_one($conn, 'SELECT id FROM compliance_settings WHERE pharmacy_id=? LIMIT 1', 'i', [$pharmacy_id]);
        if ($existing) {
            compliance_exec($conn, 'UPDATE compliance_settings SET taxpayer_name=?,tpin=?,vat_number=?,pacra_number=?,tax_registration_status=?,smart_invoice_status=?,vsdc_serial=?,default_tax_type=?,notes=?,updated_by=? WHERE pharmacy_id=?', 'ssssssssssi', [$taxpayerName,$tpin,$vat,$pacra,$taxStatus,$smartStatus,$vsdc,$taxType,$notes,compliance_actor(),$pharmacy_id]);
            $id = (int)$existing['id'];
        } else {
            compliance_exec($conn, 'INSERT INTO compliance_settings (pharmacy_id,taxpayer_name,tpin,vat_number,pacra_number,tax_registration_status,smart_invoice_status,vsdc_serial,default_tax_type,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', 'isssssssssss', [$pharmacy_id,$taxpayerName,$tpin,$vat,$pacra,$taxStatus,$smartStatus,$vsdc,$taxType,$notes,compliance_actor(),compliance_actor()]);
            $id = (int)$conn->insert_id;
        }
        compliance_audit($conn, $pharmacy_id, 'Updated taxpayer compliance profile', 'compliance_settings', $id);
        compliance_redirect('taxpayer', 'Taxpayer compliance profile saved.');
    }

    if ($action === 'save_branch_device') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $recordBranch = (int)($_POST['branch_id'] ?? 0);
        $device = trim((string)($_POST['device_name'] ?? ''));
        $serial = trim((string)($_POST['vsdc_serial'] ?? ''));
        $status = trim((string)($_POST['registration_status'] ?? 'pending'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $validBranch = compliance_one($conn, 'SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1', 'ii', [$recordBranch,$pharmacy_id]);
        if (!$validBranch) compliance_redirect('branches', 'The selected branch is not part of this pharmacy.', 'error');

        $registeredAt = $status === 'registered' ? date('Y-m-d H:i:s') : null;
        if ($recordId > 0) {
            compliance_exec($conn, 'UPDATE compliance_branch_devices SET branch_id=?,device_name=?,vsdc_serial=?,registration_status=?,registered_at=?,notes=?,updated_by=? WHERE id=? AND pharmacy_id=?', 'isssssiii', [$recordBranch,$device,$serial,$status,$registeredAt,$notes,compliance_actor(),$recordId,$pharmacy_id]);
        } else {
            compliance_exec($conn, 'INSERT INTO compliance_branch_devices (pharmacy_id,branch_id,device_name,vsdc_serial,registration_status,registered_at,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)', 'iisssssss', [$pharmacy_id,$recordBranch,$device,$serial,$status,$registeredAt,$notes,compliance_actor(),compliance_actor()]);
            $recordId = (int)$conn->insert_id;
        }
        compliance_audit($conn, $pharmacy_id, 'Updated branch/device compliance record', 'compliance_branch_devices', $recordId);
        compliance_redirect('branches', 'Branch/device compliance record saved.');
    }

    if ($action === 'delete_branch_device') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        compliance_exec($conn, 'DELETE FROM compliance_branch_devices WHERE id=? AND pharmacy_id=?', 'ii', [$recordId,$pharmacy_id]);
        compliance_audit($conn, $pharmacy_id, 'Deleted branch/device compliance record', 'compliance_branch_devices', $recordId);
        compliance_redirect('branches', 'Branch/device record removed.');
    }

    if ($action === 'save_obligation') {
        $id = (int)($_POST['record_id'] ?? 0);
        $type = trim((string)($_POST['obligation_type'] ?? 'VAT'));
        $period = trim((string)($_POST['period_label'] ?? date('F Y')));
        $due = trim((string)($_POST['due_date'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $dueValue = $due !== '' ? $due : null;
        if ($id > 0) {
            compliance_exec($conn, 'UPDATE compliance_obligations SET obligation_type=?,period_label=?,due_date=?,status=?,amount=?,reference=?,notes=?,updated_by=? WHERE id=? AND pharmacy_id=?', 'sssssdssii', [$type,$period,$dueValue,$status,$amount,$reference,$notes,compliance_actor(),$id,$pharmacy_id]);
        } else {
            compliance_exec($conn, 'INSERT INTO compliance_obligations (pharmacy_id,obligation_type,period_label,due_date,status,amount,reference,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)', 'issssdssss', [$pharmacy_id,$type,$period,$dueValue,$status,$amount,$reference,$notes,compliance_actor(),compliance_actor()]);
            $id = (int)$conn->insert_id;
        }
        compliance_audit($conn, $pharmacy_id, 'Updated tax obligation', 'compliance_obligations', $id);
        compliance_redirect('obligations', 'Tax obligation saved.');
    }

    if ($action === 'save_payment') {
        $id = (int)($_POST['record_id'] ?? 0);
        $obligationId = (int)($_POST['obligation_id'] ?? 0);
        $type = trim((string)($_POST['payment_type'] ?? 'VAT'));
        $date = trim((string)($_POST['payment_date'] ?? ''));
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'recorded'));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $dateValue = $date !== '' ? $date : null;
        $obligationValue = $obligationId > 0 ? $obligationId : null;
        if ($id > 0) {
            compliance_exec($conn, 'UPDATE compliance_tax_payments SET obligation_id=?,payment_type=?,payment_date=?,amount=?,payment_reference=?,status=?,notes=?,updated_by=? WHERE id=? AND pharmacy_id=?', 'issdssssii', [$obligationValue,$type,$dateValue,$amount,$reference,$status,$notes,compliance_actor(),$id,$pharmacy_id]);
        } else {
            compliance_exec($conn, 'INSERT INTO compliance_tax_payments (pharmacy_id,obligation_id,payment_type,payment_date,amount,payment_reference,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)', 'iissdsssss', [$pharmacy_id,$obligationValue,$type,$dateValue,$amount,$reference,$status,$notes,compliance_actor(),compliance_actor()]);
            $id = (int)$conn->insert_id;
        }
        compliance_audit($conn, $pharmacy_id, 'Recorded tax payment', 'compliance_tax_payments', $id);
        compliance_redirect('payments', 'Tax payment record saved.');
    }
}

/* ------------------------------------------------------------
 | View + notice
 * ------------------------------------------------------------ */
$view = (string)($_GET['view'] ?? 'overview');
$allowedViews = ['overview','taxpayer','smart_invoice','branches','invoices','obligations','payments','audit'];
if (!in_array($view, $allowedViews, true)) $view = 'overview';

$notice = (string)($_GET['notice'] ?? '');
$noticeType = (string)($_GET['notice_type'] ?? 'success');

/* ------------------------------------------------------------
 | Compliance profile
 * ------------------------------------------------------------ */
$settings = compliance_one($conn, 'SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1', 'i', [$pharmacy_id]) ?? [];

// IMPORTANT: always define these variables. This fixes the undefined
// array-key warning previously appearing on the Compliance page.
$taxpayer_name = (string)($settings['taxpayer_name'] ?? $pharmacy_name);
$tpin = (string)($settings['tpin'] ?? '');
$vat_number = (string)($settings['vat_number'] ?? '');
$pacra_number = (string)($settings['pacra_number'] ?? '');
$tax_registration_status = (string)($settings['tax_registration_status'] ?? 'not_registered');
$smart_invoice_status = (string)($settings['smart_invoice_status'] ?? 'not_configured');
$vsdc_serial = (string)($settings['vsdc_serial'] ?? '');
$default_tax_type = (string)($settings['default_tax_type'] ?? 'VAT');
$compliance_notes = (string)($settings['notes'] ?? '');

/* ------------------------------------------------------------
 | Readiness
 * ------------------------------------------------------------ */
$readiness = [
    'tpin' => $tpin !== '',
    'vat' => $vat_number !== '',
    'tax_registration' => in_array($tax_registration_status, ['registered','active','valid'], true),
    'smart_invoice' => in_array($smart_invoice_status, ['configured','registered','active'], true),
];
$readinessCompleted = count(array_filter($readiness));
$readinessTotal = count($readiness);
$readinessPercent = (int)round(($readinessCompleted / max(1,$readinessTotal)) * 100);

/* ------------------------------------------------------------
 | Branches and device records
 * ------------------------------------------------------------ */
$branches = compliance_rows($conn, 'SELECT id, branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name ASC', 'i', [$pharmacy_id]);
$branchDevices = compliance_rows($conn, 'SELECT d.*, b.branch_name FROM compliance_branch_devices d LEFT JOIN branches b ON b.id=d.branch_id AND b.pharmacy_id=d.pharmacy_id WHERE d.pharmacy_id=? ORDER BY b.branch_name ASC, d.device_name ASC, d.id DESC', 'i', [$pharmacy_id]);

/* ------------------------------------------------------------
 | Obligations / payments
 * ------------------------------------------------------------ */
$obligations = compliance_rows($conn, 'SELECT * FROM compliance_obligations WHERE pharmacy_id=? ORDER BY due_date IS NULL, due_date ASC, id DESC', 'i', [$pharmacy_id]);
$payments = compliance_rows($conn, 'SELECT p.*, o.obligation_type, o.period_label FROM compliance_tax_payments p LEFT JOIN compliance_obligations o ON o.id=p.obligation_id AND o.pharmacy_id=p.pharmacy_id WHERE p.pharmacy_id=? ORDER BY p.payment_date DESC, p.id DESC', 'i', [$pharmacy_id]);

$totalOutstanding = 0.0;
$overdueCount = 0;
$today = date('Y-m-d');
foreach ($obligations as $o) {
    $status = strtolower((string)$o['status']);
    $amount = (float)$o['amount'];
    if (!in_array($status, ['paid','settled','complete'], true)) $totalOutstanding += $amount;
    if (!empty($o['due_date']) && $o['due_date'] < $today && !in_array($status, ['paid','settled','complete'], true)) $overdueCount++;
}

$totalPayments = 0.0;
foreach ($payments as $p) $totalPayments += (float)$p['amount'];

/* ------------------------------------------------------------
 | ZRA invoice/readiness view
 | No live submission. Pull only existing sales data when the
 | current production schema contains the expected fields.
 * ------------------------------------------------------------ */
$zraInvoices = [];
if (compliance_table_exists($conn, 'sales')) {
    $salesCols = compliance_columns($conn, 'sales');
    $select = ['s.id'];
    foreach (['invoice','created_at','total','subtotal','vat_amount','payment_method','pharmacy_id','branch_id'] as $c) {
        if (in_array($c, $salesCols, true)) $select[] = 's.`' . $c . '`';
    }
    if (in_array('invoice', $salesCols, true) || in_array('total', $salesCols, true)) {
        $select[] = "CASE WHEN " . (in_array('invoice',$salesCols,true) ? "COALESCE(s.invoice,'')" : "''") . " <> '' THEN 'Invoice created' ELSE 'Pending compliance reference' END AS compliance_status";
        $where = 's.pharmacy_id=?';
        $types = 'i';
        $params = [$pharmacy_id];
        if (in_array('branch_id', $salesCols, true) && $branch_id > 0) {
            $where .= ' AND s.branch_id=?';
            $types .= 'i';
            $params[] = $branch_id;
        }
        $zraInvoices = compliance_rows($conn, 'SELECT ' . implode(',', $select) . ' FROM sales s WHERE ' . $where . ' ORDER BY ' . (in_array('created_at',$salesCols,true) ? 's.created_at' : 's.id') . ' DESC LIMIT 100', $types, $params);
    }
}

/* ------------------------------------------------------------
 | Audit
 * ------------------------------------------------------------ */
$auditRows = compliance_rows($conn, 'SELECT * FROM compliance_audit_log WHERE pharmacy_id=? ORDER BY created_at DESC, id DESC LIMIT 150', 'i', [$pharmacy_id]);

/* ------------------------------------------------------------
 | Admin header / aside
 * ------------------------------------------------------------ */
$current_admin_page = 'compliance.php';
$user_display_name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['sessionUsername'] ?? 'Administrator');
$total_orders = 0;
$branch_count = count($branches);
if (compliance_table_exists($conn, 'sales')) {
    $salesCols = compliance_columns($conn, 'sales');
    $q = 'SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?';
    if (in_array('branch_id',$salesCols,true) && $branch_id > 0) $q .= ' AND branch_id=' . $branch_id;
    $row = compliance_one($conn, $q, 'i', [$pharmacy_id]);
    $total_orders = (int)($row['c'] ?? 0);
}

$csrf = compliance_csrf();

if (is_file(__DIR__ . '/admin_header.php')) {
    // The existing header expects the variables above.
    // It is loaded below after the page CSS begins.
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compliance | <?= compliance_h($pharmacy_name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--ink:#26313d;--muted:#718096;--line:#e4e9ef;--bg:#f4f7fa;--panel:#fff;--blue:#2563eb;--blue-soft:#eef4ff;--green:#138a57;--green-soft:#ecf9f2;--amber:#b7791f;--amber-soft:#fff7e6;--red:#c0392b;--red-soft:#fff1f0;--shadow:0 8px 24px rgba(31,45,61,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:var(--ink);font-size:13px}.main{min-height:100vh;margin-left:250px}.compliance-content{padding:22px 28px 40px;max-width:1500px;margin:0 auto}.page-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:800;color:#748094}.page-head h1{margin:4px 0 5px;font-size:25px;line-height:1.15}.page-head p{margin:0;color:var(--muted);font-size:12px}.page-actions{display:flex;gap:8px}.btn{border:1px solid #d7dee7;background:#fff;color:#354052;border-radius:8px;padding:9px 13px;font-weight:750;font-size:11px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}.btn:hover{background:#f8fafc}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.green{background:var(--green);border-color:var(--green);color:#fff}.btn.danger{color:var(--red);border-color:#f1c4bf}.notice{border:1px solid #cfe0ff;background:#f3f7ff;color:#31537e;padding:11px 13px;border-radius:9px;margin-bottom:15px}.notice.error{border-color:#f0c4bf;background:var(--red-soft);color:#96382f}.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:15px}.summary-card{background:#fff;border:1px solid var(--line);border-radius:11px;padding:15px;box-shadow:var(--shadow)}.summary-top{display:flex;justify-content:space-between;align-items:center;color:#748094;font-size:10px;font-weight:800;text-transform:uppercase}.summary-icon{width:31px;height:31px;border-radius:8px;background:#f0f4f8;display:grid;place-items:center;color:#58677a}.summary-value{font-size:25px;font-weight:850;margin-top:8px}.summary-sub{font-size:10px;color:#8a95a3;margin-top:4px}.tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:5px;overflow-x:auto;margin-bottom:15px;white-space:nowrap}.tabs a{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:8px;color:#5e6b7c;text-decoration:none;font-weight:750;font-size:11px}.tabs a.active{background:var(--blue-soft);color:var(--blue)}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.panel{background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:14px}.panel-head{padding:15px 17px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:15px;align-items:flex-start}.panel-head h2,.panel-head h3{font-size:15px;margin:0 0 4px}.panel-head p{margin:0;color:var(--muted);font-size:11px}.panel-body{padding:16px}.info-box{border:1px solid #d7e3f5;background:#f5f8fd;border-radius:9px;padding:12px;color:#52677f;font-size:11px;line-height:1.55}.readiness-list{display:grid;gap:9px}.readiness-item{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;border:1px solid var(--line);border-radius:9px;padding:12px}.readiness-item strong{font-size:12px}.readiness-item small{display:block;color:var(--muted);margin-top:3px;font-size:10px}.state{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;font-size:9px;font-weight:850;text-transform:uppercase}.state.ok{background:var(--green-soft);color:var(--green)}.state.warn{background:var(--amber-soft);color:var(--amber)}.state.bad{background:var(--red-soft);color:var(--red)}.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.field.full{grid-column:1/-1}.field label{display:block;font-size:10px;font-weight:800;color:#566273;margin-bottom:5px}.field input,.field select,.field textarea{width:100%;border:1px solid #d8e0e8;border-radius:8px;padding:9px 10px;font:inherit;font-size:11px;background:#fff;color:#26313d}.field textarea{min-height:90px;resize:vertical}.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:13px}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:720px}.table th{background:#f8fafc;color:#718096;text-transform:uppercase;letter-spacing:.04em;font-size:9px;text-align:left;padding:10px 12px;border-bottom:1px solid var(--line)}.table td{padding:10px 12px;border-bottom:1px solid #edf0f4;font-size:10px;vertical-align:top}.table tr:last-child td{border-bottom:0}.muted{color:var(--muted)}.empty{padding:35px;text-align:center;color:#8b96a4}.progress{height:7px;background:#edf1f5;border-radius:99px;overflow:hidden;margin-top:8px}.progress span{display:block;height:100%;background:var(--blue);border-radius:99px}.metric-row{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:13px}.metric{border:1px solid var(--line);border-radius:9px;padding:10px}.metric b{display:block;font-size:16px}.metric span{font-size:9px;color:var(--muted)}.section-link{font-size:10px;font-weight:800;color:var(--blue);text-decoration:none}.badge{display:inline-block;padding:4px 7px;border-radius:999px;background:#f0f3f6;font-size:9px;font-weight:800}.badge.green{background:var(--green-soft);color:var(--green)}.badge.amber{background:var(--amber-soft);color:var(--amber)}.badge.red{background:var(--red-soft);color:var(--red)}.inline-form{display:inline}.danger-link{background:none;border:0;color:var(--red);cursor:pointer;font:inherit;font-size:10px;font-weight:750}.two-col-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mini-note{font-size:9px;color:#8a95a3;margin-top:5px}@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}.main{margin-left:250px}}@media(max-width:900px){.main{margin-left:0}.compliance-content{padding:18px 15px 30px}.page-head{flex-direction:column}.page-actions{width:100%}.page-actions .btn{flex:1;justify-content:center}}@media(max-width:560px){.summary-grid{grid-template-columns:1fr}.form-grid,.two-col-form{grid-template-columns:1fr}.metric-row{grid-template-columns:1fr}.field.full{grid-column:auto}.page-head h1{font-size:22px}}
</style>
</head>
<body>
<div class="app">
<?php
if (is_file(__DIR__ . '/admin_aside.php')) require __DIR__ . '/admin_aside.php';
?>
<main class="main">
<?php
if (is_file(__DIR__ . '/admin_header.php')) require __DIR__ . '/admin_header.php';
?>
<section class="compliance-content">
    <div class="page-head">
        <div>
            <div class="eyebrow">Administration / Regulatory</div>
            <h1>Compliance</h1>
            <p>Manage taxpayer registration, Smart Invoice readiness, branch devices, obligations and tax-payment records for <?= compliance_h($pharmacy_name) ?>.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="compliance.php?view=overview"><i class="fas fa-arrows-rotate"></i>Refresh</a>
            <button class="btn" type="button" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="notice <?= $noticeType === 'error' ? 'error' : '' ?>">
            <i class="fas <?= $noticeType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
            <?= compliance_h($notice) ?>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-top"><span>Readiness</span><span class="summary-icon"><i class="fas fa-shield-halved"></i></span></div><div class="summary-value"><?= $readinessPercent ?>%</div><div class="progress"><span style="width:<?= $readinessPercent ?>%"></span></div><div class="summary-sub"><?= $readinessCompleted ?> of <?= $readinessTotal ?> readiness checks complete</div></div>
        <div class="summary-card"><div class="summary-top"><span>Branches</span><span class="summary-icon"><i class="fas fa-store"></i></span></div><div class="summary-value"><?= number_format($branch_count) ?></div><div class="summary-sub">Active pharmacy branches</div></div>
        <div class="summary-card"><div class="summary-top"><span>Outstanding</span><span class="summary-icon"><i class="fas fa-file-invoice-dollar"></i></span></div><div class="summary-value" style="font-size:21px"><?= compliance_money($totalOutstanding) ?></div><div class="summary-sub">Unpaid compliance obligations</div></div>
        <div class="summary-card"><div class="summary-top"><span>Overdue</span><span class="summary-icon"><i class="fas fa-calendar-xmark"></i></span></div><div class="summary-value"><?= $overdueCount ?></div><div class="summary-sub">Past payment due date</div></div>
    </div>

    <nav class="tabs" aria-label="Compliance navigation">
        <?php
        $tabs = [
            'overview'=>['Overview','fa-gauge-high'],
            'taxpayer'=>['ZRA / Smart Invoice','fa-building-columns'],
            'smart_invoice'=>['Tax Configuration','fa-percent'],
            'branches'=>['Branches','fa-code-branch'],
            'invoices'=>['ZRA Invoices','fa-file-invoice'],
            'obligations'=>['Obligations','fa-calendar-check'],
            'payments'=>['Tax Payments','fa-money-bill-transfer'],
            'audit'=>['Audit','fa-shield-halved'],
        ];
        foreach ($tabs as $key=>$tab):
        ?>
            <a class="<?= $view === $key ? 'active' : '' ?>" href="compliance.php?view=<?= $key ?>"><i class="fas <?= $tab[1] ?>"></i><?= compliance_h($tab[0]) ?></a>
        <?php endforeach; ?>
    </nav>

<?php if ($view === 'overview'): ?>
    <div class="grid-2">
        <section class="panel">
            <div class="panel-head"><div><h2>Compliance readiness</h2><p>Phase 1 records that must be completed before live Smart Invoice integration.</p></div><strong><?= $readinessPercent ?>%</strong></div>
            <div class="panel-body">
                <div class="readiness-list">
                    <div class="readiness-item"><div><strong>Taxpayer TPIN</strong><small>ZRA taxpayer identification number</small></div><span class="state <?= $readiness['tpin'] ? 'ok':'bad' ?>"><?= $readiness['tpin'] ? 'Complete':'Missing' ?></span></div>
                    <div class="readiness-item"><div><strong>VAT registration</strong><small>VAT number / registration record</small></div><span class="state <?= $readiness['vat'] ? 'ok':'warn' ?>"><?= $readiness['vat'] ? 'Registered':'Not marked registered' ?></span></div>
                    <div class="readiness-item"><div><strong>Tax registration status</strong><small>Taxpayer registration must be active/valid</small></div><span class="state <?= $readiness['tax_registration'] ? 'ok':'warn' ?>"><?= compliance_h(ucwords(str_replace('_',' ',$tax_registration_status))) ?></span></div>
                    <div class="readiness-item"><div><strong>Smart Invoice configuration</strong><small>VSDC/device information for Phase 1 readiness</small></div><span class="state <?= $readiness['smart_invoice'] ? 'ok':'warn' ?>"><?= compliance_h(ucwords(str_replace('_',' ',$smart_invoice_status))) ?></span></div>
                </div>
                <div style="margin-top:13px"><a class="section-link" href="compliance.php?view=taxpayer">Open taxpayer profile <i class="fas fa-arrow-right"></i></a></div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div><h2>ZRA workflow</h2><p>Phase 1 prepares the compliance layer without sending live data.</p></div><i class="fas fa-route"></i></div>
            <div class="panel-body">
                <div class="info-box"><strong>Important:</strong> EchoTech POS does not declare itself ZRA-certified from this screen. The live Smart Invoice/VSDC integration should only be enabled after the applicable ZRA registration and certification requirements have been completed.</div>
                <div class="readiness-list" style="margin-top:11px">
                    <div class="readiness-item"><div><strong>1. Complete taxpayer profile</strong><small>TPIN, VAT and tax registrations</small></div><i class="fas fa-check"></i></div>
                    <div class="readiness-item"><div><strong>2. Register each branch/device</strong><small>Branch Smart Invoice and VSDC information</small></div><i class="fas fa-check"></i></div>
                    <div class="readiness-item"><div><strong>3. Track obligations</strong><small>VAT, PAYE and other tax periods</small></div><i class="fas fa-check"></i></div>
                    <div class="readiness-item"><div><strong>4. Maintain evidence</strong><small>Payment references and compliance audit history</small></div><i class="fas fa-check"></i></div>
                </div>
            </div>
        </section>
    </div>
    <div class="grid-2">
        <section class="panel"><div class="panel-head"><div><h3>Taxpayer profile</h3><p><?= compliance_h($taxpayer_name) ?></p></div><a class="section-link" href="compliance.php?view=taxpayer">Manage</a></div><div class="panel-body"><div class="metric-row"><div class="metric"><b><?= $tpin !== '' ? compliance_h($tpin) : 'â€”' ?></b><span>TPIN</span></div><div class="metric"><b><?= $vat_number !== '' ? compliance_h($vat_number) : 'â€”' ?></b><span>VAT Number</span></div><div class="metric"><b><?= compliance_h(ucwords(str_replace('_',' ',$smart_invoice_status))) ?></b><span>Smart Invoice</span></div></div></div></section>
        <section class="panel"><div class="panel-head"><div><h3>Recent compliance activity</h3><p>Latest Phase 1 changes</p></div><a class="section-link" href="compliance.php?view=audit">View audit</a></div><div class="panel-body">
            <?php if (!$auditRows): ?><div class="empty">No compliance activity recorded yet.</div><?php else: ?>
                <?php foreach (array_slice($auditRows,0,5) as $a): ?><div style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #edf0f4"><div><strong style="font-size:10px"><?= compliance_h($a['action']) ?></strong><div class="muted" style="font-size:9px"><?= compliance_h($a['actor']) ?></div></div><span class="muted" style="font-size:9px"><?= compliance_h(date('d M Y H:i',strtotime($a['created_at']))) ?></span></div><?php endforeach; ?>
            <?php endif; ?>
        </div></section>
    </div>

<?php elseif ($view === 'taxpayer'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Taxpayer / ZRA registration profile</h2><p>Store the official registration information used for compliance readiness.</p></div></div><div class="panel-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= compliance_h($csrf) ?>"><input type="hidden" name="action" value="save_taxpayer">
            <div class="form-grid">
                <div class="field"><label>Taxpayer Name</label><input name="taxpayer_name" value="<?= compliance_h($taxpayer_name) ?>" required></div>
                <div class="field"><label>TPIN</label><input name="tpin" value="<?= compliance_h($tpin) ?>" placeholder="ZRA TPIN"></div>
                <div class="field"><label>VAT Number</label><input name="vat_number" value="<?= compliance_h($vat_number) ?>" placeholder="VAT registration number"></div>
                <div class="field"><label>PACRA / Registration Number</label><input name="pacra_number" value="<?= compliance_h($pacra_number) ?>"></div>
                <div class="field"><label>Tax Registration Status</label><select name="tax_registration_status"><?php foreach(['not_registered','pending','registered','active','expired'] as $s): ?><option value="<?= $s ?>" <?= $tax_registration_status===$s?'selected':'' ?>><?= compliance_h(ucwords(str_replace('_',' ',$s))) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Default Tax Type</label><select name="default_tax_type"><?php foreach(['VAT','Turnover Tax','Income Tax','Other'] as $s): ?><option value="<?= compliance_h($s) ?>" <?= $default_tax_type===$s?'selected':'' ?>><?= compliance_h($s) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Smart Invoice Status</label><select name="smart_invoice_status"><?php foreach(['not_configured','pending','configured','registered','active'] as $s): ?><option value="<?= $s ?>" <?= $smart_invoice_status===$s?'selected':'' ?>><?= compliance_h(ucwords(str_replace('_',' ',$s))) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>VSDC Serial / Device Reference</label><input name="vsdc_serial" value="<?= compliance_h($vsdc_serial) ?>"></div>
                <div class="field full"><label>Compliance Notes</label><textarea name="notes" placeholder="Registration notes, certificates, references, etc."><?= compliance_h($compliance_notes) ?></textarea></div>
            </div>
            <div class="form-actions"><button class="btn green" type="submit"><i class="fas fa-save"></i>Save Compliance Profile</button></div>
        </form>
    </div></section>
    <div class="info-box"><strong>Phase 1 boundary:</strong> this record stores compliance configuration and readiness information. It does not make live VSDC/ZRA submissions.</div>

<?php elseif ($view === 'smart_invoice'): ?>
    <div class="grid-2">
        <section class="panel"><div class="panel-head"><div><h2>Tax configuration</h2><p>Current POS tax configuration used for compliance preparation.</p></div></div><div class="panel-body"><div class="metric-row"><div class="metric"><b><?= compliance_h($default_tax_type) ?></b><span>Default tax type</span></div><div class="metric"><b>16%</b><span>Current POS VAT rate</span></div><div class="metric"><b><?= $vat_number !== '' ? 'ON':'OFF' ?></b><span>VAT registration recorded</span></div></div><div class="info-box" style="margin-top:14px">The production sale processor currently calculates VAT-inclusive POS pricing at 16%. îˆ€fileciteîˆ‚turn199file4îˆ‚L454-L468îˆ</div></div></section>
        <section class="panel"><div class="panel-head"><div><h2>Smart Invoice status</h2><p>Configuration readiness before live integration.</p></div></div><div class="panel-body"><div class="readiness-item"><div><strong>Smart Invoice</strong><small>VSDC/device configuration state</small></div><span class="state <?= $readiness['smart_invoice']?'ok':'warn' ?>"><?= compliance_h(ucwords(str_replace('_',' ',$smart_invoice_status))) ?></span></div><div style="margin-top:12px"><a class="btn primary" href="compliance.php?view=taxpayer">Edit registration configuration</a></div></div></section>
    </div>

<?php elseif ($view === 'branches'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Branch / VSDC device register</h2><p>Register each branch/device record required for the Phase 1 compliance checklist.</p></div></div><div class="panel-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= compliance_h($csrf) ?>"><input type="hidden" name="action" value="save_branch_device"><input type="hidden" name="record_id" value="0">
            <div class="form-grid">
                <div class="field"><label>Branch</label><select name="branch_id" required><option value="">Select branch</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= compliance_h($b['branch_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Device Name</label><input name="device_name" placeholder="e.g. Main Till 01" required></div>
                <div class="field"><label>VSDC Serial / Reference</label><input name="vsdc_serial"></div>
                <div class="field"><label>Registration Status</label><select name="registration_status"><?php foreach(['pending','submitted','registered','active'] as $s): ?><option value="<?= $s ?>"><?= compliance_h(ucwords($s)) ?></option><?php endforeach; ?></select></div>
                <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
            </div>
            <div class="form-actions"><button class="btn green"><i class="fas fa-plus"></i>Add Device Record</button></div>
        </form>
    </div></section>
    <section class="panel"><div class="panel-head"><div><h3>Registered branch/device records</h3><p><?= count($branchDevices) ?> record(s)</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Branch</th><th>Device</th><th>VSDC Reference</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead><tbody>
    <?php if(!$branchDevices): ?><tr><td colspan="6" class="empty">No branch/device compliance records yet.</td></tr><?php else: foreach($branchDevices as $d): ?><tr><td><strong><?= compliance_h($d['branch_name'] ?? 'Unknown') ?></strong></td><td><?= compliance_h($d['device_name']) ?></td><td><?= $d['vsdc_serial']!==''?compliance_h($d['vsdc_serial']):'<span class="muted">â€”</span>' ?></td><td><span class="badge <?= $d['registration_status']==='registered'||$d['registration_status']==='active'?'green':($d['registration_status']==='pending'?'amber':'') ?>"><?= compliance_h($d['registration_status']) ?></span></td><td><?= !empty($d['registered_at'])?compliance_h(date('d M Y',strtotime($d['registered_at']))):'â€”' ?></td><td><form class="inline-form" method="post" onsubmit="return confirm('Remove this branch/device compliance record?')"><input type="hidden" name="csrf_token" value="<?= compliance_h($csrf) ?>"><input type="hidden" name="action" value="delete_branch_device"><input type="hidden" name="record_id" value="<?= (int)$d['id'] ?>"><button class="danger-link">Delete</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></section>

<?php elseif ($view === 'invoices'): ?>
    <section class="panel"><div class="panel-head"><div><h2>ZRA invoice readiness register</h2><p>Recent POS sales visible to the Phase 1 compliance layer. No live ZRA/VSDC submission is performed here.</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Sale</th><th>Invoice</th><th>Date</th><th>Total</th><th>Payment</th><th>Compliance</th></tr></thead><tbody>
    <?php if(!$zraInvoices): ?><tr><td colspan="6" class="empty">No sales records were found for this pharmacy/branch.</td></tr><?php else: foreach($zraInvoices as $s): ?><tr><td>#<?= (int)$s['id'] ?></td><td><?= compliance_h($s['invoice'] ?? 'â€”') ?></td><td><?= !empty($s['created_at'])?compliance_h(date('d M Y H:i',strtotime($s['created_at']))):'â€”' ?></td><td><?= compliance_money((float)($s['total'] ?? 0)) ?></td><td><?= compliance_h($s['payment_method'] ?? 'â€”') ?></td><td><span class="badge amber">Phase 1 only</span></td></tr><?php endforeach; endif; ?></tbody></table></div></section>

<?php elseif ($view === 'obligations'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Tax obligations</h2><p>Track filing periods, due dates and outstanding amounts.</p></div></div><div class="panel-body">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= compliance_h($csrf) ?>"><input type="hidden" name="action" value="save_obligation"><input type="hidden" name="record_id" value="0"><div class="form-grid"><div class="field"><label>Obligation</label><select name="obligation_type"><option>VAT</option><option>PAYE</option><option>Withholding Tax</option><option>Income Tax</option><option>Other</option></select></div><div class="field"><label>Period</label><input name="period_label" value="<?= compliance_h(date('F Y')) ?>"></div><div class="field"><label>Due Date</label><input type="date" name="due_date"></div><div class="field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" value="0"></div><div class="field"><label>Status</label><select name="status"><option>pending</option><option>submitted</option><option>paid</option><option>overdue</option><option>settled</option></select></div><div class="field"><label>Reference</label><input name="reference"></div><div class="field full"><label>Notes</label><textarea name="notes"></textarea></div></div><div class="form-actions"><button class="btn green"><i class="fas fa-plus"></i>Add Obligation</button></div></form>
    </div></section>
    <section class="panel"><div class="panel-head"><div><h3>Obligation register</h3></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Obligation</th><th>Period</th><th>Due</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody><?php if(!$obligations): ?><tr><td colspan="6" class="empty">No obligations recorded.</td></tr><?php else: foreach($obligations as $o): $isOverdue=!empty($o['due_date'])&&$o['due_date']<$today&&!in_array($o['status'],['paid','settled','complete'],true); ?><tr><td><strong><?= compliance_h($o['obligation_type']) ?></strong></td><td><?= compliance_h($o['period_label']) ?></td><td class="<?= $isOverdue?'':'muted' ?>"><?= !empty($o['due_date'])?compliance_h(date('d M Y',strtotime($o['due_date']))):'â€”' ?></td><td><?= compliance_money((float)$o['amount']) ?></td><td><span class="badge <?= $isOverdue?'red':($o['status']==='paid'||$o['status']==='settled'?'green':'amber') ?>"><?= compliance_h($o['status']) ?></span></td><td><?= $o['reference']!==''?compliance_h($o['reference']):'â€”' ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>

<?php elseif ($view === 'payments'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Tax payments</h2><p>Record completed filings/payments and preserve payment references.</p></div></div><div class="panel-body">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= compliance_h($csrf) ?>"><input type="hidden" name="action" value="save_payment"><input type="hidden" name="record_id" value="0"><div class="form-grid"><div class="field"><label>Payment Type</label><input name="payment_type" value="VAT"></div><div class="field"><label>Obligation</label><select name="obligation_id"><option value="0">â€” Not linked â€”</option><?php foreach($obligations as $o): ?><option value="<?= (int)$o['id'] ?>"><?= compliance_h($o['obligation_type'].' / '.$o['period_label']) ?> â€” <?= compliance_money((float)$o['amount']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Payment Date</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div><div class="field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" value="0"></div><div class="field"><label>Payment Reference</label><input name="payment_reference" placeholder="ZRA / bank / portal reference"></div><div class="field"><label>Status</label><select name="status"><option>recorded</option><option>submitted</option><option>confirmed</option></select></div><div class="field full"><label>Notes</label><textarea name="notes"></textarea></div></div><div class="form-actions"><button class="btn green"><i class="fas fa-save"></i>Record Payment</button></div></form>
    </div></section>
    <section class="panel"><div class="panel-head"><div><h3>Payment register</h3><p>Total recorded: <?= compliance_money($totalPayments) ?></p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Period</th><th>Date</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead><tbody><?php if(!$payments): ?><tr><td colspan="6" class="empty">No tax payments recorded.</td></tr><?php else: foreach($payments as $p): ?><tr><td><?= compliance_h($p['payment_type']) ?></td><td><?= compliance_h(($p['obligation_type']??'').' '.($p['period_label']??'')) ?></td><td><?= !empty($p['payment_date'])?compliance_h(date('d M Y',strtotime($p['payment_date']))):'â€”' ?></td><td><?= compliance_money((float)$p['amount']) ?></td><td><?= $p['payment_reference']!==''?compliance_h($p['payment_reference']):'â€”' ?></td><td><span class="badge green"><?= compliance_h($p['status']) ?></span></td></tr><?php endforeach; endif; ?></tbody></table></div></section>

<?php elseif ($view === 'audit'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Compliance audit trail</h2><p>Administrative changes made through the Phase 1 Compliance module.</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead><tbody><?php if(!$auditRows): ?><tr><td colspan="5" class="empty">No audit records found.</td></tr><?php else: foreach($auditRows as $a): ?><tr><td><?= compliance_h(date('d M Y H:i:s',strtotime($a['created_at']))) ?></td><td><?= compliance_h($a['actor']) ?></td><td><strong><?= compliance_h($a['action']) ?></strong></td><td><?= compliance_h($a['entity_type']) ?><?= $a['entity_id']?' #'.(int)$a['entity_id']:'' ?></td><td><?= $a['details']!==''?compliance_h($a['details']):'â€”' ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php endif; ?>

<div class="mini-note">EchoTech POS Compliance Phase 1 â€¢ Compliance administration only â€¢ Live ZRA/VSDC submission is intentionally disabled in this module.</div>
</section>
</main>
</div>
</body>
</html>
