<?php
/**
 * ============================================================
 * EchoTech POS - Payroll Approval & Payslips
 * ============================================================
 *
 * Location:
 *   /admin/actions/payroll_approval.php
 *
 * Phase 3:
 *   - Review calculated payroll
 *   - Approve payroll
 *   - Mark payroll as paid
 *   - Reopen payroll when authorised
 *   - View / print individual payslips
 *   - Print the complete payroll register
 *
 * Uses the same payroll_records table created by Phase 2.
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

function pa_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pa_money(float $value): string
{
    return 'K' . number_format($value, 2);
}

function pa_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pa_column_exists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pa_first_column(
    mysqli $conn,
    string $table,
    array $columns
): ?string {
    foreach ($columns as $column) {
        if (pa_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function pa_staff_table(mysqli $conn): ?string
{
    foreach (['staff', 'users', 'employees', 'profiles'] as $table) {
        if (pa_table_exists($conn, $table)) {
            return $table;
        }
    }

    return null;
}

function pa_staff_info(
    mysqli $conn,
    string $staffTable,
    int $staffId
): array {
    $idCol = pa_first_column($conn, $staffTable, [
        'id',
        'staff_id',
        'user_id',
        'employee_id',
    ]);

    $nameCol = pa_first_column($conn, $staffTable, [
        'full_name',
        'name',
        'staff_name',
        'employee_name',
        'username',
    ]);

    $roleCol = pa_first_column($conn, $staffTable, [
        'role',
        'user_role',
        'position',
    ]);

    $branchCol = pa_first_column($conn, $staffTable, [
        'branch_id',
    ]);

    $emailCol = pa_first_column($conn, $staffTable, [
        'email',
        'email_address',
    ]);

    if ($idCol === null || $nameCol === null) {
        return [];
    }

    $select = [
        "s.`{$idCol}` AS staff_id",
        "s.`{$nameCol}` AS staff_name",
        $roleCol
            ? "s.`{$roleCol}` AS staff_role"
            : "'Staff' AS staff_role",
        $branchCol
            ? "s.`{$branchCol}` AS branch_id"
            : "NULL AS branch_id",
        $emailCol
            ? "s.`{$emailCol}` AS staff_email"
            : "'' AS staff_email",
    ];

    $stmt = $conn->prepare(
        "SELECT " . implode(', ', $select) .
        " FROM `{$staffTable}` s
          WHERE s.`{$idCol}` = ?
          LIMIT 1"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $staffId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: [];

    $stmt->close();

    return $row;
}

function pa_pharmacy_info(mysqli $conn): array
{
    if (!pa_table_exists($conn, 'pharmacies')) {
        return [
            'name' => 'PHARMANOVA',
            'address' => '',
            'phone' => '',
            'email' => '',
        ];
    }

    $nameCol = pa_first_column($conn, 'pharmacies', [
        'pharmacy_name',
        'name',
        'business_name',
    ]);

    $addressCol = pa_first_column($conn, 'pharmacies', [
        'address',
        'physical_address',
        'location',
    ]);

    $phoneCol = pa_first_column($conn, 'pharmacies', [
        'phone',
        'phone_number',
        'contact_number',
    ]);

    $emailCol = pa_first_column($conn, 'pharmacies', [
        'email',
        'email_address',
    ]);

    $select = [
        $nameCol ? "`{$nameCol}` AS name" : "'PHARMANOVA' AS name",
        $addressCol ? "`{$addressCol}` AS address" : "'' AS address",
        $phoneCol ? "`{$phoneCol}` AS phone" : "'' AS phone",
        $emailCol ? "`{$emailCol}` AS email" : "'' AS email",
    ];

    $where = '';

    if (pa_column_exists($conn, 'pharmacies', 'id')
        && isset($_SESSION['pharmacy_id'])
        && (int)$_SESSION['pharmacy_id'] > 0) {
        $where = ' WHERE `id` = ' . (int)$_SESSION['pharmacy_id'];
    }

    $result = $conn->query(
        "SELECT " . implode(', ', $select) .
        " FROM `pharmacies`{$where} LIMIT 1"
    );

    if ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        return $row;
    }

    return [
        'name' => 'PHARMANOVA',
        'address' => '',
        'phone' => '',
        'email' => '',
    ];
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
$periodLabel = date('F Y', strtotime($period . '-01'));

$action = (string)($_POST['action'] ?? '');
$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Ensure Phase 2 table exists
|--------------------------------------------------------------------------
*/

