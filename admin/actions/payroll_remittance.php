<?php
/**
 * ============================================================
 * EchoTech POS - Payroll Statutory Returns & Remittance
 * ============================================================
 *
 * Location:
 *   /admin/actions/payroll_remittance.php
 *
 * Phase 5:
 *   - PAYE return/payment tracking
 *   - NAPSA return/payment tracking
 *   - NHIMA return/payment tracking
 *   - Monthly statutory liability summary
 *   - Due-date monitoring
 *   - Mark submitted / paid
 *   - Record payment references
 *   - Outstanding balances
 *   - Audit trail
 *
 * The page does NOT make payments to ZRA/NAPSA/NHIMA.
 * It records the employer's filing/remittance status.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/zambia_payroll_engine.php';

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

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function pr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pr_money(float $value): string
{
    return 'K' . number_format($value, 2);
}

function pr_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pr_column_exists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pr_current_user_name(): string
{
    return (string)(
        $_SESSION['full_name']
        ?? $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? 'Administrator'
    );
}

/*
|--------------------------------------------------------------------------
| Period
|--------------------------------------------------------------------------
*/

$month = (int)($_GET['month'] ?? $_POST['month'] ?? date('n'));
$year  = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));

if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

if ($year < 2000 || $year > 2200) {
    $year = (int)date('Y');
}

$period = sprintf('%04d-%02d', $year, $month);
$periodDate = new DateTimeImmutable($period . '-01');
$periodLabel = $periodDate->format('F Y');

/*
 * All three monthly returns are treated as due on the 10th of the
 * following month. ZRA officially publishes PAYE as due on the 10th
 * of the subsequent month; NAPSA and NHIMA also publish the 10th as
 * their monthly remittance date.
 */
$dueDate = $periodDate->modify('first day of next month')->setDate(
    (int)$periodDate->modify('first day of next month')->format('Y'),
    (int)$periodDate->modify('first day of next month')->format('m'),
    10
);

$today = new DateTimeImmutable('today');

$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Create statutory remittance tables automatically
|--------------------------------------------------------------------------
*/

if (!pr_table_exists($conn, 'payroll_remittances')) {

    $create = "
        CREATE TABLE `payroll_remittances` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payroll_period` CHAR(7) NOT NULL,
            `statutory_type` ENUM('PAYE','NAPSA','NHIMA') NOT NULL,
            `liability_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `employee_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `employer_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `due_date` DATE NOT NULL,
            `return_status` ENUM('pending','submitted','paid','overdue') NOT NULL DEFAULT 'pending',
            `payment_date` DATE NULL,
            `payment_reference` VARCHAR(150) NULL,
            `return_reference` VARCHAR(150) NULL,
            `notes` TEXT NULL,
            `created_by` VARCHAR(150) NULL,
            `updated_by` VARCHAR(150) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_payroll_remittance_period_type`
                (`payroll_period`,`statutory_type`),
            KEY `idx_payroll_remittance_period` (`payroll_period`),
            KEY `idx_payroll_remittance_status` (`return_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($create)) {
        $error = 'Could not create payroll_remittances table: ' . $conn->error;
    }
}

/*
|--------------------------------------------------------------------------
| Audit table
|--------------------------------------------------------------------------
*/

if ($error === '' && !pr_table_exists($conn, 'payroll_remittance_audit')) {

    $createAudit = "
        CREATE TABLE `payroll_remittance_audit` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `remittance_id` INT UNSIGNED NOT NULL,
            `action` VARCHAR(50) NOT NULL,
            `old_status` VARCHAR(30) NULL,
            `new_status` VARCHAR(30) NULL,
            `reference_value` VARCHAR(150) NULL,
            `performed_by` VARCHAR(150) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_remittance_audit_remittance` (`remittance_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($createAudit)) {
        $error = 'Could not create payroll_remittance_audit table: ' . $conn->error;
    }
}

/*
|--------------------------------------------------------------------------
| Calculate liability from payroll_records
|--------------------------------------------------------------------------
*/

