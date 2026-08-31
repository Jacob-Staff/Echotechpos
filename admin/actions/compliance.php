<?php
/**
 * EchoTech POS - PHASE 1 COMPLIANCE / ZRA ADMIN
 *
 * Location:
 *   /admin/actions/compliance.php
 *
 * Browser:
 *   /admin/compliance.php
 *
 * Phase 1:
 *   - Compliance dashboard
 *   - ZRA taxpayer profile
 *   - Smart Invoice/VSDC configuration
 *   - VAT/tax registration settings
 *   - Branch compliance register
 *   - ZRA invoice register
 *   - Tax obligations and due dates
 *   - Tax payments register
 *   - Compliance audit log
 *
 * IMPORTANT:
 *   This module does NOT claim EchoTech POS is ZRA-certified and does
 *   NOT send live invoices to ZRA. ZRA states that third-party POS/ERP
 *   systems use the VSDC after certification. Phase 2 will add the
 *   certified VSDC integration.
 */
declare(strict_types=1);

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

$authCandidates = [
    __DIR__ . '/../../includes/auth.php',
    __DIR__ . '/../../includes/auth_helpers.php',
    __DIR__ . '/../../auth.php',
];
foreach ($authCandidates as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

$dbCandidates = [
    __DIR__ . '/../../includes/conn.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../db.php',
];
foreach ($dbCandidates as $f) {
    if (is_file($f)) {
        require_once $f;
        if (isset($conn) && $conn instanceof mysqli) {
            break;
        }
    }
}

if (function_exists('require_login')) {
    require_login();
}

$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'Admin') {
    http_response_code(403);
    exit('Access denied. Compliance is restricted to Admin.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}
$conn->set_charset('utf8mb4');

$pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($pharmacyId <= 0) {
    http_response_code(400);
    exit('Pharmacy session is missing.');
}

function compliance_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function compliance_money(mixed $v): string {
    return 'K' . number_format((float)$v, 2);
}
function compliance_table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $r = @$db->query("SHOW TABLES LIKE '{$table}'");
    return $r instanceof mysqli_result && $r->num_rows > 0;
}
function compliance_redirect(string $tab = 'overview', string $msg = '', string $err = ''): never {
    $q = ['tab' => $tab];
    if ($msg !== '') $q['ok'] = $msg;
    if ($err !== '') $q['err'] = $err;
    header('Location: ../compliance.php?' . http_build_query($q));
    exit;
}
function compliance_audit(mysqli $db, int $pharmacyId, int $userId, string $action, string $type = '', ?int $entityId = null, string $description = ''): void {
    if (!compliance_table_exists($db, 'compliance_audit_log')) return;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $stmt = $db->prepare("INSERT INTO compliance_audit_log
        (pharmacy_id,user_id,action,entity_type,entity_id,description,ip_address)
        VALUES (?,?,?,?,?,?,?)");
    if ($stmt) {
        $stmt->bind_param('iississ', $pharmacyId, $userId, $action, $type, $entityId, $description, $ip);
        @$stmt->execute();
        $stmt->close();
    }
}
function compliance_setting(mysqli $db, int $pharmacyId): array {
    if (!compliance_table_exists($db, 'compliance_settings')) return [];
    $stmt = $db->prepare("SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $r = $stmt->get_result();
    $row = $r ? $r->fetch_assoc() : [];
    $stmt->close();
    return $row ?: [];
}
function compliance_post(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
}
function compliance_bool(string $key): int {
    return isset($_POST[$key]) && $_POST[$key] === '1' ? 1 : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = compliance_post('action');

    if ($action === 'save_settings') {
        if (!compliance_table_exists($conn, 'compliance_settings')) {
            compliance_redirect('zra', '', 'Run the Phase 1 SQL migration first.');
        }

        $tpin = compliance_post('tpin');
        $businessName = compliance_post('business_name');
        $vatNo = compliance_post('vat_number');
        $smartStatus = compliance_post('smart_invoice_status', 'not_configured');
        $environment = compliance_post('smart_invoice_environment', 'test');
        $cis = compliance_post('cis_code');
        $serial = compliance_post('vsdc_serial');
        $vsdcStatus = compliance_post('vsdc_status', 'not_configured');
        $endpoint = compliance_post('vsdc_endpoint');
        $taxRef = compliance_post('tax_account_reference');
        $contactName = compliance_post('compliance_contact_name');
        $contactEmail = compliance_post('compliance_contact_email');
        $contactPhone = compliance_post('compliance_contact_phone');
        $notes = compliance_post('notes');
        $vatRegistered = compliance_bool('vat_registered');
        $incomeTax = compliance_bool('income_tax_registered');
        $turnoverTax = compliance_bool('turnover_tax_registered');

        $allowedSmart = ['not_configured','pending','test','connected','suspended'];
        $allowedEnv = ['test','production'];
        $allowedVsd = ['not_configured','offline','online','error'];
        if (!in_array($smartStatus, $allowedSmart, true)) $smartStatus = 'not_configured';
        if (!in_array($environment, $allowedEnv, true)) $environment = 'test';
        if (!in_array($vsdcStatus, $allowedVsd, true)) $vsdcStatus = 'not_configured';

        $stmt = $conn->prepare("
            INSERT INTO compliance_settings
            (pharmacy_id,tpin,business_name,vat_registered,vat_number,income_tax_registered,
             turnover_tax_registered,smart_invoice_status,smart_invoice_environment,cis_code,
             vsdc_serial,vsdc_status,vsdc_endpoint,tax_account_reference,compliance_contact_name,
             compliance_contact_email,compliance_contact_phone,notes,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              tpin=VALUES(tpin), business_name=VALUES(business_name),
              vat_registered=VALUES(vat_registered), vat_number=VALUES(vat_number),
              income_tax_registered=VALUES(income_tax_registered),
              turnover_tax_registered=VALUES(turnover_tax_registered),
              smart_invoice_status=VALUES(smart_invoice_status),
              smart_invoice_environment=VALUES(smart_invoice_environment),
              cis_code=VALUES(cis_code), vsdc_serial=VALUES(vsdc_serial),
              vsdc_status=VALUES(vsdc_status), vsdc_endpoint=VALUES(vsdc_endpoint),
              tax_account_reference=VALUES(tax_account_reference),
              compliance_contact_name=VALUES(compliance_contact_name),
              compliance_contact_email=VALUES(compliance_contact_email),
              compliance_contact_phone=VALUES(compliance_contact_phone),
              notes=VALUES(notes), updated_by=VALUES(updated_by)
        ");
        if (!$stmt) compliance_redirect('zra', '', 'Could not prepare settings save.');
        $stmt->bind_param(
            'issisiiisssssssssi',
            $pharmacyId,$tpin,$businessName,$vatRegistered,$vatNo,$incomeTax,$turnoverTax,
            $smartStatus,$environment,$cis,$serial,$vsdcStatus,$endpoint,$taxRef,
            $contactName,$contactEmail,$contactPhone,$notes,$userId
        );
        $ok = @$stmt->execute();
        $stmt->close();
        if (!$ok) compliance_redirect('zra', '', 'Settings were not saved.');
        compliance_audit($conn,$pharmacyId,$userId,'update_settings','compliance_settings',null,'Updated ZRA/compliance configuration.');
        compliance_redirect('zra','Compliance settings saved.');
    }

    if ($action === 'add_obligation') {
        $taxType = compliance_post('tax_type');
        $year = max(2000, (int)($_POST['period_year'] ?? date('Y')));
        $monthRaw = $_POST['period_month'] ?? '';
        $month = $monthRaw === '' ? null : max(1, min(12, (int)$monthRaw));
        $returnDue = compliance_post('return_due_date') ?: null;
        $paymentDue = compliance_post('payment_due_date') ?: null;
        $amount = max(0, (float)($_POST['amount_due'] ?? 0));
        $notes = compliance_post('notes');

        if ($taxType === '') compliance_redirect('obligations','','Tax type is required.');
        $stmt = $conn->prepare("INSERT INTO compliance_obligations
            (pharmacy_id,tax_type,period_year,period_month,return_due_date,payment_due_date,amount_due,notes,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) compliance_redirect('obligations','','Could not prepare obligation.');
        $stmt->bind_param('isiissdsii',$pharmacyId,$taxType,$year,$month,$returnDue,$paymentDue,$amount,$notes,$userId,$userId);
        $ok = @$stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        if (!$ok) compliance_redirect('obligations','','Could not save obligation.');
        compliance_audit($conn,$pharmacyId,$userId,'create_obligation','compliance_obligations',(int)$newId,"Created {$taxType} obligation.");
        compliance_redirect('obligations','Tax obligation added.');
    }

    if ($action === 'mark_obligation_filed') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE compliance_obligations SET return_status='filed',updated_by=? WHERE id=? AND pharmacy_id=?");
            if ($stmt) {
                $stmt->bind_param('iii',$userId,$id,$pharmacyId);
                @$stmt->execute();
                $stmt->close();
                compliance_audit($conn,$pharmacyId,$userId,'mark_filed','compliance_obligations',$id,'Marked tax return as filed.');
            }
        }
        compliance_redirect('obligations','Return marked as filed.');
    }

    if ($action === 'mark_obligation_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE compliance_obligations SET payment_status='paid',amount_paid=amount_due,updated_by=? WHERE id=? AND pharmacy_id=?");
            if ($stmt) {
                $stmt->bind_param('iii',$userId,$id,$pharmacyId);
                @$stmt->execute();
                $stmt->close();
                compliance_audit($conn,$pharmacyId,$userId,'mark_paid','compliance_obligations',$id,'Marked tax obligation as paid.');
            }
        }
        compliance_redirect('obligations','Payment marked as paid.');
    }

    compliance_redirect('overview');
}

$settings = compliance_setting($conn, $pharmacyId);
$tab = compliance_post('tab', $_GET['tab'] ?? 'overview');
$allowedTabs = ['overview','zra','tax','branches','invoices','obligations','payments','audit'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'overview';

$ok = (string)($_GET['ok'] ?? '');
$err = (string)($_GET['err'] ?? '');

$stats = [
    'branches' => 0, 'connected' => 0, 'pending_invoices' => 0,
    'failed_invoices' => 0, 'open_obligations' => 0, 'overdue' => 0
];

if (compliance_table_exists($conn,'compliance_branches')) {
    $stmt=$conn->prepare("SELECT COUNT(*) c, SUM(smart_invoice_registered=1) registered FROM compliance_branches WHERE pharmacy_id=?");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();$x=$r?$r->fetch_assoc():[];$stats['branches']=(int)($x['c']??0);$stats['connected']=(int)($x['registered']??0);$stmt->close();}
}
if (compliance_table_exists($conn,'compliance_invoices')) {
    $stmt=$conn->prepare("SELECT
        SUM(status='pending') pending_count,
        SUM(status='rejected') rejected_count
        FROM compliance_invoices WHERE pharmacy_id=?");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();$x=$r?$r->fetch_assoc():[];$stats['pending_invoices']=(int)($x['pending_count']??0);$stats['failed_invoices']=(int)($x['rejected_count']??0);$stmt->close();}
}
if (compliance_table_exists($conn,'compliance_obligations')) {
    $stmt=$conn->prepare("SELECT
        SUM(payment_status<>'paid' AND payment_status<>'not_applicable') open_count,
        SUM(payment_due_date < CURDATE() AND payment_status<>'paid') overdue_count
        FROM compliance_obligations WHERE pharmacy_id=?");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();$x=$r?$r->fetch_assoc():[];$stats['open_obligations']=(int)($x['open_count']??0);$stats['overdue']=(int)($x['overdue_count']??0);$stmt->close();}
}

$obligations = [];
if (compliance_table_exists($conn,'compliance_obligations')) {
    $stmt=$conn->prepare("SELECT * FROM compliance_obligations WHERE pharmacy_id=? ORDER BY COALESCE(payment_due_date,return_due_date) ASC, id DESC LIMIT 100");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();if($r)while($row=$r->fetch_assoc())$obligations[]=$row;$stmt->close();}
}
$invoices = [];
if (compliance_table_exists($conn,'compliance_invoices')) {
    $stmt=$conn->prepare("SELECT * FROM compliance_invoices WHERE pharmacy_id=? ORDER BY id DESC LIMIT 100");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();if($r)while($row=$r->fetch_assoc())$invoices[]=$row;$stmt->close();}
}
$branches = [];
if (compliance_table_exists($conn,'compliance_branches')) {
    $stmt=$conn->prepare("SELECT * FROM compliance_branches WHERE pharmacy_id=? ORDER BY branch_name");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();if($r)while($row=$r->fetch_assoc())$branches[]=$row;$stmt->close();}
}
$audits = [];
if (compliance_table_exists($conn,'compliance_audit_log')) {
    $stmt=$conn->prepare("SELECT * FROM compliance_audit_log WHERE pharmacy_id=? ORDER BY id DESC LIMIT 100");
    if($stmt){$stmt->bind_param('i',$pharmacyId);$stmt->execute();$r=$stmt->get_result();if($r)while($row=$r->fetch_assoc())$audits[]=$row;$stmt->close();}
}

$adminAside = __DIR__ . '/admin_aside.php';
$adminHeader = __DIR__ . '/admin_header.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compliance â€” EchoTech POS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{
    --bg:#f4f6f9;
    --card:#fff;
    --text:#17202b;
    --muted:#718096;
    --border:#e3e8ef;
    --blue:#2563eb;
    --green:#059669;
    --orange:#d97706;
    --red:#dc2626;
    --dark:#202831;
    --shadow:0 4px 18px rgba(15,23,42,.06);
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    min-height:100%;
}

body{
    background:var(--bg);
    color:var(--text);
    font:14px Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    overflow-x:hidden;
}

.app{
    min-height:100vh;
    width:100%;
}

/* =========================================================
   MAIN CONTENT LAYOUT
   Admin aside = 250px
   ========================================================= */

.main{
    min-height:100vh;
    margin-left:250px;
    width:calc(100% - 250px);
    position:relative;
    overflow-x:hidden;
}

/* =========================================================
   COMPLIANCE CONTENT
   ========================================================= */

.compliance-content{
    width:100%;
    max-width:1600px;
    margin:0 auto;
    padding:24px;
}

/* =========================================================
   PAGE HEADING
   ========================================================= */

.heading{
    display:flex;
    justify-content:space-between;
    gap:20px;
    align-items:flex-start;
    margin-bottom:18px;
}

.heading h1{
    margin:0 0 6px;
    font-size:28px;
    line-height:1.2;
}

.heading p{
    margin:0;
    color:var(--muted);
}

.heading .actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}