if (!pa_table_exists($conn, 'payroll_records')) {
    $error = 'Payroll has not been prepared yet. Open Payroll Processing first.';
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {

    if ($action === 'approve_payroll') {

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET status = 'approved'
            WHERE payroll_period = ?
              AND status = 'calculated'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $period);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success = 'Payroll approved for ' . $periodLabel . '.';
            } else {
                $error = 'No calculated payroll rows were available for approval.';
            }

            $stmt->close();
        } else {
            $error = 'Could not approve payroll: ' . $conn->error;
        }
    }

    if ($action === 'mark_paid') {

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET status = 'paid'
            WHERE payroll_period = ?
              AND status = 'approved'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $period);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success = 'Payroll for ' . $periodLabel . ' has been marked as paid.';
            } else {
                $error = 'Payroll must be approved before it can be marked as paid.';
            }

            $stmt->close();
        } else {
            $error = 'Could not mark payroll as paid: ' . $conn->error;
        }
    }

    if ($action === 'reopen_payroll') {

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET status = 'calculated'
            WHERE payroll_period = ?
              AND status = 'approved'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $period);
            $stmt->execute();

            $success = 'Approved payroll has been reopened for review.';
            $stmt->close();
        } else {
            $error = 'Could not reopen payroll: ' . $conn->error;
        }
    }

    if ($action === 'reset_paid') {

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET status = 'approved'
            WHERE payroll_period = ?
              AND status = 'paid'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $period);
            $stmt->execute();

            $success = 'Paid status has been reversed to approved.';
            $stmt->close();
        } else {
            $error = 'Could not reverse paid status: ' . $conn->error;
        }
    }

    header(
        'Location: payroll_approval.php?month=' .
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
    $success = 'Payroll updated successfully.';
}

if (isset($_GET['error']) && $error === '') {
    $error = (string)$_GET['error'];
}

/*
|--------------------------------------------------------------------------
| Load payroll register
|--------------------------------------------------------------------------
*/

$rows = [];
$totals = [
    'basic' => 0,
    'gross' => 0,
    'deductions' => 0,
    'net' => 0,
];