$liability = [
    'PAYE' => 0.0,
    'NAPSA' => 0.0,
    'NHIMA' => 0.0,
];

$employeeTotals = [
    'NAPSA' => 0.0,
    'NHIMA' => 0.0,
];

$employerTotals = [
    'NAPSA' => 0.0,
    'NHIMA' => 0.0,
];

$payrollRows = [];

if ($error === '' && pr_table_exists($conn, 'payroll_records')) {

    $stmt = $conn->prepare("
        SELECT
            paye,
            napsa,
            nhima,
            employer_napsa,
            employer_nhima,
            status
        FROM payroll_records
        WHERE payroll_period = ?
    ");

    if ($stmt) {

        $stmt->bind_param('s', $period);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $payrollRows[] = $row;

            $liability['PAYE'] += (float)$row['paye'];

            $employeeTotals['NAPSA'] += (float)$row['napsa'];
            $employerTotals['NAPSA'] += (float)$row['employer_napsa'];

            $employeeTotals['NHIMA'] += (float)$row['nhima'];
            $employerTotals['NHIMA'] += (float)$row['employer_nhima'];
        }

        $stmt->close();
    }
}

$liability['NAPSA'] =
    $employeeTotals['NAPSA']
    + $employerTotals['NAPSA'];

$liability['NHIMA'] =
    $employeeTotals['NHIMA']
    + $employerTotals['NHIMA'];

/*
|--------------------------------------------------------------------------
| Upsert statutory liability records
|--------------------------------------------------------------------------
*/

