<?php
/**
 * ============================================================
 * EchoTech POS - Payroll Processing
 * ============================================================
 *
 * Location:
 *   /admin/actions/payroll_process.php
 *
 * Phase 2:
 *   - Payroll period
 *   - Staff register
 *   - Earnings
 *   - Deductions
 *   - Gross / net calculations
 *   - Save payroll
 *   - Lock payroll
 *   - Print register
 *
 * This page deliberately uses the independent Admin shell:
 *   /admin/actions/admin_header.php
 *   /admin/actions/admin_aside.php
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

function pp_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pp_money(float $value): string
{
    return 'K' . number_format($value, 2);
}

function pp_post_money(string $key): float
{
    $value = $_POST[$key] ?? 0;
    return max(0, (float)$value);
}

function pp_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pp_column_exists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function pp_first_existing_column(
    mysqli $conn,
    string $table,
    array $columns
): ?string {
    foreach ($columns as $column) {
        if (pp_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function pp_current_pharmacy(): ?int
{
    $value = $_SESSION['pharmacy_id'] ?? null;
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

function pp_current_branch(): ?int
{
    $value = $_SESSION['branch_id'] ?? null;
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

/*
|--------------------------------------------------------------------------
| Payroll period
|--------------------------------------------------------------------------
*/

$periodMonth = (int)($_GET['month'] ?? $_POST['month'] ?? date('n'));
$periodYear  = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));

if ($periodMonth < 1 || $periodMonth > 12) {
    $periodMonth = (int)date('n');
}

if ($periodYear < 2000 || $periodYear > 2200) {
    $periodYear = (int)date('Y');
}

$periodKey = sprintf('%04d-%02d', $periodYear, $periodMonth);
$periodLabel = date('F Y', strtotime($periodKey . '-01'));

$successMessage = '';
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Phase-2 payroll storage
|--------------------------------------------------------------------------
|
| The processing table is created automatically if it does not exist.
| This avoids requiring a manual SQL migration just to start Phase 2.
|
*/

$payrollTable = 'payroll_records';