if ($error === '') {

    $stmt = $conn->prepare("
        SELECT
            id,
            staff_id,
            basic_salary,
            allowances,
            bonus,
            overtime,
            other_earnings,
            paye,
            napsa,
            nhima,
            loan_deduction,
            salary_advance,
            other_deductions,
            gross_salary,
            total_deductions,
            net_salary,
            status
        FROM payroll_records
        WHERE payroll_period = ?
        ORDER BY staff_id ASC
    ");

    if ($stmt) {

        $stmt->bind_param('s', $period);
        $stmt->execute();

        $result = $stmt->get_result();
        $staffTable = pa_staff_table($conn);

        while ($payroll = $result->fetch_assoc()) {

            $staff = [];

            if ($staffTable !== null) {
                $staff = pa_staff_info(
                    $conn,
                    $staffTable,
                    (int)$payroll['staff_id']
                );
            }

            $row = array_merge([
                'staff_id' => (int)$payroll['staff_id'],
                'staff_name' => 'Staff #' . (int)$payroll['staff_id'],
                'staff_role' => 'Staff',
                'staff_email' => '',
                'branch_id' => null,
            ], $staff, [
                'payroll_id' => (int)$payroll['id'],
                'basic_salary' => (float)$payroll['basic_salary'],
                'allowances' => (float)$payroll['allowances'],
                'bonus' => (float)$payroll['bonus'],
                'overtime' => (float)$payroll['overtime'],
                'other_earnings' => (float)$payroll['other_earnings'],
                'paye' => (float)$payroll['paye'],
                'napsa' => (float)$payroll['napsa'],
                'nhima' => (float)$payroll['nhima'],
                'loan_deduction' => (float)$payroll['loan_deduction'],
                'salary_advance' => (float)$payroll['salary_advance'],
                'other_deductions' => (float)$payroll['other_deductions'],
                'gross_salary' => (float)$payroll['gross_salary'],
                'total_deductions' => (float)$payroll['total_deductions'],
                'net_salary' => (float)$payroll['net_salary'],
                'status' => (string)$payroll['status'],
            ]);

            $rows[] = $row;

            $totals['basic'] += $row['basic_salary'];
            $totals['gross'] += $row['gross_salary'];
            $totals['deductions'] += $row['total_deductions'];
            $totals['net'] += $row['net_salary'];
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Determine period status
|--------------------------------------------------------------------------
*/

$periodStatus = 'draft';

foreach ($rows as $row) {
    $status = $row['status'];

    if ($status === 'paid') {
        $periodStatus = 'paid';
        break;
    }

    if ($status === 'approved') {
        $periodStatus = 'approved';
        continue;
    }

    if ($status === 'calculated') {
        $periodStatus = 'calculated';
    }
}

$pharmacy = pa_pharmacy_info($conn);

$printStaffId = (int)($_GET['payslip'] ?? 0);
$payslip = null;

if ($printStaffId > 0) {
    foreach ($rows as $row) {
        if ((int)$row['staff_id'] === $printStaffId) {
            $payslip = $row;
            break;
        }
    }
}

if ($payslip !== null):
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payslip — <?= pa_h($payslip['staff_name']) ?></title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f2f4f7;
    color:#17202b;
    font-family:Inter,Arial,sans-serif;
    font-size:14px;
}
.print-actions{
    max-width:800px;
    margin:20px auto 0;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}
.btn{
    border:1px solid #dce2e8;
    background:#fff;
    padding:9px 14px;
    border-radius:7px;
    cursor:pointer;
    font-weight:650;
}
.payslip{
    width:min(800px,calc(100% - 30px));
    margin:15px auto 30px;
    background:#fff;
    border:1px solid #dfe5eb;
    box-shadow:0 4px 18px rgba(15,23,42,.07);
    padding:34px;
}
.company{
    text-align:center;
    padding-bottom:20px;
    border-bottom:2px solid #17202b;
}
.company h1{
    margin:0;
    font-size:24px;
}
.company p{
    margin:4px 0;
    color:#697789;
}
.payroll-heading{
    display:flex;
    justify-content:space-between;
    margin:22px 0;
    gap:20px;
}
.payroll-heading h2{
    margin:0 0 5px;
    font-size:21px;
}
.meta{
    color:#667587;
}
.employee{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px 25px;
    padding:15px;
    background:#f8fafc;
    border:1px solid #e3e8ed;
    border-radius:8px;
}
.employee div{
    display:flex;
    justify-content:space-between;
    gap:10px;
}
.employee span:first-child{color:#667587}
.employee strong{text-align:right}
.section{
    margin-top:22px;
}
.section h3{
    font-size:14px;
    margin:0 0 8px;
    padding-bottom:7px;
    border-bottom:1px solid #e3e8ed;
}
.line{
    display:flex;
    justify-content:space-between;
    padding:6px 0;
}
.total{
    border-top:1px solid #cfd6de;
    margin-top:5px;
    padding-top:10px;
    font-weight:750;
}
.netpay{
    margin-top:22px;
    padding:16px;
    background:#eef8f3;
    border:1px solid #bde6d2;
    border-radius:8px;
    display:flex;
    justify-content:space-between;
    font-size:19px;
    color:#047857;
}
.footer{
    margin-top:28px;
    padding-top:15px;
    border-top:1px solid #e3e8ed;
    color:#778596;
    font-size:12px;
    text-align:center;
}
@media(max-width:600px){
    .payslip{padding:20px}
    .employee{grid-template-columns:1fr}
}
@media print{
    body{background:#fff}
    .print-actions{display:none}
    .payslip{
        width:100%;
        margin:0;
        border:0;
        box-shadow:none;
    }
}
</style>
</head>
<body>

<div class="print-actions">
    <button class="btn" onclick="history.back()">Back</button>
    <button class="btn" onclick="window.print()">Print Payslip</button>
</div>

<section class="payslip">

    <header class="company">
        <h1><?= pa_h($pharmacy['name']) ?></h1>
        <?php if ($pharmacy['address'] !== ''): ?>
            <p><?= pa_h($pharmacy['address']) ?></p>
        <?php endif; ?>
        <?php if ($pharmacy['phone'] !== ''): ?>
            <p><?= pa_h($pharmacy['phone']) ?></p>
        <?php endif; ?>
        <?php if ($pharmacy['email'] !== ''): ?>
            <p><?= pa_h($pharmacy['email']) ?></p>
        <?php endif; ?>
    </header>

    <div class="payroll-heading">
        <div>
            <h2>Employee Payslip</h2>
            <div class="meta"><?= pa_h($periodLabel) ?></div>
        </div>

        <div style="text-align:right">
            <strong><?= pa_h(strtoupper($payslip['status'])) ?></strong><br>
            <span class="meta">
                Payroll #<?= (int)$payslip['payroll_id'] ?>
            </span>
        </div>
    </div>

    <div class="employee">
        <div>
            <span>Employee</span>
            <strong><?= pa_h($payslip['staff_name']) ?></strong>
        </div>

        <div>
            <span>Role</span>
            <strong><?= pa_h($payslip['staff_role']) ?></strong>
        </div>

        <div>
            <span>Employee ID</span>
            <strong><?= (int)$payslip['staff_id'] ?></strong>
        </div>

        <div>
            <span>Email</span>
            <strong><?= pa_h($payslip['staff_email'] ?: '—') ?></strong>
        </div>
    </div>

    <section class="section">
        <h3>Earnings</h3>

        <div class="line">
            <span>Basic Salary</span>
            <strong><?= pa_money($payslip['basic_salary']) ?></strong>
        </div>

        <div class="line">
            <span>Allowances</span>
            <strong><?= pa_money($payslip['allowances']) ?></strong>
        </div>

        <div class="line">
            <span>Bonus</span>
            <strong><?= pa_money($payslip['bonus']) ?></strong>
        </div>

        <div class="line">
            <span>Overtime</span>
            <strong><?= pa_money($payslip['overtime']) ?></strong>
        </div>

        <div class="line">
            <span>Other Earnings</span>
            <strong><?= pa_money($payslip['other_earnings']) ?></strong>
        </div>

        <div class="line total">
            <span>Gross Salary</span>
            <strong><?= pa_money($payslip['gross_salary']) ?></strong>
        </div>
    </section>

    <section class="section">
        <h3>Deductions</h3>

        <div class="line">
            <span>PAYE</span>
            <strong><?= pa_money($payslip['paye']) ?></strong>
        </div>

        <div class="line">
            <span>NAPSA</span>
            <strong><?= pa_money($payslip['napsa']) ?></strong>
        </div>

        <div class="line">
            <span>NHIMA</span>
            <strong><?= pa_money($payslip['nhima']) ?></strong>
        </div>

        <div class="line">
            <span>Loan Deduction</span>
            <strong><?= pa_money($payslip['loan_deduction']) ?></strong>
        </div>

        <div class="line">
            <span>Salary Advance</span>
            <strong><?= pa_money($payslip['salary_advance']) ?></strong>
        </div>

        <div class="line">
            <span>Other Deductions</span>
            <strong><?= pa_money($payslip['other_deductions']) ?></strong>
        </div>

        <div class="line total">
            <span>Total Deductions</span>
            <strong><?= pa_money($payslip['total_deductions']) ?></strong>
        </div>
    </section>

    <div class="netpay">
        <span>NET PAY</span>
        <strong><?= pa_money($payslip['net_salary']) ?></strong>
    </div>

    <div class="footer">
        This payslip was generated by EchoTech POS.
        Please retain it for your records.
    </div>

</section>

</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payroll Approval — EchoTech POS</title>

<style>
:root{
    --bg:#f4f6f9;
    --card:#fff;
    --text:#17202b;
    --muted:#718096;
    --border:#e2e8f0;
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

.approval-page{
    max-width:1600px;
    margin:0 auto;
    padding:24px;
}

.page-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:18px;
}

.page-top h1{
    margin:0 0 5px;
    font-size:28px;
}

.page-top p{
    margin:0;
    color:var(--muted);
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:9px;
    align-items:center;
}

.control,
.btn{
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
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    color:var(--text);
}

.btn:hover{background:#f8fafc}

.btn-primary{
    background:var(--blue);
    border-color:var(--blue);
    color:#fff;
}

.btn-success{
    background:var(--green);
    border-color:var(--green);
    color:#fff;
}

.btn-warning{
    color:var(--orange);
    border-color:#fed7aa;
}

.btn-danger{
    color:var(--red);
    border-color:#fecaca;
}

.alert{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:15px;
    border:1px solid;
}

.success{
    background:#eaf8f1;
    color:#047857;
    border-color:#b9e8d1;
}

.error{
    background:#fff0f0;
    color:#b91c1c;
    border-color:#fecaca;
}

.summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:11px;
    box-shadow:var(--shadow);
}

.summary-card{
    padding:17px;
}

.label{
    color:var(--muted);
    text-transform:uppercase;
    font-size:11px;
    font-weight:750;
    letter-spacing:.04em;
}

.value{
    margin-top:7px;
    font-size:23px;
    font-weight:760;
}

.value.blue{color:var(--blue)}
.value.orange{color:var(--orange)}
.value.green{color:var(--green)}

.status-box{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:15px 17px;
    margin-bottom:18px;
}

.status-title{
    font-weight:750;
}

.badge{
    display:inline-flex;
    padding:6px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:750;
    text-transform:uppercase;
}

.badge-draft{background:#f1f5f9;color:#64748b}
.badge-calculated{background:#eef4ff;color:#2563eb}
.badge-approved{background:#fff7e8;color:#b45309}
.badge-paid{background:#eaf8f1;color:#047857}

.panel{
    overflow:hidden;
}

.panel-head{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
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

.search{
    width:260px;
    height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:0 12px;
    font:inherit;
}

.table-wrap{
    overflow:auto;
}

table{
    width:100%;
    min-width:1100px;
    border-collapse:collapse;
}

th,td{
    padding:11px 12px;
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

td{
    font-size:13px;
}

.money{
    text-align:right;
    white-space:nowrap;
}

.staff{
    font-weight:700;
}

.role{
    color:var(--muted);
    font-size:12px;
    margin-top:2px;
}

.net{
    color:var(--green);
    font-weight:760;
}

.actions{
    white-space:nowrap;
}

.bottom{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    padding:14px 18px;
    color:var(--muted);
}

.total-net{
    color:var(--green);
    font-weight:800;
}

.empty{
    text-align:center;
    padding:45px 15px!important;
    color:var(--muted);
}

@media(max-width:950px){
    .summary{grid-template-columns:repeat(2,minmax(0,1fr))}
    .page-top{flex-direction:column}
}

@media(max-width:600px){
    .approval-page{padding:14px}
    .summary{grid-template-columns:1fr}
    .search{width:100%}
    .panel-head{align-items:stretch;flex-direction:column}
}

@media print{
    .admin-shell,
    .page-top,
    .status-box,
    .summary,
    .panel-head .toolbar,
    .actions,
    .bottom{
        display:none!important;
    }

    body{background:#fff}
    .approval-page{padding:0}
    .card{box-shadow:none;border:0}
    table{min-width:0}
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

<main class="approval-page">

    <div class="page-top">
        <div>
            <h1>Payroll Approval</h1>
            <p>Review, approve, pay and issue payslips for <?= pa_h($periodLabel) ?>.</p>
        </div>

        <form method="get" class="toolbar">
            <select class="control" name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                        <?= pa_h(date('F', mktime(0,0,0,$m,1,$year))) ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select class="control" name="year">
                <?php for ($y = date('Y') - 3; $y <= date('Y') + 2; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                        <?= $y ?>
                    </option>
                <?php endfor; ?>
            </select>

            <button class="btn btn-primary" type="submit">Load</button>
        </form>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert success"><?= pa_h($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= pa_h($error) ?></div>
    <?php endif; ?>

    <section class="summary">

        <div class="card summary-card">
            <div class="label">Employees</div>
            <div class="value"><?= count($rows) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Gross Payroll</div>
            <div class="value blue"><?= pa_money($totals['gross']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Deductions</div>
            <div class="value orange"><?= pa_money($totals['deductions']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Net Payroll</div>
            <div class="value green"><?= pa_money($totals['net']) ?></div>
        </div>

    </section>

    <section class="card status-box">

        <div>
            <div class="status-title">
                Payroll Period: <?= pa_h($periodLabel) ?>
            </div>
            <div style="color:var(--muted);margin-top:3px">
                Basic salary: <?= pa_money($totals['basic']) ?>
            </div>
        </div>

        <span class="badge badge-<?= pa_h($periodStatus) ?>">
            <?= pa_h($periodStatus) ?>
        </span>

        <div class="toolbar">

            <?php if ($periodStatus === 'calculated'): ?>

                <form method="post">
                    <input type="hidden" name="action" value="approve_payroll">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">

                    <button
                        class="btn btn-success"
                        type="submit"
                        onclick="return confirm('Approve payroll for <?= pa_h($periodLabel) ?>?');"
                    >
                        Approve Payroll
                    </button>
                </form>

            <?php elseif ($periodStatus === 'approved'): ?>

                <form method="post">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">

                    <button
                        class="btn btn-success"
                        type="submit"
                        onclick="return confirm('Mark payroll for <?= pa_h($periodLabel) ?> as PAID?');"
                    >
                        Mark as Paid
                    </button>
                </form>

                <form method="post">
                    <input type="hidden" name="action" value="reopen_payroll">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">

                    <button class="btn btn-warning" type="submit">
                        Reopen
                    </button>
                </form>

            <?php elseif ($periodStatus === 'paid'): ?>

                <form method="post">
                    <input type="hidden" name="action" value="reset_paid">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">

                    <button
                        class="btn btn-warning"
                        type="submit"
                        onclick="return confirm('Reverse the paid status? Only do this if the payment was recorded incorrectly.');"
                    >
                        Reverse Paid
                    </button>
                </form>

            <?php endif; ?>

            <a
                class="btn"
                href="payroll_process.php?month=<?= $month ?>&year=<?= $year ?>"
            >
                Payroll Processing
            </a>

            <button class="btn" type="button" onclick="window.print()">
                Print Register
            </button>

        </div>
    </section>

    <section class="card">

        <div class="panel-head">
            <div>
                <h2>Employee Payroll Register</h2>
                <p>Open any employee to view and print a payslip.</p>
            </div>

            <input
                id="staffSearch"
                class="search"
                type="search"
                placeholder="Search employee..."
                autocomplete="off"
            >
        </div>

        <div class="table-wrap">

            <table id="payrollTable">

                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="money">Basic</th>
                    <th class="money">Gross</th>
                    <th class="money">Deductions</th>
                    <th class="money">Net Pay</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$rows): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No payroll records exist for <?= pa_h($periodLabel) ?>.
                            Prepare the payroll first.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($rows as $row): ?>

                        <tr
                            data-search="<?= pa_h(
                                strtolower(
                                    $row['staff_name'] . ' ' .
                                    $row['staff_role']
                                )
                            ) ?>"
                        >

                            <td>
                                <div class="staff">
                                    <?= pa_h($row['staff_name']) ?>
                                </div>

                                <div class="role">
                                    ID #<?= (int)$row['staff_id'] ?>
                                </div>
                            </td>

                            <td><?= pa_h($row['staff_role']) ?></td>

                            <td>
                                <span class="badge badge-<?= pa_h($row['status']) ?>">
                                    <?= pa_h($row['status']) ?>
                                </span>
                            </td>

                            <td class="money">
                                <?= pa_money($row['basic_salary']) ?>
                            </td>

                            <td class="money">
                                <?= pa_money($row['gross_salary']) ?>
                            </td>

                            <td class="money">
                                <?= pa_money($row['total_deductions']) ?>
                            </td>

                            <td class="money net">
                                <?= pa_money($row['net_salary']) ?>
                            </td>

                            <td class="actions">

                                <a
                                    class="btn"
                                    href="payroll_approval.php?month=<?= $month ?>&year=<?= $year ?>&payslip=<?= (int)$row['staff_id'] ?>"
                                >
                                    Payslip
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="bottom">

            <div>
                <?= count($rows) ?> employee(s)
            </div>

            <div>
                Net payroll:
                <strong class="total-net">
                    <?= pa_money($totals['net']) ?>
                </strong>
            </div>

        </div>

    </section>

</main>

<script>
(function(){
    const input = document.getElementById('staffSearch');
    const rows = document.querySelectorAll('#payrollTable tbody tr[data-search]');

    if (!input) return;

    input.addEventListener('input', function(){
        const term = this.value.trim().toLowerCase();

        rows.forEach(function(row){
            const value = row.getAttribute('data-search') || '';
            row.style.display =
                !term || value.includes(term) ? '' : 'none';
        });
    });
})();
</script>

</body>
</html>