if ($error === '') {

    $upsert = $conn->prepare("
        INSERT INTO payroll_remittances
        (
            payroll_period,
            statutory_type,
            liability_amount,
            employee_amount,
            employer_amount,
            due_date,
            created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            liability_amount = VALUES(liability_amount),
            employee_amount = VALUES(employee_amount),
            employer_amount = VALUES(employer_amount),
            due_date = VALUES(due_date)
    ");

    if ($upsert) {

        $dueDateSql = $dueDate->format('Y-m-d');
        $createdBy = pr_current_user_name();

        foreach (['PAYE', 'NAPSA', 'NHIMA'] as $type) {

            $employee = $employeeTotals[$type] ?? 0.0;
            $employer = $employerTotals[$type] ?? 0.0;
            $total = $liability[$type];

            $upsert->bind_param(
                'ssdddss',
                $period,
                $type,
                $total,
                $employee,
                $employer,
                $dueDateSql,
                $createdBy
            );

            $upsert->execute();
        }

        $upsert->close();

    } else {
        $error = 'Could not prepare remittance records: ' . $conn->error;
    }
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $error === '') {

    $action = (string)($_POST['action'] ?? '');
    $remittanceId = (int)($_POST['remittance_id'] ?? 0);

    if ($action === 'save_remittance' && $remittanceId > 0) {

        $newStatus = (string)($_POST['return_status'] ?? 'pending');

        $allowedStatuses = [
            'pending',
            'submitted',
            'paid',
            'overdue',
        ];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            $newStatus = 'pending';
        }

        $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $returnReference = trim((string)($_POST['return_reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $oldStatus = '';

        $oldStmt = $conn->prepare("
            SELECT return_status
            FROM payroll_remittances
            WHERE id = ?
            LIMIT 1
        ");

        if ($oldStmt) {
            $oldStmt->bind_param('i', $remittanceId);
            $oldStmt->execute();
            $oldResult = $oldStmt->get_result();
            $oldRow = $oldResult->fetch_assoc();
            $oldStatus = (string)($oldRow['return_status'] ?? '');
            $oldStmt->close();
        }

        if ($paymentDate !== '') {
            $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
                $paymentDate = '';
            }
        }

        $updatedBy = pr_current_user_name();

        $stmt = $conn->prepare("
            UPDATE payroll_remittances
            SET
                return_status = ?,
                payment_date = NULLIF(?, ''),
                payment_reference = NULLIF(?, ''),
                return_reference = NULLIF(?, ''),
                notes = NULLIF(?, ''),
                updated_by = ?
            WHERE id = ?
        ");

        if ($stmt) {

            $stmt->bind_param(
                'ssssssi',
                $newStatus,
                $paymentDate,
                $paymentReference,
                $returnReference,
                $notes,
                $updatedBy,
                $remittanceId
            );

            if ($stmt->execute()) {

                $audit = $conn->prepare("
                    INSERT INTO payroll_remittance_audit
                    (
                        remittance_id,
                        action,
                        old_status,
                        new_status,
                        reference_value,
                        performed_by
                    )
                    VALUES (?, 'STATUS_UPDATE', ?, ?, ?, ?)
                ");

                if ($audit) {

                    $referenceValue =
                        $paymentReference !== ''
                            ? $paymentReference
                            : $returnReference;

                    $audit->bind_param(
                        'issss',
                        $remittanceId,
                        $oldStatus,
                        $newStatus,
                        $referenceValue,
                        $updatedBy
                    );

                    $audit->execute();
                    $audit->close();
                }

                $success = 'Statutory remittance record updated successfully.';
            } else {
                $error = 'Could not save remittance: ' . $stmt->error;
            }

            $stmt->close();

        } else {
            $error = 'Could not prepare remittance update: ' . $conn->error;
        }
    }

    header(
        'Location: payroll_remittance.php?month=' .
        $month .
        '&year=' .
        $year .
        ($success !== ''
            ? '&saved=1'
            : ($error !== '' ? '&error=' . urlencode($error) : ''))
    );
    exit;
}

if (isset($_GET['saved'])) {
    $success = 'Statutory remittance record updated successfully.';
}

if (isset($_GET['error']) && $error === '') {
    $error = (string)$_GET['error'];
}

/*
|--------------------------------------------------------------------------
| Load remittance records
|--------------------------------------------------------------------------
*/

$remittances = [];

if ($error === '') {

    $stmt = $conn->prepare("
        SELECT
            id,
            statutory_type,
            liability_amount,
            employee_amount,
            employer_amount,
            due_date,
            return_status,
            payment_date,
            payment_reference,
            return_reference,
            notes,
            updated_by,
            updated_at
        FROM payroll_remittances
        WHERE payroll_period = ?
        ORDER BY FIELD(statutory_type,'PAYE','NAPSA','NHIMA')
    ");

    if ($stmt) {

        $stmt->bind_param('s', $period);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $remittances[] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$totalLiability = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;

foreach ($remittances as &$r) {

    $amount = (float)$r['liability_amount'];

    $r['computed_due_status'] = $r['return_status'];

    if (
        $r['return_status'] === 'pending'
        && $today > new DateTimeImmutable($r['due_date'])
    ) {
        $r['computed_due_status'] = 'overdue';
    }

    $totalLiability += $amount;

    if ($r['return_status'] === 'paid') {
        $totalPaid += $amount;
    } else {
        $totalOutstanding += $amount;
    }
}

unset($r);

$daysUntilDue = (int)$today->diff($dueDate)->format('%r%a');

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll Remittance — EchoTech POS</title>

<style>
:root{
    --bg:#f4f6f9;
    --card:#fff;
    --text:#17202b;
    --muted:#718096;
    --border:#e1e7ed;
    --blue:#2563eb;
    --green:#059669;
    --orange:#d97706;
    --red:#dc2626;
    --shadow:0 3px 14px rgba(15,23,42,.06);
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
    font-size:14px;
}

.remit-page{
    max-width:1550px;
    margin:0 auto;
    padding:24px;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:18px;
}

.top h1{
    margin:0 0 5px;
    font-size:28px;
}

.top p{
    margin:0;
    color:var(--muted);
}

.toolbar{
    display:flex;
    gap:9px;
    flex-wrap:wrap;
    align-items:center;
}

.control,.btn{
    min-height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    padding:0 12px;
    font:inherit;
}

.btn{
    cursor:pointer;
    font-weight:650;
    text-decoration:none;
    color:var(--text);
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.btn:hover{background:#f8fafc}

.primary{
    background:var(--blue);
    color:#fff;
    border-color:var(--blue);
}

.green{
    background:var(--green);
    color:#fff;
    border-color:var(--green);
}

.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:11px;
    box-shadow:var(--shadow);
}

.alert{
    padding:12px 14px;
    border-radius:8px;
    border:1px solid;
    margin-bottom:15px;
}

.success{
    background:#eaf8f1;
    border-color:#b9e8d1;
    color:#047857;
}

.error{
    background:#fff0f0;
    border-color:#fecaca;
    color:#b91c1c;
}

.info{
    background:#eef4ff;
    border-color:#cbdcff;
    color:#1d4ed8;
}

.summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    padding:17px;
}

.label{
    color:var(--muted);
    font-size:11px;
    font-weight:750;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.value{
    margin-top:7px;
    font-size:23px;
    font-weight:760;
}

.value.blue{color:var(--blue)}
.value.green{color:var(--green)}
.value.red{color:var(--red)}

.panel{
    overflow:hidden;
}

.panel-head{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.panel-head h2{
    margin:0;
    font-size:17px;
}

.panel-head p{
    margin:4px 0 0;
    color:var(--muted);
}

.table-wrap{
    overflow:auto;
}

table{
    width:100%;
    min-width:1250px;
    border-collapse:collapse;
}

th,td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    color:#607083;
    font-size:11px;
    text-transform:uppercase;
    white-space:nowrap;
}

.money{
    text-align:right;
    white-space:nowrap;
}

.type{
    font-weight:800;
}

.subtype{
    color:var(--muted);
    font-size:12px;
    margin-top:3px;
}

.badge{
    display:inline-flex;
    padding:6px 10px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
}

.pending{background:#f1f5f9;color:#64748b}
.submitted{background:#eef4ff;color:#2563eb}
.paid{background:#eaf8f1;color:#047857}
.overdue{background:#fff0f0;color:#b91c1c}

.form-card{
    padding:18px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}

.field{
    display:flex;
    flex-direction:column;
    gap:6px;
}

.field.full{
    grid-column:1/-1;
}

.field label{
    color:#536273;
    font-size:12px;
    font-weight:700;
}

.field input,
.field select,
.field textarea{
    width:100%;
    min-height:40px;
    border:1px solid var(--border);
    border-radius:7px;
    padding:8px 10px;
    font:inherit;
    background:#fff;
}

.field textarea{
    min-height:75px;
    resize:vertical;
}

.modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.42);
    z-index:1000;
    padding:20px;
    align-items:center;
    justify-content:center;
}

.modal.open{
    display:flex;
}

.modal-box{
    width:min(600px,100%);
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 55px rgba(0,0,0,.18);
}

.modal-head{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-head h3{
    margin:0;
}

.close{
    border:0;
    background:none;
    font-size:22px;
    cursor:pointer;
    color:#667587;
}

.modal-body{
    padding:18px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding-top:15px;
}

.footer-total{
    padding:15px 18px;
    display:flex;
    justify-content:space-between;
    color:var(--muted);
}

@media(max-width:950px){
    .top{flex-direction:column}
    .summary{grid-template-columns:repeat(2,minmax(0,1fr))}
}

@media(max-width:650px){
    .remit-page{padding:14px}
    .summary{grid-template-columns:1fr}
    .form-grid{grid-template-columns:1fr}
}

@media print{
    .admin-shell,
    .top .toolbar,
    .alert,
    .panel-head .toolbar,
    .actions{
        display:none!important;
    }

    body{background:#fff}
    .remit-page{padding:0}
    .card{box-shadow:none}
}
</style>
</head>

<body>

<?php
$adminAside = __DIR__ . '/admin_aside.php';
$adminHeader = __DIR__ . '/admin_header.php';

if (is_file($adminAside)) {
    require $adminAside;
}

if (is_file($adminHeader)) {
    require $adminHeader;
}
?>

<main class="remit-page">

    <div class="top">

        <div>
            <h1>Payroll Remittance</h1>
            <p>
                Track statutory returns and payments for
                <strong><?= pr_h($periodLabel) ?></strong>.
            </p>
        </div>

        <div class="toolbar">

            <form method="get" class="toolbar">
                <select class="control" name="month">
                    <?php for ($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                            <?= pr_h(date('F',mktime(0,0,0,$m,1,$year))) ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select class="control" name="year">
                    <?php for ($y=date('Y')-2;$y<=date('Y')+2;$y++): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <button class="btn" type="submit">Load</button>
            </form>

            <a
                class="btn"
                href="payroll_statutory.php?month=<?= $month ?>&year=<?= $year ?>"
            >
                Statutory Payroll
            </a>

            <a
                class="btn"
                href="payroll_approval.php?month=<?= $month ?>&year=<?= $year ?>"
            >
                Approval
            </a>

            <button class="btn" type="button" onclick="window.print()">
                Print
            </button>

        </div>

    </div>

    <?php if ($success !== ''): ?>
        <div class="alert success"><?= pr_h($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= pr_h($error) ?></div>
    <?php endif; ?>

    <div class="alert info">
        <strong>Due date:</strong>
        PAYE, NAPSA and NHIMA are shown with the 10th-of-the-following-month
        remittance date. ZRA publishes PAYE returns/payment as due on the
        10th of the subsequent month; NAPSA and NHIMA also publish the 10th
        as their monthly remittance date.
    </div>

    <section class="summary">

        <div class="card summary-card">
            <div class="label">Total Statutory Liability</div>
            <div class="value blue"><?= pr_money($totalLiability) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Paid</div>
            <div class="value green"><?= pr_money($totalPaid) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Outstanding</div>
            <div class="value red"><?= pr_money($totalOutstanding) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Due Date</div>
            <div class="value" style="font-size:18px">
                <?= pr_h($dueDate->format('d M Y')) ?>
            </div>

            <div style="margin-top:5px;color:var(--muted)">
                <?php if ($daysUntilDue > 0): ?>
                    <?= $daysUntilDue ?> day(s) remaining
                <?php elseif ($daysUntilDue === 0): ?>
                    Due today
                <?php else: ?>
                    <?= abs($daysUntilDue) ?> day(s) overdue
                <?php endif; ?>
            </div>
        </div>

    </section>

    <section class="card panel">

        <div class="panel-head">
            <div>
                <h2>Statutory Returns</h2>
                <p>
                    PAYE, NAPSA and NHIMA liability generated from payroll.
                </p>
            </div>
        </div>

        <div class="table-wrap">

            <table>

                <thead>
                <tr>
                    <th>Statutory</th>
                    <th class="money">Employee</th>
                    <th class="money">Employer</th>
                    <th class="money">Total Liability</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Payment Reference</th>
                    <th class="actions">Action</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$remittances): ?>

                    <tr>
                        <td colspan="8"
                            style="text-align:center;padding:45px;color:var(--muted)">
                            No statutory remittance records are available.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($remittances as $r): ?>

                        <tr>

                            <td>
                                <div class="type">
                                    <?= pr_h($r['statutory_type']) ?>
                                </div>

                                <div class="subtype">
                                    <?= $r['statutory_type'] === 'PAYE'
                                        ? 'ZRA'
                                        : $r['statutory_type'] ?>
                                </div>
                            </td>

                            <td class="money">
                                <?= pr_money((float)$r['employee_amount']) ?>
                            </td>

                            <td class="money">
                                <?= pr_money((float)$r['employer_amount']) ?>
                            </td>

                            <td class="money">
                                <strong>
                                    <?= pr_money((float)$r['liability_amount']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= pr_h(date('d M Y', strtotime($r['due_date']))) ?>
                            </td>

                            <td>
                                <span class="badge <?= pr_h($r['computed_due_status']) ?>">
                                    <?= pr_h($r['computed_due_status']) ?>
                                </span>
                            </td>

                            <td>
                                <?= pr_h(
                                    $r['payment_reference']
                                    ?: $r['return_reference']
                                    ?: '—'
                                ) ?>
                            </td>

                            <td class="actions">

                                <button
                                    class="btn"
                                    type="button"
                                    onclick='openRemittance(<?= json_encode([
                                        "id" => (int)$r["id"],
                                        "type" => $r["statutory_type"],
                                        "status" => $r["return_status"],
                                        "payment_date" => $r["payment_date"] ?? "",
                                        "payment_reference" => $r["payment_reference"] ?? "",
                                        "return_reference" => $r["return_reference"] ?? "",
                                        "notes" => $r["notes"] ?? ""
                                    ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                                >
                                    Update
                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="footer-total">
            <span><?= count($remittances) ?> statutory record(s)</span>

            <span>
                Outstanding:
                <strong style="color:var(--red)">
                    <?= pr_money($totalOutstanding) ?>
                </strong>
            </span>
        </div>

    </section>

</main>

<div class="modal" id="remittanceModal">

    <div class="modal-box">

        <div class="modal-head">
            <h3 id="modalTitle">Update Remittance</h3>

            <button
                class="close"
                type="button"
                onclick="closeRemittance()"
                aria-label="Close"
            >
                ×
            </button>
        </div>

        <form method="post">

            <div class="modal-body">

                <input type="hidden" name="action" value="save_remittance">
                <input type="hidden" id="remittanceId" name="remittance_id">
                <input type="hidden" name="month" value="<?= $month ?>">
                <input type="hidden" name="year" value="<?= $year ?>">

                <div class="form-grid">

                    <div class="field">
                        <label>Statutory</label>
                        <input id="modalType" type="text" readonly>
                    </div>

                    <div class="field">
                        <label>Status</label>

                        <select id="modalStatus" name="return_status">
                            <option value="pending">Pending</option>
                            <option value="submitted">Submitted</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Payment Date</label>
                        <input id="modalPaymentDate" name="payment_date" type="date">
                    </div>

                    <div class="field">
                        <label>Payment Reference</label>
                        <input
                            id="modalPaymentReference"
                            name="payment_reference"
                            type="text"
                            maxlength="150"
                            placeholder="e.g. bank / portal reference"
                        >
                    </div>

                    <div class="field full">
                        <label>Return / Filing Reference</label>
                        <input
                            id="modalReturnReference"
                            name="return_reference"
                            type="text"
                            maxlength="150"
                            placeholder="e.g. ZRA return reference"
                        >
                    </div>

                    <div class="field full">
                        <label>Notes</label>
                        <textarea
                            id="modalNotes"
                            name="notes"
                            placeholder="Add filing or payment notes..."
                        ></textarea>
                    </div>

                </div>

                <div class="modal-actions">

                    <button
                        class="btn"
                        type="button"
                        onclick="closeRemittance()"
                    >
                        Cancel
                    </button>

                    <button class="btn green" type="submit">
                        Save Remittance
                    </button>

                </div>

            </div>

        </form>

    </div>

</div>

<script>
function openRemittance(data){

    document.getElementById('remittanceId').value =
        data.id || '';

    document.getElementById('modalType').value =
        data.type || '';

    document.getElementById('modalStatus').value =
        data.status || 'pending';

    document.getElementById('modalPaymentDate').value =
        data.payment_date || '';

    document.getElementById('modalPaymentReference').value =
        data.payment_reference || '';

    document.getElementById('modalReturnReference').value =
        data.return_reference || '';

    document.getElementById('modalNotes').value =
        data.notes || '';

    document.getElementById('modalTitle').textContent =
        'Update ' + (data.type || '') + ' Remittance';

    document.getElementById('remittanceModal').classList.add('open');
}

function closeRemittance(){
    document.getElementById('remittanceModal').classList.remove('open');
}

document.getElementById('remittanceModal').addEventListener('click', function(e){
    if(e.target === this){
        closeRemittance();
    }
});

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        closeRemittance();
    }
});
</script>

</body>
</html>