/* =========================================================
   BUTTONS
   ========================================================= */

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    padding:10px 14px;
    border-radius:8px;
    text-decoration:none;
    font-weight:700;
    cursor:pointer;
    white-space:nowrap;
    transition:.15s ease;
}

.btn:hover{
    transform:translateY(-1px);
}

.btn.primary{
    background:var(--blue);
    color:#fff;
    border-color:var(--blue);
}

/* =========================================================
   ALERTS
   ========================================================= */

.alert{
    padding:12px 14px;
    border-radius:9px;
    margin-bottom:14px;
}

.alert.ok{
    background:#ecfdf5;
    color:#047857;
    border:1px solid #a7f3d0;
}

.alert.err{
    background:#fef2f2;
    color:#b91c1c;
    border:1px solid #fecaca;
}

/* =========================================================
   SUMMARY CARDS
   ========================================================= */

.cards{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:18px;
}

.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px;
    box-shadow:var(--shadow);
    min-width:0;
}

.label{
    font-size:11px;
    text-transform:uppercase;
    color:var(--muted);
    font-weight:800;
}

.value{
    font-size:25px;
    font-weight:800;
    margin-top:8px;
}

.sub{
    font-size:11px;
    color:var(--muted);
    margin-top:4px;
}

/* =========================================================
   TABS
   ========================================================= */