try {
    if (!pp_table_exists($conn, $payrollTable)) {
        $createSql = "
            CREATE TABLE `payroll_records` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `payroll_period` CHAR(7) NOT NULL,
                `staff_id` BIGINT UNSIGNED NOT NULL,
                `basic_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `allowances` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `bonus` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `overtime` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `other_earnings` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `paye` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `napsa` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `nhima` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `loan_deduction` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `salary_advance` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `other_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `gross_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `total_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `net_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_payroll_period_staff` (`payroll_period`, `staff_id`),
                KEY `idx_payroll_period` (`payroll_period`),
                KEY `idx_payroll_staff` (`staff_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($createSql)) {
            throw new RuntimeException('Unable to create payroll_records table: ' . $conn->error);
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Discover staff table
|--------------------------------------------------------------------------
*/

$staffTableCandidates = [
    'staff',
    'users',
    'employees',
    'profiles',
];

$staffTable = null;

foreach ($staffTableCandidates as $candidate) {
    if (pp_table_exists($conn, $candidate)) {
        $staffTable = $candidate;
        break;
    }
}

$staffRows = [];

if ($staffTable !== null) {
    $idCol = pp_first_existing_column($conn, $staffTable, [
        'id',
        'staff_id',
        'user_id',
        'employee_id',
    ]);

    $nameCol = pp_first_existing_column($conn, $staffTable, [
        'full_name',
        'name',
        'staff_name',
        'employee_name',
        'username',
    ]);

    $roleCol = pp_first_existing_column($conn, $staffTable, [
        'role',
        'user_role',
        'position',
    ]);

    $salaryCol = pp_first_existing_column($conn, $staffTable, [
        'basic_salary',
        'salary',
        'monthly_salary',
    ]);

    $branchCol = pp_first_existing_column($conn, $staffTable, [
        'branch_id',
    ]);

    $statusCol = pp_first_existing_column($conn, $staffTable, [
        'status',
        'account_status',
        'is_active',
    ]);

    if ($idCol !== null && $nameCol !== null) {
        $select = [
            "s.`{$idCol}` AS staff_id",
            "s.`{$nameCol}` AS staff_name",
        ];

        $select[] = $roleCol
            ? "s.`{$roleCol}` AS staff_role"
            : "'Staff' AS staff_role";

        $select[] = $salaryCol
            ? "COALESCE(s.`{$salaryCol}`,0) AS basic_salary"
            : "0 AS basic_salary";

        $select[] = $branchCol
            ? "s.`{$branchCol}` AS branch_id"
            : "NULL AS branch_id";

        $select[] = $statusCol
            ? "s.`{$statusCol}` AS staff_status"
            : "'Active' AS staff_status";

        $where = [];

        if ($statusCol) {
            $where[] = "(s.`{$statusCol}` = 1 OR LOWER(CAST(s.`{$statusCol}` AS CHAR)) IN ('active','enabled','1'))";
        }

        $pharmacyCol = pp_first_existing_column($conn, $staffTable, [
            'pharmacy_id',
        ]);

        if ($pharmacyCol !== null && pp_current_pharmacy() !== null) {
            $where[] = "s.`{$pharmacyCol}` = " . (int)pp_current_pharmacy();
        }

        $sql = "SELECT " . implode(', ', $select) . " FROM `{$staffTable}` s";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY s.`{$nameCol}` ASC";

        $result = $conn->query($sql);

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $staffRows[] = $row;
            }
            $result->free();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Existing payroll records
|--------------------------------------------------------------------------
*/

$existing = [];

if ($errorMessage === '') {
    $stmt = $conn->prepare("
        SELECT *
        FROM `payroll_records`
        WHERE `payroll_period` = ?
    ");

    if ($stmt) {
        $stmt->bind_param('s', $periodKey);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $existing[(string)$row['staff_id']] = $row;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_row') {
        $staffId = (int)($_POST['staff_id'] ?? 0);

        if ($staffId <= 0) {
            $errorMessage = 'Invalid staff member.';
        } else {
            $basic = pp_post_money('basic_salary');
            $allowances = pp_post_money('allowances');
            $bonus = pp_post_money('bonus');
            $overtime = pp_post_money('overtime');
            $otherEarnings = pp_post_money('other_earnings');

            $paye = pp_post_money('paye');
            $napsa = pp_post_money('napsa');
            $nhima = pp_post_money('nhima');
            $loan = pp_post_money('loan_deduction');
            $advance = pp_post_money('salary_advance');
            $otherDeductions = pp_post_money('other_deductions');

            $gross = $basic + $allowances + $bonus + $overtime + $otherEarnings;
            $deductions = $paye + $napsa + $nhima + $loan + $advance + $otherDeductions;
            $net = max(0, $gross - $deductions);

            $status = 'calculated';

            $stmt = $conn->prepare("
                INSERT INTO `payroll_records`
                (
                    payroll_period, staff_id,
                    basic_salary, allowances, bonus, overtime, other_earnings,
                    paye, napsa, nhima, loan_deduction, salary_advance, other_deductions,
                    gross_salary, total_deductions, net_salary, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    basic_salary = VALUES(basic_salary),
                    allowances = VALUES(allowances),
                    bonus = VALUES(bonus),
                    overtime = VALUES(overtime),
                    other_earnings = VALUES(other_earnings),
                    paye = VALUES(paye),
                    napsa = VALUES(napsa),
                    nhima = VALUES(nhima),
                    loan_deduction = VALUES(loan_deduction),
                    salary_advance = VALUES(salary_advance),
                    other_deductions = VALUES(other_deductions),
                    gross_salary = VALUES(gross_salary),
                    total_deductions = VALUES(total_deductions),
                    net_salary = VALUES(net_salary),
                    status = VALUES(status)
            ");

            if (!$stmt) {
                $errorMessage = 'Could not prepare payroll save: ' . $conn->error;
            } else {
                $stmt->bind_param(
                    'sidddddddddddddds',
                    $periodKey,
                    $staffId,
                    $basic,
                    $allowances,
                    $bonus,
                    $overtime,
                    $otherEarnings,
                    $paye,
                    $napsa,
                    $nhima,
                    $loan,
                    $advance,
                    $otherDeductions,
                    $gross,
                    $deductions,
                    $net,
                    $status
                );

                if ($stmt->execute()) {
                    $successMessage = 'Payroll row saved successfully.';
                } else {
                    $errorMessage = 'Payroll could not be saved: ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    }

    if ($action === 'lock_payroll') {
        $stmt = $conn->prepare("
            UPDATE `payroll_records`
            SET `status` = 'locked'
            WHERE `payroll_period` = ?
        ");

        if ($stmt) {
            $stmt->bind_param('s', $periodKey);
            $stmt->execute();

            if ($stmt->affected_rows >= 0) {
                $successMessage = 'Payroll for ' . $periodLabel . ' has been locked.';
            }

            $stmt->close();
        } else {
            $errorMessage = 'Could not lock payroll: ' . $conn->error;
        }
    }

    if ($action === 'reopen_payroll') {
        $stmt = $conn->prepare("
            UPDATE `payroll_records`
            SET `status` = 'calculated'
            WHERE `payroll_period` = ?
              AND `status` = 'locked'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $periodKey);
            $stmt->execute();
            $successMessage = 'Payroll has been reopened for editing.';
            $stmt->close();
        }
    }

    if ($action === 'calculate_all') {
        /*
         * Phase 2 intentionally does not invent tax rules.
         * Existing deduction values are preserved; gross and net are
         * recalculated from the values already stored in the register.
         */
        $stmt = $conn->prepare("
            UPDATE `payroll_records`
            SET
                gross_salary =
                    basic_salary + allowances + bonus + overtime + other_earnings,
                total_deductions =
                    paye + napsa + nhima + loan_deduction + salary_advance + other_deductions,
                net_salary = GREATEST(
                    0,
                    (
                        basic_salary + allowances + bonus + overtime + other_earnings
                    ) -
                    (
                        paye + napsa + nhima + loan_deduction + salary_advance + other_deductions
                    )
                ),
                status = 'calculated'
            WHERE payroll_period = ?
              AND status <> 'locked'
        ");

        if ($stmt) {
            $stmt->bind_param('s', $periodKey);
            $stmt->execute();
            $successMessage = 'Payroll calculations updated.';
            $stmt->close();
        }
    }

    header(
        'Location: payroll_process.php?month='
        . $periodMonth
        . '&year='
        . $periodYear
        . ($successMessage !== ''
            ? '&saved=1'
            : ($errorMessage !== '' ? '&error=' . urlencode($errorMessage) : ''))
    );
    exit;
}

if (isset($_GET['saved'])) {
    $successMessage = 'Payroll saved successfully.';
}

if (isset($_GET['error']) && $errorMessage === '') {
    $errorMessage = (string)$_GET['error'];
}

/*
|--------------------------------------------------------------------------
| Reload payroll records after POST/redirect
|--------------------------------------------------------------------------
*/

$existing = [];

$stmt = $conn->prepare("
    SELECT *
    FROM `payroll_records`
    WHERE `payroll_period` = ?
");

if ($stmt) {
    $stmt->bind_param('s', $periodKey);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $existing[(string)$row['staff_id']] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Combine staff with saved payroll data
|--------------------------------------------------------------------------
*/

$register = [];

foreach ($staffRows as $staff) {
    $staffId = (string)$staff['staff_id'];
    $saved = $existing[$staffId] ?? null;

    $register[] = [
        'staff_id' => (int)$staff['staff_id'],
        'staff_name' => (string)$staff['staff_name'],
        'staff_role' => (string)($staff['staff_role'] ?? 'Staff'),
        'branch_id' => $staff['branch_id'] ?? null,
        'basic_salary' => (float)($saved['basic_salary'] ?? $staff['basic_salary'] ?? 0),
        'allowances' => (float)($saved['allowances'] ?? 0),
        'bonus' => (float)($saved['bonus'] ?? 0),
        'overtime' => (float)($saved['overtime'] ?? 0),
        'other_earnings' => (float)($saved['other_earnings'] ?? 0),
        'paye' => (float)($saved['paye'] ?? 0),
        'napsa' => (float)($saved['napsa'] ?? 0),
        'nhima' => (float)($saved['nhima'] ?? 0),
        'loan_deduction' => (float)($saved['loan_deduction'] ?? 0),
        'salary_advance' => (float)($saved['salary_advance'] ?? 0),
        'other_deductions' => (float)($saved['other_deductions'] ?? 0),
        'gross_salary' => (float)($saved['gross_salary'] ?? $staff['basic_salary'] ?? 0),
        'total_deductions' => (float)($saved['total_deductions'] ?? 0),
        'net_salary' => (float)($saved['net_salary'] ?? $staff['basic_salary'] ?? 0),
        'status' => (string)($saved['status'] ?? 'draft'),
    ];
}

$totalBasic = 0;
$totalGross = 0;
$totalDeductions = 0;
$totalNet = 0;
$locked = false;

foreach ($register as $row) {
    $totalBasic += $row['basic_salary'];
    $totalGross += $row['gross_salary'];
    $totalDeductions += $row['total_deductions'];
    $totalNet += $row['net_salary'];

    if ($row['status'] === 'locked') {
        $locked = true;
    }
}

/*
|--------------------------------------------------------------------------
| Admin shell
|--------------------------------------------------------------------------
*/

$adminHeader = __DIR__ . '/admin_header.php';
$adminAside = __DIR__ . '/admin_aside.php';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payroll — EchoTech POS</title>

<style>
:root{
    --bg:#f4f6f9;
    --card:#fff;
    --text:#17202b;
    --muted:#718096;
    --border:#e4e9ef;
    --blue:#2563eb;
    --blue-soft:#eef4ff;
    --green:#059669;
    --green-soft:#e9f8f1;
    --orange:#d97706;
    --orange-soft:#fff7e8;
    --red:#dc2626;
    --red-soft:#fff0f0;
    --dark:#202b36;
    --shadow:0 3px 14px rgba(15,23,42,.06);
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

*{box-sizing:border-box}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-size:14px;
}

.payroll-page{
    padding:24px;
    max-width:1600px;
    margin:0 auto;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:18px;
}

.page-title h1{
    margin:0 0 5px;
    font-size:28px;
    line-height:1.15;
}

.page-title p{
    margin:0;
    color:var(--muted);
}

.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.control{
    height:40px;
    border:1px solid var(--border);
    background:#fff;
    border-radius:8px;
    padding:0 12px;
    color:var(--text);
    font:inherit;
}

.btn{
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    min-height:40px;
    padding:0 14px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
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

.btn-danger{
    background:#fff;
    color:var(--red);
    border-color:#fecaca;
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
}

.alert{
    border-radius:9px;
    padding:12px 14px;
    margin-bottom:16px;
    border:1px solid;
}

.alert-success{
    color:#047857;
    background:var(--green-soft);
    border-color:#b7ebd3;
}

.alert-error{
    color:#b91c1c;
    background:var(--red-soft);
    border-color:#fecaca;
}

.summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:11px;
    padding:18px;
    box-shadow:var(--shadow);
}

.summary-label{
    color:var(--muted);
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.summary-value{
    margin-top:8px;
    font-size:24px;
    font-weight:750;
}

.summary-card:nth-child(2) .summary-value{color:var(--blue)}
.summary-card:nth-child(3) .summary-value{color:var(--orange)}
.summary-card:nth-child(4) .summary-value{color:var(--green)}

.panel{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:11px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.panel-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
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
    font-size:13px;
}

.status{
    display:inline-flex;
    align-items:center;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
}

.status-draft{background:#f1f5f9;color:#64748b}
.status-calculated{background:var(--blue-soft);color:var(--blue)}
.status-locked{background:var(--green-soft);color:var(--green)}

.table-wrap{
    width:100%;
    overflow:auto;
}

.payroll-table{
    width:100%;
    min-width:1250px;
    border-collapse:collapse;
}

.payroll-table th,
.payroll-table td{
    border-bottom:1px solid var(--border);
    padding:11px 12px;
    text-align:left;
    vertical-align:middle;
}

.payroll-table th{
    background:#f8fafc;
    color:#526173;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.03em;
    white-space:nowrap;
}

.payroll-table td{
    font-size:13px;
}

.staff-name{
    font-weight:700;
}

.staff-role{
    color:var(--muted);
    font-size:12px;
    margin-top:2px;
}

.money{
    text-align:right!important;
    white-space:nowrap;
    font-variant-numeric:tabular-nums;
}

.net{
    font-weight:750;
    color:var(--green);
}

.row-actions{
    white-space:nowrap;
}

.empty{
    text-align:center;
    padding:45px 20px!important;
    color:var(--muted);
}

.search-box{
    width:260px;
}

.footer-note{
    color:var(--muted);
    font-size:12px;
    padding:14px 18px;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.48);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
    padding:18px;
}

.modal-backdrop.open{display:flex}

.modal{
    background:#fff;
    width:min(760px,100%);
    max-height:90vh;
    overflow:auto;
    border-radius:13px;
    box-shadow:0 25px 70px rgba(15,23,42,.25);
}

.modal-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:17px 19px;
    border-bottom:1px solid var(--border);
}

.modal-head h3{
    margin:0;
    font-size:18px;
}

.modal-body{padding:19px}

.form-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:13px;
}

.form-group label{
    display:block;
    color:#526173;
    font-size:12px;
    font-weight:650;
    margin-bottom:5px;
}

.form-input{
    width:100%;
    height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:0 10px;
    font:inherit;
}

.section-label{
    margin:18px 0 10px;
    font-size:13px;
    font-weight:750;
    color:#334155;
}

.modal-foot{
    display:flex;
    justify-content:flex-end;
    gap:9px;
    padding:15px 19px;
    border-top:1px solid var(--border);
}

.locked-banner{
    margin:0 18px 16px;
    padding:11px 13px;
    background:var(--green-soft);
    color:#047857;
    border:1px solid #b7ebd3;
    border-radius:8px;
    font-weight:650;
}

@media(max-width:1000px){
    .summary{grid-template-columns:repeat(2,minmax(0,1fr))}
    .page-title{flex-direction:column}
}

@media(max-width:650px){
    .payroll-page{padding:14px}
    .summary{grid-template-columns:1fr}
    .toolbar{width:100%}
    .control,.search-box,.toolbar .btn{width:100%}
    .form-grid{grid-template-columns:1fr}
}
</style>
</head>

<body>

<?php
/*
 * These are intentionally independent shell includes.
 * If the shell files output markup, they remain responsible for
 * the admin navigation/header and this page supplies only content.
 */
if (is_file($adminAside)) {
    require $adminAside;
}

if (is_file($adminHeader)) {
    require $adminHeader;
}
?>

<main class="payroll-page">

    <div class="page-title">
        <div>
            <h1>Payroll</h1>
            <p>Process staff salaries, earnings, deductions and net pay.</p>
        </div>

        <form method="get" class="toolbar">
            <select class="control" name="month" aria-label="Payroll month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $periodMonth ? 'selected' : '' ?>>
                        <?= pp_h(date('F', mktime(0,0,0,$m,1,$periodYear))) ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select class="control" name="year" aria-label="Payroll year">
                <?php for ($y = date('Y') - 3; $y <= date('Y') + 2; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $periodYear ? 'selected' : '' ?>>
                        <?= $y ?>
                    </option>
                <?php endfor; ?>
            </select>

            <button class="btn btn-primary" type="submit">Load Payroll</button>
        </form>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= pp_h($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?= pp_h($errorMessage) ?></div>
    <?php endif; ?>

    <section class="summary">
        <div class="summary-card">
            <div class="summary-label">Payroll Period</div>
            <div class="summary-value" style="font-size:20px">
                <?= pp_h($periodLabel) ?>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Basic Salary</div>
            <div class="summary-value"><?= pp_money($totalBasic) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Gross Payroll</div>
            <div class="summary-value"><?= pp_money($totalGross) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Net Payroll</div>
            <div class="summary-value"><?= pp_money($totalNet) ?></div>
        </div>
    </section>

    <section class="panel">

        <div class="panel-head">
            <div>
                <h2><?= pp_h($periodLabel) ?> Payroll Register</h2>
                <p><?= count($register) ?> active staff member(s) in the register.</p>
            </div>

            <div class="toolbar">
                <input
                    class="control search-box"
                    id="staffSearch"
                    type="search"
                    placeholder="Search staff..."
                    autocomplete="off"
                >

                <?php if (!$locked): ?>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="calculate_all">
                        <input type="hidden" name="month" value="<?= $periodMonth ?>">
                        <input type="hidden" name="year" value="<?= $periodYear ?>">
                        <button class="btn" type="submit">Recalculate</button>
                    </form>

                    <form
                        method="post"
                        style="margin:0"
                        onsubmit="return confirm('Lock payroll for <?= pp_h($periodLabel) ?>? Locked payroll cannot be edited until reopened by an administrator.');"
                    >
                        <input type="hidden" name="action" value="lock_payroll">
                        <input type="hidden" name="month" value="<?= $periodMonth ?>">
                        <input type="hidden" name="year" value="<?= $periodYear ?>">
                        <button class="btn btn-success" type="submit">Lock Payroll</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="reopen_payroll">
                        <input type="hidden" name="month" value="<?= $periodMonth ?>">
                        <input type="hidden" name="year" value="<?= $periodYear ?>">
                        <button class="btn" type="submit">Reopen Payroll</button>
                    </form>
                <?php endif; ?>

                <button class="btn" type="button" onclick="window.print()">Print</button>
            </div>
        </div>

        <?php if ($locked): ?>
            <div class="locked-banner">
                This payroll period is locked. Reopen it before making changes.
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="payroll-table" id="payrollTable">
                <thead>
                <tr>
                    <th>Staff</th>
                    <th>Status</th>
                    <th class="money">Basic</th>
                    <th class="money">Allowances</th>
                    <th class="money">Bonus</th>
                    <th class="money">Overtime</th>
                    <th class="money">Other Earnings</th>
                    <th class="money">Gross</th>
                    <th class="money">Deductions</th>
                    <th class="money">Net Pay</th>
                    <th>Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$register): ?>
                    <tr>
                        <td colspan="11" class="empty">
                            No active staff records were found.
                            Make sure staff members have a valid account and basic salary.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($register as $row): ?>
                        <?php
                            $rowJson = json_encode(
                                $row,
                                JSON_HEX_TAG
                                | JSON_HEX_AMP
                                | JSON_HEX_APOS
                                | JSON_HEX_QUOT
                            );

                            $rowSearch =
                                strtolower(
                                    $row['staff_name']
                                    . ' '
                                    . $row['staff_role']
                                );
                        ?>

                        <tr data-search="<?= pp_h($rowSearch) ?>">
                            <td>
                                <div class="staff-name"><?= pp_h($row['staff_name']) ?></div>
                                <div class="staff-role"><?= pp_h($row['staff_role']) ?></div>
                            </td>

                            <td>
                                <?php
                                $statusClass = match ($row['status']) {
                                    'locked' => 'status-locked',
                                    'calculated' => 'status-calculated',
                                    default => 'status-draft',
                                };
                                ?>
                                <span class="status <?= $statusClass ?>">
                                    <?= pp_h($row['status']) ?>
                                </span>
                            </td>

                            <td class="money"><?= pp_money($row['basic_salary']) ?></td>
                            <td class="money"><?= pp_money($row['allowances']) ?></td>
                            <td class="money"><?= pp_money($row['bonus']) ?></td>
                            <td class="money"><?= pp_money($row['overtime']) ?></td>
                            <td class="money"><?= pp_money($row['other_earnings']) ?></td>
                            <td class="money"><?= pp_money($row['gross_salary']) ?></td>
                            <td class="money"><?= pp_money($row['total_deductions']) ?></td>
                            <td class="money net"><?= pp_money($row['net_salary']) ?></td>

                            <td class="row-actions">
                                <button
                                    type="button"
                                    class="btn"
                                    <?= $locked ? 'disabled' : '' ?>
                                    onclick='openPayrollEditor(<?= $rowJson ?>)'
                                >
                                    Edit Pay
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer-note">
            Total deductions for this period: <strong><?= pp_money($totalDeductions) ?></strong>.
            Tax and statutory deduction rules will be added in the next payroll phase rather than being guessed here.
        </div>
    </section>
</main>

<!-- Payroll editor -->
<div class="modal-backdrop" id="payrollModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="payrollModalTitle">

        <div class="modal-head">
            <div>
                <h3 id="payrollModalTitle">Edit Payroll</h3>
            </div>

            <button type="button" class="btn" onclick="closePayrollEditor()">Close</button>
        </div>

        <form method="post">

            <div class="modal-body">

                <input type="hidden" name="action" value="save_row">
                <input type="hidden" name="month" value="<?= $periodMonth ?>">
                <input type="hidden" name="year" value="<?= $periodYear ?>">
                <input type="hidden" name="staff_id" id="staff_id">

                <div id="staffDisplay"
                     style="font-size:17px;font-weight:750;margin-bottom:16px">
                </div>

                <div class="section-label">Earnings</div>

                <div class="form-grid">

                    <div class="form-group">
                        <label>Basic Salary</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="basic_salary"
                               id="basic_salary">
                    </div>

                    <div class="form-group">
                        <label>Allowances</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="allowances"
                               id="allowances">
                    </div>

                    <div class="form-group">
                        <label>Bonus</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="bonus"
                               id="bonus">
                    </div>

                    <div class="form-group">
                        <label>Overtime</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="overtime"
                               id="overtime">
                    </div>

                    <div class="form-group">
                        <label>Other Earnings</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="other_earnings"
                               id="other_earnings">
                    </div>

                </div>

                <div class="section-label">Deductions</div>

                <div class="form-grid">

                    <div class="form-group">
                        <label>PAYE</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="paye"
                               id="paye">
                    </div>

                    <div class="form-group">
                        <label>NAPSA</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="napsa"
                               id="napsa">
                    </div>

                    <div class="form-group">
                        <label>NHIMA</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="nhima"
                               id="nhima">
                    </div>

                    <div class="form-group">
                        <label>Loan Deduction</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="loan_deduction"
                               id="loan_deduction">
                    </div>

                    <div class="form-group">
                        <label>Salary Advance</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="salary_advance"
                               id="salary_advance">
                    </div>

                    <div class="form-group">
                        <label>Other Deductions</label>
                        <input class="form-input money-input"
                               type="number"
                               min="0"
                               step="0.01"
                               name="other_deductions"
                               id="other_deductions">
                    </div>

                </div>

                <div style="
                    margin-top:18px;
                    padding:14px;
                    background:#f8fafc;
                    border:1px solid var(--border);
                    border-radius:9px;
                ">
                    <div style="display:flex;justify-content:space-between;margin-bottom:7px">
                        <span>Gross Salary</span>
                        <strong id="previewGross">K0.00</strong>
                    </div>

                    <div style="display:flex;justify-content:space-between;margin-bottom:7px">
                        <span>Total Deductions</span>
                        <strong id="previewDeductions">K0.00</strong>
                    </div>

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding-top:9px;
                        border-top:1px solid var(--border);
                        font-size:16px;
                    ">
                        <span>Net Pay</span>
                        <strong class="net" id="previewNet">K0.00</strong>
                    </div>
                </div>

            </div>

            <div class="modal-foot">
                <button type="button" class="btn" onclick="closePayrollEditor()">Cancel</button>
                <button class="btn btn-primary" type="submit">Save Payroll</button>
            </div>

        </form>
    </div>
</div>

<script>
(function(){
    const search = document.getElementById('staffSearch');
    const table = document.getElementById('payrollTable');

    if (search && table) {
        search.addEventListener('input', function(){
            const term = this.value.trim().toLowerCase();

            table.querySelectorAll('tbody tr[data-search]').forEach(function(row){
                const haystack = row.getAttribute('data-search') || '';
                row.style.display = !term || haystack.includes(term) ? '' : 'none';
            });
        });
    }
})();

function moneyValue(id){
    const element = document.getElementById(id);
    if (!element) return 0;

    const value = parseFloat(element.value);
    return Number.isFinite(value) && value > 0 ? value : 0;
}

function formatMoney(value){
    return 'K' + Number(value || 0).toLocaleString('en-ZM', {
        minimumFractionDigits:2,
        maximumFractionDigits:2
    });
}

function updatePayrollPreview(){
    const gross =
        moneyValue('basic_salary') +
        moneyValue('allowances') +
        moneyValue('bonus') +
        moneyValue('overtime') +
        moneyValue('other_earnings');

    const deductions =
        moneyValue('paye') +
        moneyValue('napsa') +
        moneyValue('nhima') +
        moneyValue('loan_deduction') +
        moneyValue('salary_advance') +
        moneyValue('other_deductions');

    const net = Math.max(0, gross - deductions);

    document.getElementById('previewGross').textContent = formatMoney(gross);
    document.getElementById('previewDeductions').textContent = formatMoney(deductions);
    document.getElementById('previewNet').textContent = formatMoney(net);
}

function openPayrollEditor(row){
    document.getElementById('staff_id').value = row.staff_id;
    document.getElementById('staffDisplay').textContent =
        row.staff_name + ' — ' + row.staff_role;

    const fields = [
        'basic_salary',
        'allowances',
        'bonus',
        'overtime',
        'other_earnings',
        'paye',
        'napsa',
        'nhima',
        'loan_deduction',
        'salary_advance',
        'other_deductions'
    ];

    fields.forEach(function(field){
        const input = document.getElementById(field);
        if (input) {
            input.value = Number(row[field] || 0).toFixed(2);
        }
    });

    updatePayrollPreview();

    document.getElementById('payrollModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePayrollEditor(){
    document.getElementById('payrollModal').classList.remove('open');
    document.body.style.overflow = '';
}

document.querySelectorAll('.money-input').forEach(function(input){
    input.addEventListener('input', updatePayrollPreview);
});

document.getElementById('payrollModal').addEventListener('click', function(event){
    if (event.target === this) {
        closePayrollEditor();
    }
});

document.addEventListener('keydown', function(event){
    if (event.key === 'Escape') {
        closePayrollEditor();
    }
});
</script>

</body>
</html>