.tabs{
    display:flex;
    gap:5px;
    overflow-x:auto;
    overflow-y:hidden;
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:6px;
    margin-bottom:16px;
    scrollbar-width:thin;
}

.tabs a{
    white-space:nowrap;
    padding:10px 12px;
    border-radius:8px;
    text-decoration:none;
    color:#52606d;
    font-weight:700;
    flex:0 0 auto;
}

.tabs a.active{
    background:#eef4ff;
    color:var(--blue);
}

/* =========================================================
   PANELS
   ========================================================= */

.panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:16px;
    overflow:hidden;
}

.panel-head{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
}

.panel-head h2{
    margin:0;
    font-size:17px;
}

.panel-head p{
    margin:4px 0 0;
    color:var(--muted);
    font-size:12px;
}

.panel-body{
    padding:18px;
}

/* =========================================================
   GRIDS
   ========================================================= */

.grid2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.grid3{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
}

/* =========================================================
   FORM FIELDS
   ========================================================= */

.field{
    margin-bottom:12px;
}

.field label{
    display:block;
    font-size:12px;
    font-weight:800;
    margin-bottom:6px;
}

.field input,
.field select,
.field textarea{
    width:100%;
    border:1px solid #d9e0e8;
    border-radius:8px;
    padding:10px 11px;
    background:#fff;
    font:inherit;
    color:var(--text);
}

.field input:focus,
.field select:focus,
.field textarea:focus{
    outline:none;
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(37,99,235,.08);
}

.field textarea{
    min-height:90px;
    resize:vertical;
}

/* =========================================================
   CHECKBOX
   ========================================================= */

.check{
    display:flex;
    gap:8px;
    align-items:center;
    padding:10px;
    border:1px solid var(--border);
    border-radius:8px;
}

.check input{
    width:auto;
}

/* =========================================================
   ACTIONS
   ========================================================= */

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:6px;
}

/* =========================================================
   TABLES
   ========================================================= */

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:650px;
}

th,
td{
    text-align:left;
    padding:11px 10px;
    border-bottom:1px solid #edf0f4;
    font-size:12px;
}

th{
    font-size:11px;
    color:#667789;
    text-transform:uppercase;
    background:#fafbfc;
    white-space:nowrap;
}

td{
    vertical-align:middle;
}

/* =========================================================
   BADGES
   ========================================================= */

.badge{
    display:inline-block;
    padding:5px 8px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
}

.green{
    background:#eaf8f1;
    color:#047857;
}

.orange{
    background:#fff7e6;
    color:#b45309;
}

.red{
    background:#fef0f0;
    color:#b91c1c;
}

.gray{
    background:#eef2f5;
    color:#5b6773;
}

/* =========================================================
   NOTICE
   ========================================================= */

.notice{
    padding:12px;
    border:1px solid #dbe5f3;
    background:#f7faff;
    border-radius:8px;
    color:#40566f;
    font-size:12px;
    line-height:1.55;
}

/* =========================================================
   LINK BOX
   ========================================================= */

.linkbox{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:14px;
    border:1px solid var(--border);
    border-radius:9px;
    margin-bottom:10px;
}

.linkbox b{
    display:block;
}

.linkbox span{
    font-size:11px;
    color:var(--muted);
}

/* =========================================================
   EMPTY STATE
   ========================================================= */

.empty{
    text-align:center;
    padding:35px;
    color:var(--muted);
}

/* =========================================================
   DESKTOP / TABLET
   ========================================================= */

@media(max-width:1200px){

    .cards{
        grid-template-columns:repeat(3,1fr);
    }

}

/* =========================================================
   MOBILE
   ========================================================= */

@media(max-width:800px){

    .main{
        margin-left:0;
        width:100%;
    }

    .compliance-content{
        padding:14px;
    }

    .heading{
        flex-direction:column;
    }

    .heading .actions{
        width:100%;
    }

    .grid2,
    .grid3{
        grid-template-columns:1fr;
    }

    .cards{
        grid-template-columns:repeat(2,1fr);
    }

}

/* =========================================================
   SMALL MOBILE
   ========================================================= */

@media(max-width:520px){

    .cards{
        grid-template-columns:1fr;
    }

    .heading h1{
        font-size:23px;
    }

    .heading p{
        line-height:1.5;
    }

    .compliance-content{
        padding:12px;
    }

    .panel-body{
        padding:14px;
    }

}

/* =========================================================
   PRINT
   ========================================================= */

@media print{

    .tabs,
    .btn,
    .heading .actions{
        display:none!important;
    }

    .main{
        margin-left:0;
        width:100%;
    }

    .compliance-content{
        padding:0;
        max-width:none;
    }

    .panel{
        box-shadow:none;
    }

}
</style>
</head>
<body>
<div class="app">
<?php if (is_file($adminAside)) require $adminAside; ?>
<main class="main">
<?php if (is_file($adminHeader)) require $adminHeader; ?>

<section class="compliance-content">
<div class="heading">
  <div>
    <div style="color:#2563eb;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase">Administration / Compliance</div>
    <h1>ZRA & Tax Compliance</h1>
    <p>Central compliance control for the multi-branch pharmacy POS.</p>
  </div>
  <div class="actions">
    <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <a class="btn primary" href="https://www.zra.org.zm/smart-invoice-learn-more/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> ZRA Smart Invoice</a>
  </div>
</div>

<?php if ($ok !== ''): ?><div class="alert ok"><i class="fa-solid fa-circle-check"></i> <?= compliance_h($ok) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i> <?= compliance_h($err) ?></div><?php endif; ?>

<div class="cards">
  <div class="card"><div class="label">Smart Invoice</div><div class="value"><?= compliance_h(ucwords(str_replace('_',' ',(string)($settings['smart_invoice_status'] ?? 'not_configured')))) ?></div><div class="sub">Current configuration</div></div>
  <div class="card"><div class="label">Branches</div><div class="value"><?= $stats['branches'] ?></div><div class="sub"><?= $stats['connected'] ?> marked registered</div></div>
  <div class="card"><div class="label">Pending Invoices</div><div class="value"><?= $stats['pending_invoices'] ?></div><div class="sub">Phase 2 submission queue</div></div>
  <div class="card"><div class="label">Rejected Invoices</div><div class="value"><?= $stats['failed_invoices'] ?></div><div class="sub">Needs review</div></div>
  <div class="card"><div class="label">Open Obligations</div><div class="value"><?= $stats['open_obligations'] ?></div><div class="sub">Returns/payments</div></div>
  <div class="card"><div class="label">Overdue</div><div class="value"><?= $stats['overdue'] ?></div><div class="sub">Past payment due date</div></div>
</div>

<nav class="tabs">
<?php foreach ([
'overview'=>['fa-gauge-high','Overview'],'zra'=>['fa-landmark','ZRA / Smart Invoice'],
'tax'=>['fa-percent','Tax Configuration'],'branches'=>['fa-code-branch','Branches'],
'invoices'=>['fa-file-invoice','ZRA Invoices'],'obligations'=>['fa-calendar-check','Obligations'],
'payments'=>['fa-money-bill-transfer','Tax Payments'],'audit'=>['fa-shield-halved','Audit Log']
] as $key=>$t): ?>
<a class="<?= $tab===$key?'active':'' ?>" href="?tab=<?= $key ?>"><i class="fa-solid <?= $t[0] ?>"></i> <?= $t[1] ?></a>
<?php endforeach; ?>
</nav>

<?php if ($tab==='overview'): ?>
<div class="grid2">
  <div class="panel"><div class="panel-head"><h2>Compliance readiness</h2><p>Phase 1 records that must be completed before live Smart Invoice integration.</p></div><div class="panel-body">
    <div class="linkbox"><div><b>Taxpayer TPIN</b><span><?= $settings['tpin'] ? 'Configured' : 'Missing' ?></span></div><span class="badge <?= $settings['tpin']?'green':'red' ?>"><?= $settings['tpin']?'READY':'ACTION' ?></span></div>
    <div class="linkbox"><div><b>VAT registration</b><span><?= !empty($settings['vat_registered']) ? 'Registered' : 'Not marked registered' ?></span></div><span class="badge <?= !empty($settings['vat_registered'])?'green':'gray' ?>"><?= !empty($settings['vat_registered'])?'ON':'OFF' ?></span></div>
    <div class="linkbox"><div><b>Smart Invoice configuration</b><span><?= compliance_h(ucwords(str_replace('_',' ',(string)($settings['smart_invoice_status'] ?? 'not_configured')))) ?></span></div><span class="badge <?= (($settings['smart_invoice_status']??'')==='connected')?'green':'orange' ?>">STATUS</span></div>
    <div class="linkbox"><div><b>VSDC</b><span><?= compliance_h(ucwords(str_replace('_',' ',(string)($settings['vsdc_status'] ?? 'not_configured')))) ?></span></div><span class="badge <?= (($settings['vsdc_status']??'')==='online')?'green':'gray' ?>">STATUS</span></div>
  </div></div>
  <div class="panel"><div class="panel-head"><h2>ZRA workflow</h2><p>Phase 1 prepares the compliance layer without sending live data.</p></div><div class="panel-body">
    <div class="notice"><b>Important:</b> EchoTech POS is not declaring itself ZRA-certified here. ZRA says third-party POS/ERP solutions use the VSDC bridge after certification. The live invoice API belongs in Phase 2.</div>
    <div style="margin-top:14px" class="linkbox"><div><b>1. Complete taxpayer profile</b><span>TPIN, VAT and tax registrations</span></div><i class="fa-solid fa-check"></i></div>
    <div class="linkbox"><div><b>2. Register each branch/device</b><span>Branch Smart Invoice and VSDC information</span></div><i class="fa-solid fa-check"></i></div>
    <div class="linkbox"><div><b>3. Track obligations</b><span>VAT, PAYE and other tax periods</span></div><i class="fa-solid fa-check"></i></div>
    <div class="linkbox"><div><b>4. Integrate VSDC</b><span>Phase 2 after test/UAT/certification</span></div><i class="fa-solid fa-lock"></i></div>
  </div></div>
</div>
<?php endif; ?>

<?php if ($tab==='zra' || $tab==='tax'): ?>
<div class="panel">
<div class="panel-head"><h2><?= $tab==='zra'?'ZRA / Smart Invoice Configuration':'Tax Configuration' ?></h2><p>Store taxpayer configuration safely. No live ZRA submission occurs in Phase 1.</p></div>
<div class="panel-body">
<form method="post">
<input type="hidden" name="action" value="save_settings">
<div class="grid2">
<div>
<div class="field"><label>Business / Taxpayer Name</label><input name="business_name" value="<?= compliance_h($settings['business_name']??'') ?>"></div>
<div class="field"><label>ZRA TPIN</label><input name="tpin" value="<?= compliance_h($settings['tpin']??'') ?>" placeholder="Enter taxpayer TPIN"></div>
<div class="field"><label>VAT Number / Registration Reference</label><input name="vat_number" value="<?= compliance_h($settings['vat_number']??'') ?>"></div>
<div class="grid2">
<div class="check"><input type="checkbox" name="vat_registered" value="1" <?= !empty($settings['vat_registered'])?'checked':'' ?>><label>VAT registered</label></div>
<div class="check"><input type="checkbox" name="income_tax_registered" value="1" <?= !empty($settings['income_tax_registered'])?'checked':'' ?>><label>Income Tax registered</label></div>
</div>
<div class="check" style="margin-top:10px"><input type="checkbox" name="turnover_tax_registered" value="1" <?= !empty($settings['turnover_tax_registered'])?'checked':'' ?>><label>Turnover Tax registered</label></div>
</div>
<div>
<div class="field"><label>Smart Invoice status</label><select name="smart_invoice_status">
<?php foreach(['not_configured','pending','test','connected','suspended'] as $v): ?><option value="<?= $v ?>" <?= (($settings['smart_invoice_status']??'not_configured')===$v)?'selected':'' ?>><?= ucwords(str_replace('_',' ',$v)) ?></option><?php endforeach; ?>
</select></div>
<div class="field"><label>Smart Invoice environment</label><select name="smart_invoice_environment"><option value="test" <?= (($settings['smart_invoice_environment']??'test')==='test')?'selected':'' ?>>Test</option><option value="production" <?= (($settings['smart_invoice_environment']??'test')==='production')?'selected':'' ?>>Production</option></select></div>
<div class="field"><label>CIS Code</label><input name="cis_code" value="<?= compliance_h($settings['cis_code']??'') ?>"></div>
<div class="field"><label>VSDC Serial Number</label><input name="vsdc_serial" value="<?= compliance_h($settings['vsdc_serial']??'') ?>"></div>
<div class="field"><label>VSDC Status</label><select name="vsdc_status"><?php foreach(['not_configured','offline','online','error'] as $v): ?><option value="<?= $v ?>" <?= (($settings['vsdc_status']??'not_configured')===$v)?'selected':'' ?>><?= ucwords(str_replace('_',' ',$v)) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>VSDC Endpoint</label><input name="vsdc_endpoint" value="<?= compliance_h($settings['vsdc_endpoint']??'') ?>" placeholder="Leave blank until Phase 2"></div>
</div>
</div>
<div class="grid3">
<div class="field"><label>Tax Account Reference</label><input name="tax_account_reference" value="<?= compliance_h($settings['tax_account_reference']??'') ?>"></div>
<div class="field"><label>Compliance Contact</label><input name="compliance_contact_name" value="<?= compliance_h($settings['compliance_contact_name']??'') ?>"></div>
<div class="field"><label>Contact Phone</label><input name="compliance_contact_phone" value="<?= compliance_h($settings['compliance_contact_phone']??'') ?>"></div>
</div>
<div class="field"><label>Contact Email</label><input type="email" name="compliance_contact_email" value="<?= compliance_h($settings['compliance_contact_email']??'') ?>"></div>
<div class="field"><label>Compliance Notes</label><textarea name="notes"><?= compliance_h($settings['notes']??'') ?></textarea></div>
<div class="actions"><button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Compliance Settings</button><a class="btn" target="_blank" rel="noopener" href="https://siportal.zra.org.zm/main/signup/indexLearnMore"><i class="fa-solid fa-arrow-up-right-from-square"></i> ZRA Registration Portal</a></div>
</form>
</div></div>
<?php endif; ?>

<?php if ($tab==='branches'): ?>
<div class="panel"><div class="panel-head"><h2>Branch Compliance Register</h2><p>Phase 1 stores branch-level Smart Invoice/VSDC readiness. Branch discovery is intentionally not automatic to avoid guessing your existing branches schema.</p></div><div class="panel-body">
<div class="notice">Use this register to record each existing POS branch and its ZRA/Smart Invoice status. We can connect it directly to your existing <code>branches</code> table in Phase 2 after confirming its columns.</div>
<?php if ($branches): ?><div style="overflow:auto;margin-top:14px"><table><thead><tr><th>Branch</th><th>Branch TPIN</th><th>Smart Invoice</th><th>VSDC</th><th>Device</th><th>Notes</th></tr></thead><tbody>
<?php foreach($branches as $b): ?><tr><td><?= compliance_h($b['branch_name']) ?></td><td><?= compliance_h($b['branch_tpin']??'-') ?></td><td><span class="badge <?= $b['smart_invoice_registered']?'green':'orange' ?>"><?= $b['smart_invoice_registered']?'Registered':'Not registered' ?></span></td><td><?= compliance_h($b['vsdc_status']) ?></td><td><?= compliance_h($b['device_serial']??'-') ?></td><td><?= compliance_h($b['notes']??'') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><div class="empty">No branch compliance records have been created yet.</div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($tab==='invoices'): ?>
<div class="panel"><div class="panel-head"><h2>ZRA Invoice Register</h2><p>Phase 1 audit/register view. Live Smart Invoice submission is a Phase 2 function.</p></div><div class="panel-body">
<div class="notice">When Phase 2 is connected, each completed POS sale will be linked to its ZRA response, including the ZRA invoice number, SDC ID, signature and internal data. ZRA describes the fiscal signature as the authenticity/integrity mark for electronic invoices.</div>
<div style="overflow:auto;margin-top:14px"><?php if($invoices): ?><table><thead><tr><th>Local Invoice</th><th>ZRA Invoice</th><th>Status</th><th>Gross</th><th>VAT</th><th>SDC</th><th>Submitted</th></tr></thead><tbody>
<?php foreach($invoices as $i): ?><tr><td><?= compliance_h($i['local_invoice_no']??'-') ?></td><td><?= compliance_h($i['zra_invoice_no']??'-') ?></td><td><span class="badge <?= $i['status']==='accepted'?'green':($i['status']==='rejected'?'red':'orange') ?>"><?= compliance_h($i['status']) ?></span></td><td><?= compliance_money($i['gross_amount']) ?></td><td><?= compliance_money($i['vat_amount']) ?></td><td><?= compliance_h($i['zra_sdc_id']??'-') ?></td><td><?= compliance_h($i['submitted_at']??'-') ?></td></tr><?php endforeach; ?>
</tbody></table><?php else: ?><div class="empty">No ZRA invoice records yet. Existing POS sales are not altered by Phase 1.</div><?php endif; ?></div>
</div></div>
<?php endif; ?>

<?php if ($tab==='obligations'): ?>
<div class="grid2">
<div class="panel"><div class="panel-head"><h2>Add Tax Obligation</h2><p>Use this to create a tracked return/payment period.</p></div><div class="panel-body">
<form method="post"><input type="hidden" name="action" value="add_obligation">
<div class="field"><label>Tax Type</label><select name="tax_type"><option>VAT</option><option>PAYE</option><option>Withholding Tax</option><option>Turnover Tax</option><option>Income Tax</option><option>Skills Development Levy</option><option>Other</option></select></div>
<div class="grid2"><div class="field"><label>Year</label><input type="number" name="period_year" value="<?= date('Y') ?>"></div><div class="field"><label>Month</label><select name="period_month"><option value="">Annual / N/A</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>"><?= date('F',mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div></div>
<div class="grid2"><div class="field"><label>Return due date</label><input type="date" name="return_due_date"></div><div class="field"><label>Payment due date</label><input type="date" name="payment_due_date"></div></div>
<div class="field"><label>Amount due</label><input type="number" step="0.01" min="0" name="amount_due" value="0"></div>
<div class="field"><label>Notes</label><textarea name="notes"></textarea></div>
<button class="btn primary" type="submit"><i class="fa-solid fa-plus"></i> Add Obligation</button>
</form>
</div></div>
<div class="panel"><div class="panel-head"><h2>ZRA Due-Date Reference</h2><p>Official ZRA dates used as the Phase 1 planning reference.</p></div><div class="panel-body">
<div class="linkbox"><div><b>PAYE</b><span>Return and payment by the 10th of the following month</span></div><span class="badge green">10TH</span></div>
<div class="linkbox"><div><b>VAT â€” electronic</b><span>Payment due by the 18th of the following month</span></div><span class="badge green">18TH</span></div>
<div class="linkbox"><div><b>Withholding Tax</b><span>Return/payment by the 14th of the following month</span></div><span class="badge green">14TH</span></div>
<div class="notice">Always verify the applicable obligation and current ZRA guidance before filing. Phase 1 is a management tracker, not a tax filing engine.</div>
</div></div>
</div>
<div class="panel"><div class="panel-head"><h2>Tracked Obligations</h2><p>Open, filed and paid compliance periods.</p></div><div class="panel-body" style="overflow:auto">
<?php if($obligations): ?><table><thead><tr><th>Tax</th><th>Period</th><th>Return Due</th><th>Payment Due</th><th>Amount</th><th>Return</th><th>Payment</th><th>Actions</th></tr></thead><tbody>
<?php foreach($obligations as $o): ?><tr>
<td><?= compliance_h($o['tax_type']) ?></td><td><?= compliance_h($o['period_year'].' / '.($o['period_month']?$o['period_month']:'Annual')) ?></td>
<td><?= compliance_h($o['return_due_date']??'-') ?></td><td><?= compliance_h($o['payment_due_date']??'-') ?></td><td><?= compliance_money($o['amount_due']) ?></td>
<td><span class="badge <?= $o['return_status']==='filed'?'green':'orange' ?>"><?= compliance_h($o['return_status']) ?></span></td>
<td><span class="badge <?= $o['payment_status']==='paid'?'green':($o['payment_status']==='partial'?'orange':'red') ?>"><?= compliance_h($o['payment_status']) ?></span></td>
<td><div class="actions">
<?php if($o['return_status']!=='filed'): ?><form method="post"><input type="hidden" name="action" value="mark_obligation_filed"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn" type="submit">Mark Filed</button></form><?php endif; ?>
<?php if($o['payment_status']!=='paid'): ?><form method="post"><input type="hidden" name="action" value="mark_obligation_paid"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn" type="submit">Mark Paid</button></form><?php endif; ?>
</div></td></tr><?php endforeach; ?>
</tbody></table><?php else: ?><div class="empty">No tax obligations have been entered.</div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($tab==='payments'): ?>
<div class="panel"><div class="panel-head"><h2>Tax Payments</h2><p>Phase 1 payment register reserved for ZRA reconciliation.</p></div><div class="panel-body">
<div class="notice">Payments are intentionally kept separate from the obligation tracker so that future ZRA payment references can be reconciled without changing payroll or sales tables.</div>
<div class="empty">No payment records yet. Payment capture/reconciliation will be expanded in Phase 2.</div>
</div></div>
<?php endif; ?>

<?php if ($tab==='audit'): ?>
<div class="panel"><div class="panel-head"><h2>Compliance Audit Log</h2><p>Administrative changes made inside the compliance module.</p></div><div class="panel-body" style="overflow:auto">
<?php if($audits): ?><table><thead><tr><th>Date</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead><tbody>
<?php foreach($audits as $a): ?><tr><td><?= compliance_h($a['created_at']) ?></td><td><?= compliance_h($a['action']) ?></td><td><?= compliance_h($a['entity_type']??'-') ?></td><td><?= compliance_h($a['description']??'') ?></td><td><?= compliance_h($a['ip_address']??'-') ?></td></tr><?php endforeach; ?>
</tbody></table><?php else: ?><div class="empty">No compliance audit entries yet.</div><?php endif; ?>
</div></div>
<?php endif; ?>

</section>
</main>
</div>
</body>
</html>
