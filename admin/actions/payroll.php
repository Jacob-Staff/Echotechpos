<?php
/**
 * ============================================================
 * EchoTech POS - COMPLETE PAYROLL MODULE
 * ============================================================
 *
 * Payroll controller/view:
 *   /admin/actions/payroll.php
 *
 * Browser entry point:
 *   /admin/payroll.php
 *
 * IMPORTANT:
 * This file is intentionally stored in /admin/actions/.
 * /admin/payroll.php is only the browser entry wrapper.
 *
 * Includes:
 *   - Monthly payroll preparation
 *   - Earnings and deductions
 *   - Zambia PAYE / NAPSA / NHIMA
 *   - Employer statutory costs
 *   - Recalculation
 *   - Approval / Paid / Locked workflow
 *   - Statutory remittance tracking
 *   - Payroll history
 *   - YTD register
 *
 * The Admin Header and Admin Aside remain separate reusable files.
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

/* ---------- Existing project includes ---------- */

foreach ([
    __DIR__ . '/../../includes/auth.php',
    __DIR__ . '/../../includes/auth_helpers.php',
    __DIR__ . '/../../auth.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

foreach ([
    __DIR__ . '/../../includes/conn.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../db.php'
] as $f) {
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

if (function_exists('require_admin')) {
    require_admin();
} elseif (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Access denied.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

/*
 * Payslip functionality is a separate module.
 * Payroll may call its services (for example, automatic email after payment),
 * but it does not contain the payslip renderer, PDF engine or email code.
 */
require_once __DIR__ . '/payslip.php';

/*
 * Payroll is the single owner of staff salary.
 * Keep the master salary on users so staff accounts can be created
 * without choosing a salary, while Payroll controls the value.
 */
function payroll_complete_ensure_column(
    mysqli $db,
    string $table,
    string $column,
    string $definition
): void {
    if (!payroll_complete_table($db, $table)) {
        return;
    }

    if (!payroll_complete_col($db, $table, $column)) {
        @$db->query(
            "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}"
        );
    }
}

/* ---------- Helpers ---------- */

function payroll_complete_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function payroll_complete_money(float $v): string {
    return 'K' . number_format($v, 2);
}

function payroll_template_components_sum(mixed $json): float {
    $items = is_string($json) ? json_decode($json, true) : $json;
    if (!is_array($items)) return 0.0;
    $sum = 0.0;
    foreach ($items as $item) {
        if (is_array($item)) $sum += max(0, (float)($item['amount'] ?? 0));
    }
    return round($sum, 2);
}

function payroll_template_components_decode(mixed $json): array {
    $items = is_string($json) ? json_decode($json, true) : $json;
    return is_array($items) ? $items : [];
}

function payroll_complete_table(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $r = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $r instanceof mysqli_result && $r->num_rows > 0;
}

function payroll_complete_col(mysqli $db, string $table, string $column): bool {
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $db->real_escape_string($column);
    $r = @$db->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );
    return $r instanceof mysqli_result && $r->num_rows > 0;
}

function payroll_complete_first_col(
    mysqli $db,
    string $table,
    array $candidates
): ?string {
    foreach ($candidates as $c) {
        if (payroll_complete_col($db, $table, $c)) {
            return $c;
        }
    }
    return null;
}

function payroll_complete_rows(
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

    $r = @$stmt->get_result();
    $rows = [];

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function payroll_complete_user(): string {
    return (string)(
        $_SESSION['full_name']
        ?? $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? 'Administrator'
    );
}

function payroll_complete_redirect(array $params = []): never {
    $url = '/admin/payroll.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/*
 * Master salary is intentionally maintained by Payroll only.
 */
payroll_complete_ensure_column(
    $conn,
    'users',
    'salary_amount',
    'DECIMAL(15,2) NOT NULL DEFAULT 0'
);

/* ---------- Zambia statutory calculations ---------- */

const EP_PAYE_B1 = 5100.00;
const EP_PAYE_B2 = 7100.00;
const EP_PAYE_B3 = 9200.00;

const EP_NAPSA_RATE = 0.05;
const EP_NAPSA_CEILING = 57840.60;
const EP_NAPSA_MAX = 2892.03;

const EP_NHIMA_RATE = 0.01;

function payroll_complete_paye(float $gross): float {
    $gross = max(0, $gross);
    $tax = 0;

    if ($gross <= EP_PAYE_B1) {
        return 0.00;
    }

    $part = min($gross, EP_PAYE_B2) - EP_PAYE_B1;
    if ($part > 0) {
        $tax += $part * 0.20;
    }

    $part = min($gross, EP_PAYE_B3) - EP_PAYE_B2;
    if ($part > 0) {
        $tax += $part * 0.30;
    }

    $part = $gross - EP_PAYE_B3;
    if ($part > 0) {
        $tax += $part * 0.37;
    }

    return round($tax, 2);
}

function payroll_complete_napsa(float $gross): float {
    $base = min(max(0, $gross), EP_NAPSA_CEILING);
    return round(min($base * EP_NAPSA_RATE, EP_NAPSA_MAX), 2);
}

function payroll_complete_nhima(float $basic): float {
    return round(max(0, $basic) * EP_NHIMA_RATE, 2);
}

function payroll_complete_calculate(
    float $basic,
    float $allowances,
    float $bonus,
    float $overtime,
    float $otherEarnings,
    float $loan,
    float $advance,
    float $otherDeductions
): array {
    $gross = round(
        max(0, $basic)
        + max(0, $allowances)
        + max(0, $bonus)
        + max(0, $overtime)
        + max(0, $otherEarnings),
        2
    );

    $paye = payroll_complete_paye($gross);
    $napsa = payroll_complete_napsa($gross);
    $nhima = payroll_complete_nhima($basic);

    $other = round(
        max(0, $loan)
        + max(0, $advance)
        + max(0, $otherDeductions),
        2
    );

    $total = round($paye + $napsa + $nhima + $other, 2);
    $net = round(max(0, $gross - $total), 2);

    return [
        'gross' => $gross,
        'paye' => $paye,
        'napsa' => $napsa,
        'nhima' => $nhima,
        'employer_napsa' => $napsa,
        'employer_nhima' => $nhima,
        'other' => $other,
        'total' => $total,
        'net' => $net,
        'employer_cost' => round($gross + $napsa + $nhima, 2),
    ];
}

/* ---------- Tenant ---------- */

$pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);

if ($pharmacyId <= 0 && function_exists('require_pharmacy')) {
    require_pharmacy();
    $pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
}

if ($pharmacyId <= 0) {
    http_response_code(403);
    exit('Your account is not assigned to a valid pharmacy.');
}

$userRole = function_exists('current_role')
    ? current_role()
    : (string)($_SESSION['role'] ?? 'Admin');

$userDisplayName = function_exists('current_user')
    ? current_user()
    : payroll_complete_user();

$admin_page_title = 'Payroll';

/* ---------- Period ---------- */

$selectedMonth = max(
    1,
    min(12, (int)($_GET['month'] ?? $_POST['month'] ?? date('n')))
);

$selectedYear = max(
    2020,
    min(2100, (int)($_GET['year'] ?? $_POST['year'] ?? date('Y')))
);

$period = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
$periodLabel = date('F Y', strtotime($period . '-01'));

$search = trim((string)($_GET['search'] ?? ''));
$branchFilter = (int)($_GET['branch_id'] ?? 0);

$view = (string)($_GET['view'] ?? 'payroll');

if (!in_array(
    $view,
    ['payroll', 'salary', 'statutory', 'remittance', 'history', 'ytd'],
    true
)) {
    $view = 'payroll';
}

$success = '';
$error = '';

if (isset($_GET['saved'])) {
    $success = (string)$_GET['saved'];
}

if (isset($_GET['error'])) {
    $error = (string)$_GET['error'];
}

/* ---------- Pharmacy ---------- */

$pharmacyName = 'PHARMANOVA';

if (payroll_complete_table($conn, 'pharmacies')) {
    $nameCol = payroll_complete_first_col(
        $conn,
        'pharmacies',
        ['name', 'pharmacy_name', 'business_name']
    );

    if ($nameCol) {
        $name = payroll_complete_rows(
            $conn,
            "SELECT `{$nameCol}` AS name
             FROM pharmacies
             WHERE id = ?
             LIMIT 1",
            'i',
            [$pharmacyId]
        );

        if (!empty($name[0]['name'])) {
            $pharmacyName = (string)$name[0]['name'];
        }
    }
}

/* ---------- Branches ---------- */

$branches = [];

if (payroll_complete_table($conn, 'branches')) {
    $branchCol = payroll_complete_first_col(
        $conn,
        'branches',
        ['branch_name', 'name', 'branch']
    );

    if ($branchCol) {
        $branches = payroll_complete_rows(
            $conn,
            "SELECT id, `{$branchCol}` AS branch_name
             FROM branches
             WHERE pharmacy_id = ?
             ORDER BY `{$branchCol}` ASC",
            'i',
            [$pharmacyId]
        );
    }
}

$branchNames = [];
foreach ($branches as $branch) {
    $branchNames[(int)$branch['id']] = (string)$branch['branch_name'];
}

$branchCount = count($branches);

/* ---------- Staff source: users is the authoritative staff table ---------- */

/*
 * The live POS database supplied with this project has one authoritative
 * staff table: users.
 *
 * IMPORTANT:
 * - Staff Management owns the employee account.
 * - Payroll owns salary_amount.
 * - Do not try to discover a different "staff" table here.
 *
 * This removes the old "No active staff found" condition.
 */

$staffTable = 'users';
$staffRows = [];

if (!payroll_complete_table($conn, $staffTable)) {

    $error = 'The users table could not be found. Payroll cannot load staff records.';

} else {

    /*
     * These columns are confirmed by the current database schema.
     */
    $staffIdCol = 'id';
    $roleCol = 'role';
    $branchCol = 'branch_id';
    $salaryCol = 'salary_amount';
    $emailCol = 'email';
    $employeeNoCol = null;
    $statusCol = 'status';

    $where = [
        's.`pharmacy_id` = ?'
    ];

    $types = 'i';
    $params = [$pharmacyId];

    if ($branchFilter > 0) {
        $where[] = 's.`branch_id` = ?';
        $types .= 'i';
        $params[] = $branchFilter;
    }

    if ($search !== '') {

        $where[] = "(
            COALESCE(NULLIF(s.`full_name`, ''), s.`username`) LIKE ?
            OR s.`email` LIKE ?
            OR s.`username` LIKE ?
            OR CAST(s.`id` AS CHAR) LIKE ?
        )";

        $like = '%' . $search . '%';

        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    /*
     * Only active staff are loaded into the normal payroll register.
     * Frozen accounts are not automatically excluded: freezing controls
     * access to the POS, not employment/payroll history.
     */
    $where[] = "(
        s.`status` IS NULL
        OR s.`status` = ''
        OR LOWER(s.`status`) = 'active'
        OR s.`status` = '1'
    )";

    $staffRows = payroll_complete_rows(
        $conn,
        "SELECT
            s.`id` AS staff_id,

            COALESCE(
                NULLIF(TRIM(s.`full_name`), ''),
                NULLIF(TRIM(s.`username`), ''),
                CONCAT('Staff #', s.`id`)
            ) AS staff_name,

            COALESCE(s.`salary_amount`, 0) AS basic_salary,

            COALESCE(s.`role`, 'Staff') AS staff_role,

            COALESCE(s.`branch_id`, 0) AS branch_id,

            COALESCE(s.`email`, '') AS email,

            s.`id` AS employee_number

         FROM `users` s

         WHERE " . implode("\n AND ", $where) . "

         ORDER BY
            COALESCE(
                NULLIF(TRIM(s.`full_name`), ''),
                NULLIF(TRIM(s.`username`), ''),
                CONCAT('Staff #', s.`id`)
            ) ASC",
        $types,
        $params
    );
}
/* ---------- Create/upgrade payroll records ---------- */

if (!payroll_complete_table($conn, 'payroll_records')) {

    $create = "
        CREATE TABLE `payroll_records` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `pharmacy_id` INT UNSIGNED NULL,
            `branch_id` INT UNSIGNED NULL,
            `staff_id` INT UNSIGNED NOT NULL,
            `payroll_period` CHAR(7) NOT NULL,
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
            `employer_napsa` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `employer_nhima` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `statutory_year` INT NULL,
            `statutory_calculated_at` DATETIME NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `payment_date` DATE NULL,
            `payment_reference` VARCHAR(150) NULL,
            `payment_method` VARCHAR(50) NULL,
            `verification_token` CHAR(48) NULL,
            `document_hash` CHAR(64) NULL,
            `verified_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `revoked_by` VARCHAR(150) NULL,
            `payslip_email_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `payslip_email_sent_at` DATETIME NULL,
            `payslip_email_error` TEXT NULL,
            `created_by` VARCHAR(150) NULL,
            `updated_by` VARCHAR(150) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_payroll_staff_period`
                (`pharmacy_id`,`staff_id`,`payroll_period`),
            KEY `idx_payroll_period`
                (`pharmacy_id`,`payroll_period`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($create)) {
        $error = 'Could not create payroll records table: ' . $conn->error;
    }
}

if ($error === '') {

    $upgradeColumns = [
        'pharmacy_id' => 'INT UNSIGNED NULL',
        'branch_id' => 'INT UNSIGNED NULL',
        'allowances' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'bonus' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'overtime' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'other_earnings' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'loan_deduction' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'salary_advance' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'other_deductions' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'gross_salary' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'total_deductions' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'net_salary' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'employer_napsa' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'employer_nhima' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'statutory_year' => 'INT NULL',
        'statutory_calculated_at' => 'DATETIME NULL',
        'created_by' => 'VARCHAR(150) NULL',
        'updated_by' => 'VARCHAR(150) NULL',
        'payment_date' => 'DATE NULL',
        'payment_reference' => 'VARCHAR(150) NULL',
        'payment_method' => 'VARCHAR(50) NULL',
        'verification_token' => 'CHAR(48) NULL',
        'document_hash' => 'CHAR(64) NULL',
        'verified_at' => 'DATETIME NULL',
        'revoked_at' => 'DATETIME NULL',
        'revoked_by' => 'VARCHAR(150) NULL',
        'payslip_email_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'payslip_email_sent_at' => 'DATETIME NULL',
        'payslip_email_error' => 'TEXT NULL',
    ];

    foreach ($upgradeColumns as $col => $definition) {
        if (!payroll_complete_col($conn, 'payroll_records', $col)) {
            @$conn->query(
                "ALTER TABLE payroll_records
                 ADD COLUMN `{$col}` {$definition}"
            );
        }
    }
}

/* ---------- Remittance table ---------- */

if ($error === '' && !payroll_complete_table($conn, 'payroll_remittances')) {

    $create = "
        CREATE TABLE `payroll_remittances` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `pharmacy_id` INT UNSIGNED NULL,
            `payroll_period` CHAR(7) NOT NULL,
            `statutory_type` VARCHAR(10) NOT NULL,
            `liability_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `employee_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `employer_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `due_date` DATE NOT NULL,
            `return_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
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
            UNIQUE KEY `uq_remit_period_type`
                (`pharmacy_id`,`payroll_period`,`statutory_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($create)) {
        $error = 'Could not create payroll remittance table: ' . $conn->error;
    }
}


/* ---------- Salary Template master ---------- */

if ($error === '' && !payroll_complete_table($conn, 'payroll_salary_templates')) {
    $create = "
        CREATE TABLE `payroll_salary_templates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `pharmacy_id` INT UNSIGNED NULL,
            `staff_id` INT UNSIGNED NOT NULL,
            `grade_name` VARCHAR(100) NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'ZMW',
            `basic_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `gratuity_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `overtime_rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
            `monthly_allowed_leaves` DECIMAL(8,2) NOT NULL DEFAULT 0,
            `allowances_json` LONGTEXT NULL,
            `deductions_json` LONGTEXT NULL,
            `bank_name` VARCHAR(150) NULL,
            `account_name` VARCHAR(150) NULL,
            `account_number` VARCHAR(100) NULL,
            `effective_from` DATE NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_by` VARCHAR(150) NULL,
            `updated_by` VARCHAR(150) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_salary_template_staff` (`pharmacy_id`,`staff_id`),
            KEY `idx_salary_template_staff` (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!$conn->query($create)) {
        $error = 'Could not create salary template table: ' . $conn->error;
    }
}

if ($error === '' && payroll_complete_table($conn, 'payroll_salary_templates')) {
    $templateColumns = [
        'pharmacy_id' => 'INT UNSIGNED NULL',
        'grade_name' => 'VARCHAR(100) NULL',
        'currency' => "VARCHAR(10) NOT NULL DEFAULT 'ZMW'",
        'basic_salary' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'gratuity_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'overtime_rate' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'monthly_allowed_leaves' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
        'allowances_json' => 'LONGTEXT NULL',
        'deductions_json' => 'LONGTEXT NULL',
        'bank_name' => 'VARCHAR(150) NULL',
        'account_name' => 'VARCHAR(150) NULL',
        'account_number' => 'VARCHAR(100) NULL',
        'effective_from' => 'DATE NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'created_by' => 'VARCHAR(150) NULL',
        'updated_by' => 'VARCHAR(150) NULL'
    ];
    foreach ($templateColumns as $col => $definition) {
        if (!payroll_complete_col($conn, 'payroll_salary_templates', $col)) {
            @$conn->query("ALTER TABLE payroll_salary_templates ADD COLUMN `{$col}` {$definition}");
        }
    }
}

/* ---------- Load employee salary templates ---------- */
$salaryTemplates = [];
if ($error === '' && payroll_complete_table($conn, 'payroll_salary_templates')) {
    $templateRows = payroll_complete_rows(
        $conn,
        "SELECT * FROM payroll_salary_templates WHERE pharmacy_id = ?",
        'i',
        [$pharmacyId]
    );
    foreach ($templateRows as $templateRow) {
        $salaryTemplates[(int)$templateRow['staff_id']] = $templateRow;
    }
}

/* ---------- POST actions ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {

    $action = (string)($_POST['action'] ?? '');

    /*
     * Salary Template:
     * Payroll owns the complete employee salary structure. Staff Management
     * creates the account only; all compensation settings live here.
     */
    if ($action === 'save_salary_template') {

        $staffId = (int)($_POST['staff_id'] ?? 0);
        $gradeName = trim((string)($_POST['grade_name'] ?? ''));
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'ZMW')));
        $basic = max(0, (float)($_POST['basic_salary'] ?? 0));
        $gratuity = max(0, (float)($_POST['gratuity_amount'] ?? 0));
        $overtimeRate = max(0, (float)($_POST['overtime_rate'] ?? 0));
        $allowedLeaves = max(0, (float)($_POST['monthly_allowed_leaves'] ?? 0));
        $bankName = trim((string)($_POST['bank_name'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        $accountNumber = trim((string)($_POST['account_number'] ?? ''));
        $effectiveFrom = trim((string)($_POST['effective_from'] ?? ''));
        if ($effectiveFrom === '') $effectiveFrom = date('Y-m-d');

        $allowanceNames = $_POST['allowance_name'] ?? [];
        $allowanceAmounts = $_POST['allowance_amount'] ?? [];
        $deductionNames = $_POST['deduction_name'] ?? [];
        $deductionAmounts = $_POST['deduction_amount'] ?? [];

        $allowances = [];
        foreach ((array)$allowanceNames as $i => $name) {
            $name = trim((string)$name);
            $amount = max(0, (float)($allowanceAmounts[$i] ?? 0));
            if ($name !== '' && $amount > 0) $allowances[] = ['name' => $name, 'amount' => $amount];
        }

        $deductions = [];
        foreach ((array)$deductionNames as $i => $name) {
            $name = trim((string)$name);
            $amount = max(0, (float)($deductionAmounts[$i] ?? 0));
            if ($name !== '' && $amount > 0) $deductions[] = ['name' => $name, 'amount' => $amount];
        }

        if ($staffId <= 0) {
            payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'error'=>'Invalid staff member.']);
        }

        $check = $conn->prepare("SELECT id FROM users WHERE id = ? AND pharmacy_id = ? LIMIT 1");
        if (!$check) payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'error'=>'Could not validate the staff member.']);
        $check->bind_param('ii', $staffId, $pharmacyId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'error'=>'Staff member was not found.']);

        $allowancesJson = json_encode($allowances, JSON_UNESCAPED_UNICODE);
        $deductionsJson = json_encode($deductions, JSON_UNESCAPED_UNICODE);
        $user = payroll_complete_user();

        $stmt = $conn->prepare("
            INSERT INTO payroll_salary_templates
            (pharmacy_id, staff_id, grade_name, currency, basic_salary, gratuity_amount, overtime_rate, monthly_allowed_leaves, allowances_json, deductions_json, bank_name, account_name, account_number, effective_from, status, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ON DUPLICATE KEY UPDATE
                grade_name=VALUES(grade_name), currency=VALUES(currency), basic_salary=VALUES(basic_salary), gratuity_amount=VALUES(gratuity_amount), overtime_rate=VALUES(overtime_rate), monthly_allowed_leaves=VALUES(monthly_allowed_leaves), allowances_json=VALUES(allowances_json), deductions_json=VALUES(deductions_json), bank_name=VALUES(bank_name), account_name=VALUES(account_name), account_number=VALUES(account_number), effective_from=VALUES(effective_from), status='active', updated_by=VALUES(updated_by)
        ");

        if (!$stmt) payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'error'=>'Could not prepare salary template: '.$conn->error]);

        $stmt->bind_param('iissddddssssssss', $pharmacyId, $staffId, $gradeName, $currency, $basic, $gratuity, $overtimeRate, $allowedLeaves, $allowancesJson, $deductionsJson, $bankName, $accountName, $accountNumber, $effectiveFrom, $user, $user);
        $ok = $stmt->execute();
        $msg = $stmt->error;
        $stmt->close();

        if (!$ok) payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'error'=>'Salary template could not be saved: '.$msg]);

        /* Keep the legacy users.salary_amount field synchronized for compatibility. */
        $sync = $conn->prepare("UPDATE users SET salary_amount = ? WHERE id = ? AND pharmacy_id = ?");
        if ($sync) {
            $sync->bind_param('dii', $basic, $staffId, $pharmacyId);
            $sync->execute();
            $sync->close();
        }

        payroll_complete_redirect(['view'=>'salary','month'=>$selectedMonth,'year'=>$selectedYear,'saved'=>'Salary template saved successfully. Payroll will use this template when preparing the monthly register.']);
    }

    /* Legacy compatibility: old salary-only forms are converted into a template. */
    if ($action === 'set_salary') {

        $staffId = (int)($_POST['staff_id'] ?? 0);
        $salary = max(0, (float)($_POST['salary'] ?? 0));

        if ($staffId <= 0) {
            payroll_complete_redirect([
                'view' => 'salary',
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Invalid staff member.'
            ]);
        }

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND pharmacy_id = ?
            LIMIT 1
        ");

        if (!$check) {
            payroll_complete_redirect([
                'view' => 'salary',
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Could not validate the staff member.'
            ]);
        }

        $check->bind_param('ii', $staffId, $pharmacyId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            payroll_complete_redirect([
                'view' => 'salary',
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Staff member was not found.'
            ]);
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET salary_amount = ?
            WHERE id = ?
              AND pharmacy_id = ?
        ");

        if (!$stmt) {
            payroll_complete_redirect([
                'view' => 'salary',
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Could not prepare the salary update: ' . $conn->error
            ]);
        }

        $stmt->bind_param('dii', $salary, $staffId, $pharmacyId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            payroll_complete_redirect([
                'view' => 'salary',
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Salary could not be saved.'
            ]);
        }

        payroll_complete_redirect([
            'view' => 'salary',
            'month' => $selectedMonth,
            'year' => $selectedYear,
            'saved' => 'Salary updated successfully. The new master salary will be used when payroll is prepared.'
        ]);
    }

    if ($action === 'prepare_payroll') {

        if (!$staffRows) {
            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'No active staff records were found.'
            ]);
        }

        $stmt = $conn->prepare("
            INSERT INTO payroll_records
            (
                pharmacy_id, branch_id, staff_id, payroll_period, basic_salary,
                allowances, bonus, overtime, other_earnings, paye, napsa, nhima,
                loan_deduction, salary_advance, other_deductions, gross_salary,
                total_deductions, net_salary, employer_napsa, employer_nhima,
                statutory_year, statutory_calculated_at, status, created_by, updated_by
            )
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, NOW(), 'calculated', ?, ?)
            ON DUPLICATE KEY UPDATE
                branch_id = VALUES(branch_id),
                basic_salary = VALUES(basic_salary),
                allowances = VALUES(allowances),
                other_deductions = VALUES(other_deductions),
                paye = VALUES(paye),
                napsa = VALUES(napsa),
                nhima = VALUES(nhima),
                gross_salary = VALUES(gross_salary),
                total_deductions = VALUES(total_deductions),
                net_salary = VALUES(net_salary),
                employer_napsa = VALUES(employer_napsa),
                employer_nhima = VALUES(employer_nhima),
                statutory_year = VALUES(statutory_year),
                statutory_calculated_at = NOW(),
                status = CASE
                    WHEN payroll_records.status IN ('approved','paid','locked')
                    THEN payroll_records.status
                    ELSE 'calculated'
                END,
                updated_by = VALUES(updated_by)
        ");

        if (!$stmt) {
            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Could not prepare payroll: ' . $conn->error
            ]);
        }

        $user = payroll_complete_user();

        foreach ($staffRows as $staff) {

            $branchId = (int)($staff['branch_id'] ?? 0);
            $staffId = (int)$staff['staff_id'];
            $template = $salaryTemplates[$staffId] ?? null;
            $basic = $template
                ? max(0, (float)($template['basic_salary'] ?? 0))
                : max(0, (float)$staff['basic_salary']);
            $templateAllowances = $template ? payroll_template_components_sum($template['allowances_json'] ?? '[]') : 0.0;
            $templateDeductions = $template ? payroll_template_components_sum($template['deductions_json'] ?? '[]') : 0.0;

            /*
             * Phase 2: preparing payroll also performs the base calculation.
             * Salary Template recurring allowances/deductions are carried into
             * the monthly payroll automatically.
             * This means Basic Salary immediately produces Gross, PAYE, NAPSA,
             * NHIMA and Net Pay instead of leaving the register at K0.00.
             * Additional earnings/deductions remain zero until the payroll
             * editor is used.
             */
            $calc = payroll_complete_calculate(
                $basic,
                $templateAllowances,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0,
                $templateDeductions
            );

            $yearValue = $selectedYear;
            $paye = $calc['paye'];
            $napsa = $calc['napsa'];
            $nhima = $calc['nhima'];
            $gross = $calc['gross'];
            $total = $calc['total'];
            $net = $calc['net'];
            $employerNapsa = $calc['employer_napsa'];
            $employerNhima = $calc['employer_nhima'];

            $stmt->bind_param(
                'iiisdddddddddddiss',
                $pharmacyId,
                $branchId,
                $staffId,
                $period,
                $basic,
                $templateAllowances,
                $paye,
                $napsa,
                $nhima,
                $templateDeductions,
                $gross,
                $total,
                $net,
                $employerNapsa,
                $employerNhima,
                $yearValue,
                $user,
                $user
            );

            $stmt->execute();
        }

        $stmt->close();

        payroll_complete_redirect([
            'month' => $selectedMonth,
            'year' => $selectedYear,
            'view' => 'payroll',
            'saved' => 'Payroll prepared for ' . $periodLabel . '.'
        ]);
    }

    if ($action === 'save_row') {

        $recordId = (int)($_POST['record_id'] ?? 0);

        $found = payroll_complete_rows(
            $conn,
            "SELECT *
             FROM payroll_records
             WHERE id = ?
               AND pharmacy_id = ?
             LIMIT 1",
            'ii',
            [$recordId, $pharmacyId]
        );

        if (!$found) {
            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Payroll record not found.'
            ]);
        }

        $record = $found[0];

        if (in_array(
            strtolower((string)$record['status']),
            ['approved','paid','locked'],
            true
        )) {
            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'This payroll row is protected and cannot be edited.'
            ]);
        }

        $basic = max(0, (float)($_POST['basic_salary'] ?? 0));
        $allowances = max(0, (float)($_POST['allowances'] ?? 0));
        $bonus = max(0, (float)($_POST['bonus'] ?? 0));
        $overtime = max(0, (float)($_POST['overtime'] ?? 0));
        $otherEarnings = max(0, (float)($_POST['other_earnings'] ?? 0));
        $loan = max(0, (float)($_POST['loan_deduction'] ?? 0));
        $advance = max(0, (float)($_POST['salary_advance'] ?? 0));
        $otherDeductions = max(0, (float)($_POST['other_deductions'] ?? 0));

        $calc = payroll_complete_calculate(
            $basic,
            $allowances,
            $bonus,
            $overtime,
            $otherEarnings,
            $loan,
            $advance,
            $otherDeductions
        );

        $yearValue = $selectedYear;
        $status = 'calculated';
        $user = payroll_complete_user();

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET
                basic_salary = ?,
                allowances = ?,
                bonus = ?,
                overtime = ?,
                other_earnings = ?,
                paye = ?,
                napsa = ?,
                nhima = ?,
                loan_deduction = ?,
                salary_advance = ?,
                other_deductions = ?,
                gross_salary = ?,
                total_deductions = ?,
                net_salary = ?,
                employer_napsa = ?,
                employer_nhima = ?,
                statutory_year = ?,
                statutory_calculated_at = NOW(),
                status = ?,
                updated_by = ?
            WHERE id = ?
              AND pharmacy_id = ?
        ");

        if (!$stmt) {
            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Could not update payroll: ' . $conn->error
            ]);
        }

        $stmt->bind_param(
            'ddddddddddddddddissii',
            $basic,
            $allowances,
            $bonus,
            $overtime,
            $otherEarnings,
            $calc['paye'],
            $calc['napsa'],
            $calc['nhima'],
            $loan,
            $advance,
            $otherDeductions,
            $calc['gross'],
            $calc['total'],
            $calc['net'],
            $calc['employer_napsa'],
            $calc['employer_nhima'],
            $yearValue,
            $status,
            $user,
            $recordId,
            $pharmacyId
        );

        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();

            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'error' => 'Could not save payroll: ' . $msg
            ]);
        }

        $stmt->close();

        payroll_complete_redirect([
            'month' => $selectedMonth,
            'year' => $selectedYear,
            'view' => 'payroll',
            'saved' => 'Payroll row saved and statutory values calculated.'
        ]);
    }

    if ($action === 'recalculate') {

        $records = payroll_complete_rows(
            $conn,
            "SELECT *
             FROM payroll_records
             WHERE pharmacy_id = ?
               AND payroll_period = ?",
            'is',
            [$pharmacyId, $period]
        );

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET
                paye = ?,
                napsa = ?,
                nhima = ?,
                employer_napsa = ?,
                employer_nhima = ?,
                gross_salary = ?,
                total_deductions = ?,
                net_salary = ?,
                statutory_year = ?,
                statutory_calculated_at = NOW(),
                status = 'calculated',
                updated_by = ?
            WHERE id = ?
              AND pharmacy_id = ?
              AND status NOT IN ('approved','paid','locked')
        ");

        if ($stmt) {

            $user = payroll_complete_user();
            $changed = 0;

            foreach ($records as $record) {

                $calc = payroll_complete_calculate(
                    (float)$record['basic_salary'],
                    (float)$record['allowances'],
                    (float)$record['bonus'],
                    (float)$record['overtime'],
                    (float)$record['other_earnings'],
                    (float)$record['loan_deduction'],
                    (float)$record['salary_advance'],
                    (float)$record['other_deductions']
                );

                $yearValue = $selectedYear;
                $id = (int)$record['id'];

                $stmt->bind_param(
                    'ddddddddisii',
                    $calc['paye'],
                    $calc['napsa'],
                    $calc['nhima'],
                    $calc['employer_napsa'],
                    $calc['employer_nhima'],
                    $calc['gross'],
                    $calc['total'],
                    $calc['net'],
                    $yearValue,
                    $user,
                    $id,
                    $pharmacyId
                );

                if ($stmt->execute()) {
                    $changed++;
                }
            }

            $stmt->close();

            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'view' => 'payroll',
                'saved' => $changed . ' payroll record(s) recalculated.'
            ]);
        }
    }

    /* ---------- Payroll workflow ---------- */

    if ($action === 'approve') {

        $records = payroll_complete_rows(
            $conn,
            "SELECT id, status FROM payroll_records
             WHERE pharmacy_id = ? AND payroll_period = ?",
            'is',
            [$pharmacyId, $period]
        );

        if (!$records) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'There are no payroll records to approve.'
            ]);
        }

        foreach ($records as $record) {
            if ((string)$record['status'] !== 'calculated') {
                payroll_complete_redirect([
                    'month'=>$selectedMonth,
                    'year'=>$selectedYear,
                    'view'=>'payroll',
                    'error'=>'Payroll can only be approved when every employee record is calculated.'
                ]);
            }
        }

        $stmt = $conn->prepare("UPDATE payroll_records SET status='approved', updated_by=? WHERE pharmacy_id=? AND payroll_period=? AND status='calculated'");
        if (!$stmt) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'Could not approve payroll: '.$conn->error
            ]);
        }
        $user = payroll_complete_user();
        $stmt->bind_param('sis', $user, $pharmacyId, $period);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        payroll_complete_redirect([
            'month'=>$selectedMonth,
            'year'=>$selectedYear,
            'view'=>'payroll',
            'saved'=>$changed.' payroll record(s) approved.'
        ]);
    }

    if ($action === 'mark_paid') {

        $records = payroll_complete_rows(
            $conn,
            "SELECT id, status FROM payroll_records
             WHERE pharmacy_id = ? AND payroll_period = ?",
            'is',
            [$pharmacyId, $period]
        );

        if (!$records) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'There are no payroll records to mark as paid.'
            ]);
        }

        foreach ($records as $record) {
            if ((string)$record['status'] !== 'approved') {
                payroll_complete_redirect([
                    'month'=>$selectedMonth,
                    'year'=>$selectedYear,
                    'view'=>'payroll',
                    'error'=>'Payroll can only be marked paid after it has been approved.'
                ]);
            }
        }

        $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Bank Transfer'));

        $dateObject = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $paymentDate) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'Please enter a valid payment date.'
            ]);
        }

        if ($paymentReference === '') {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'Please enter a payment reference before marking payroll as paid.'
            ]);
        }

        $allowedMethods = ['Bank Transfer','Cash','Mobile Money','Cheque','Other'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $paymentMethod = 'Other';
        }

        $stmt = $conn->prepare("UPDATE payroll_records
            SET status='paid', payment_date=?, payment_reference=?, payment_method=?, updated_by=?
            WHERE pharmacy_id=? AND payroll_period=? AND status='approved'");

        if (!$stmt) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'Could not record payroll payment: '.$conn->error
            ]);
        }

        $user = payroll_complete_user();
        $stmt->bind_param(
    'ssssis',
    $paymentDate,
    $paymentReference,
    $paymentMethod,
    $user,
    $pharmacyId,
    $period
);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows;
        $msg = $stmt->error;
        $stmt->close();

        if (!$ok) {
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'error'=>'Could not record payroll payment: '.$msg
            ]);
        }

        /*
         * Payroll is now PAID. Automatically issue the official payslip
         * verification identity and attempt email delivery for every
         * employee in this period. Email failure does not undo a valid
         * payroll payment; the administrator can resend from the register.
         */
        $mailResult = payroll_send_period_payslips(
            $conn,
            $pharmacyId,
            $period,
            $pharmacyName
        );

        $savedMessage = $changed . ' payroll record(s) marked PAID. Payment reference: ' . $paymentReference . '. '
            . $mailResult['sent'] . ' payslip(s) emailed.';

        if ($mailResult['failed'] > 0) {
            $savedMessage .= ' ' . $mailResult['failed'] . ' email(s) failed; use Resend to try again.';
        }

        payroll_complete_redirect([
            'month'=>$selectedMonth,
            'year'=>$selectedYear,
            'view'=>'payroll',
            ($mailResult['failed'] > 0 ? 'error' : 'saved') => $savedMessage
        ]);
    }

    if ($action === 'lock') {
        $stmt = $conn->prepare("UPDATE payroll_records SET status='locked', updated_by=? WHERE pharmacy_id=? AND payroll_period=? AND status='paid'");
        if ($stmt) {
            $user = payroll_complete_user();
            $stmt->bind_param('sis', $user, $pharmacyId, $period);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'saved'=>$changed.' payroll record(s) locked. The paid payroll is now protected.'
            ]);
        }
    }

    if ($action === 'reopen') {
        $stmt = $conn->prepare("UPDATE payroll_records SET status='calculated', updated_by=? WHERE pharmacy_id=? AND payroll_period=? AND status='approved'");
        if ($stmt) {
            $user = payroll_complete_user();
            $stmt->bind_param('sis', $user, $pharmacyId, $period);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            payroll_complete_redirect([
                'month'=>$selectedMonth,
                'year'=>$selectedYear,
                'view'=>'payroll',
                'saved'=>$changed.' payroll record(s) reopened for editing.'
            ]);
        }
    }

    if ($action === 'save_remittance') {

        $id = (int)($_POST['remittance_id'] ?? 0);
        $status = (string)($_POST['return_status'] ?? 'pending');

        if (!in_array(
            $status,
            ['pending','submitted','paid','overdue'],
            true
        )) {
            $status = 'pending';
        }

        $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $returnReference = trim((string)($_POST['return_reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $user = payroll_complete_user();

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
              AND pharmacy_id = ?
              AND payroll_period = ?
        ");

        if ($stmt) {

            $stmt->bind_param(
                'ssssssiis',
                $status,
                $paymentDate,
                $paymentReference,
                $returnReference,
                $notes,
                $user,
                $id,
                $pharmacyId,
                $period
            );

            if (!$stmt->execute()) {
                $msg = $stmt->error;
                $stmt->close();

                payroll_complete_redirect([
                    'month' => $selectedMonth,
                    'year' => $selectedYear,
                    'view' => 'remittance',
                    'error' => 'Could not save remittance: ' . $msg
                ]);
            }

            $stmt->close();

            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'view' => 'remittance',
                'saved' => 'Remittance updated successfully.'
            ]);
        }
    }
}

/* ---------- Load payroll ---------- */

$payrollRows = payroll_complete_rows(
    $conn,
    "SELECT *
     FROM payroll_records
     WHERE pharmacy_id = ?
       AND payroll_period = ?
     ORDER BY staff_id ASC",
    'is',
    [$pharmacyId, $period]
);

/*
 * Attach current staff display data.
 */
$staffMap = [];

foreach ($staffRows as $staff) {
    $staffMap[(int)$staff['staff_id']] = $staff;
}

foreach ($payrollRows as &$row) {

    $staff = $staffMap[(int)$row['staff_id']] ?? [];

    $row['staff_name'] =
        $staff['staff_name']
        ?? ('Staff #' . (int)$row['staff_id']);

    $row['staff_role'] =
        $staff['staff_role']
        ?? 'Staff';

    $row['employee_number'] =
        $staff['employee_number']
        ?? '';

    $row['email'] =
        trim((string)($staff['email'] ?? ''));

    $row['branch_name'] =
        $branchNames[(int)($row['branch_id'] ?? 0)]
        ?? 'Main Branch';

    foreach ([
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
        'other_deductions',
        'gross_salary',
        'total_deductions',
        'net_salary',
        'employer_napsa',
        'employer_nhima'
    ] as $numericColumn) {
        $row[$numericColumn] = (float)$row[$numericColumn];
    }
}

unset($row);

/* ---------- Totals ---------- */

$totals = [
    'basic' => 0.0,
    'gross' => 0.0,
    'paye' => 0.0,
    'napsa' => 0.0,
    'nhima' => 0.0,
    'deductions' => 0.0,
    'net' => 0.0,
    'employer_napsa' => 0.0,
    'employer_nhima' => 0.0,
];

foreach ($payrollRows as $row) {
    $totals['basic'] += $row['basic_salary'];
    $totals['gross'] += $row['gross_salary'];
    $totals['paye'] += $row['paye'];
    $totals['napsa'] += $row['napsa'];
    $totals['nhima'] += $row['nhima'];
    $totals['deductions'] += $row['total_deductions'];
    $totals['net'] += $row['net_salary'];
    $totals['employer_napsa'] += $row['employer_napsa'];
    $totals['employer_nhima'] += $row['employer_nhima'];
}

$employerCost =
    $totals['gross']
    + $totals['employer_napsa']
    + $totals['employer_nhima'];

$periodStatus = 'draft';

foreach ($payrollRows as $row) {

    if ($row['status'] === 'locked') {
        $periodStatus = 'locked';
        break;
    }

    if ($row['status'] === 'paid') {
        $periodStatus = 'paid';
        continue;
    }

    if ($row['status'] === 'approved') {
        $periodStatus = 'approved';
        continue;
    }

    if ($row['status'] === 'calculated') {
        $periodStatus = 'calculated';
    }
}

/* ---------- Remittance ---------- */

$nextMonth = new DateTimeImmutable($period . '-01');
$nextMonth = $nextMonth->modify('first day of next month');

$dueDate = $nextMonth->setDate(
    (int)$nextMonth->format('Y'),
    (int)$nextMonth->format('m'),
    10
);

if ($error === '' && payroll_complete_table($conn, 'payroll_remittances')) {

    $remitData = [
        'PAYE' => [
            'employee' => $totals['paye'],
            'employer' => 0.0,
        ],
        'NAPSA' => [
            'employee' => $totals['napsa'],
            'employer' => $totals['employer_napsa'],
        ],
        'NHIMA' => [
            'employee' => $totals['nhima'],
            'employer' => $totals['employer_nhima'],
        ],
    ];

    $stmt = $conn->prepare("
        INSERT INTO payroll_remittances
        (
            pharmacy_id,
            payroll_period,
            statutory_type,
            liability_amount,
            employee_amount,
            employer_amount,
            due_date,
            created_by
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            liability_amount = VALUES(liability_amount),
            employee_amount = VALUES(employee_amount),
            employer_amount = VALUES(employer_amount),
            due_date = VALUES(due_date)
    ");

    if ($stmt) {

        $dueSql = $dueDate->format('Y-m-d');
        $user = payroll_complete_user();

        foreach ($remitData as $type => $values) {

            $employee = $values['employee'];
            $employer = $values['employer'];
            $liability = $employee + $employer;

            $stmt->bind_param(
                'issdddss',
                $pharmacyId,
                $period,
                $type,
                $liability,
                $employee,
                $employer,
                $dueSql,
                $user
            );

            $stmt->execute();
        }

        $stmt->close();
    }
}

$remittances = [];

if (payroll_complete_table($conn, 'payroll_remittances')) {
    $remittances = payroll_complete_rows(
        $conn,
        "SELECT *
         FROM payroll_remittances
         WHERE pharmacy_id = ?
           AND payroll_period = ?
         ORDER BY FIELD(statutory_type,'PAYE','NAPSA','NHIMA')",
        'is',
        [$pharmacyId, $period]
    );
}

$totalLiability = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;
$today = new DateTimeImmutable('today');

foreach ($remittances as &$remit) {

    $amount = (float)$remit['liability_amount'];

    $displayStatus = (string)$remit['return_status'];

    if (
        $displayStatus === 'pending'
        && $today > new DateTimeImmutable($remit['due_date'])
    ) {
        $displayStatus = 'overdue';
    }

    $remit['display_status'] = $displayStatus;

    $totalLiability += $amount;

    if ($remit['return_status'] === 'paid') {
        $totalPaid += $amount;
    } else {
        $totalOutstanding += $amount;
    }
}

unset($remit);

/* ---------- History ---------- */

$history = [];

if ($view === 'history') {

    $history = payroll_complete_rows(
        $conn,
        "SELECT
            payroll_period,
            COUNT(*) AS staff_count,
            SUM(gross_salary) AS gross,
            SUM(total_deductions) AS deductions,
            SUM(net_salary) AS net,
            SUM(paye) AS paye,
            SUM(napsa) AS napsa,
            SUM(nhima) AS nhima
         FROM payroll_records
         WHERE pharmacy_id = ?
         GROUP BY payroll_period
         ORDER BY payroll_period DESC
         LIMIT 36",
        'i',
        [$pharmacyId]
    );
}

/* ---------- YTD ---------- */

$ytdRows = [];

if ($view === 'ytd') {

    $ytdRows = payroll_complete_rows(
        $conn,
        "SELECT
            staff_id,
            SUM(basic_salary) AS basic,
            SUM(gross_salary) AS gross,
            SUM(paye) AS paye,
            SUM(napsa) AS napsa,
            SUM(nhima) AS nhima,
            SUM(total_deductions) AS deductions,
            SUM(net_salary) AS net
         FROM payroll_records
         WHERE pharmacy_id = ?
           AND payroll_period LIKE ?
         GROUP BY staff_id
         ORDER BY staff_id ASC",
        'is',
        [$pharmacyId, sprintf('%04d-%%', $selectedYear)]
    );

    foreach ($ytdRows as &$yr) {
        $staff = $staffMap[(int)$yr['staff_id']] ?? [];
        $yr['staff_name'] =
            $staff['staff_name']
            ?? ('Staff #' . (int)$yr['staff_id']);
        $yr['role'] =
            $staff['staff_role']
            ?? 'Staff';
    }

    unset($yr);
}

/* ---------- Selected payroll record for the optional top payslip button ---------- */
/*
 * Payslip rendering, PDF generation, verification and email delivery are NOT
 * handled by this payroll controller. They live in /admin/actions/payslip.php.
 *
 * We only resolve the selected staff row here so the existing top-level
 * "Download Official PDF" button can remain available.
 */
$payslipStaffId = (int)($_GET['payslip'] ?? 0);
$payslip = null;

if ($payslipStaffId > 0) {
    foreach ($payrollRows as $selectedPayrollRow) {
        if ((int)($selectedPayrollRow['staff_id'] ?? 0) === $payslipStaffId) {
            $payslip = $selectedPayrollRow;
            break;
        }
    }
}

/* ---------- Shared shell ---------- */

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll | <?= payroll_complete_h($pharmacyName) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>
:root{
    --bg:#f4f6f8;
    --surface:#fff;
    --soft:#f8fafc;
    --charcoal:#202831;
    --text:#1d252d;
    --muted:#71808f;
    --border:#dfe4e9;
    --blue:#246bfe;
    --blue-soft:#eaf1ff;
    --green:#159a68;
    --green-soft:#e8f7f0;
    --yellow:#e7a72e;
    --yellow-soft:#fff6df;
    --red:#d94d61;
    --red-soft:#fff0f2;
    --radius:12px;
    --shadow:0 4px 18px rgba(31,40,49,.06);
}
*{box-sizing:border-box}
html,body{
    margin:0;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Arial,sans-serif;
    font-size:14px;
}
body{overflow-x:hidden}
button,input,select,textarea{font:inherit}
button,select{cursor:pointer}
a{text-decoration:none;color:inherit}
.main{margin-left:250px;min-height:100vh}
.payroll-content{max-width:1600px;margin:auto;padding:24px 28px 40px}

.page-heading{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:20px;
    margin-bottom:18px
}
.page-heading h1{
    margin:0;
    font-size:27px;
    line-height:1.1;
    font-weight:800;
    letter-spacing:-.6px
}
.page-heading p{
    margin:7px 0 0;
    color:var(--muted);
    font-size:12px
}
.page-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{
    min-height:39px;
    border:1px solid var(--border);
    background:#fff;
    color:#52606d;
    border-radius:8px;
    padding:0 13px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px
}
.btn:hover{
    border-color:#b8c7da;
    box-shadow:0 3px 10px rgba(30,50,80,.08)
}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn.green{background:var(--green);border-color:var(--green);color:#fff}
.btn.yellow{color:#a36d00;border-color:#ecd79b;background:#fffaf0}
.btn.small{min-height:32px;padding:0 9px;font-size:11px}

.notice{
    padding:11px 13px;
    margin-bottom:14px;
    border-radius:9px;
    border:1px solid;
    font-size:12px
}
.notice.success{background:var(--green-soft);border-color:#b9e8d1;color:#08754f}
.notice.error{background:var(--red-soft);border-color:#efc0c8;color:#b4233b}
.notice.info{background:var(--blue-soft);border-color:#cbdcff;color:#2458b8}

.payroll-tabs{
    display:flex;
    align-items:center;
    gap:5px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:6px;
    box-shadow:var(--shadow);
    margin-bottom:16px;
    overflow:auto
}
.payroll-tab{
    min-height:36px;
    padding:0 13px;
    border-radius:7px;
    color:#667482;
    font-size:12px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:7px;
    white-space:nowrap
}
.payroll-tab:hover{background:#f3f6fa;color:var(--text)}
.payroll-tab.active{background:var(--blue-soft);color:var(--blue)}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:13px;
    margin-bottom:16px
}
.summary-card{
    position:relative;
    overflow:hidden;
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px 17px;
    min-height:112px
}
.summary-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    color:#74808c;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.8px
}
.summary-icon{
    width:34px;height:34px;
    display:grid;place-items:center;
    border-radius:8px;
    background:var(--blue-soft);
    color:var(--blue)
}
.summary-value{
    margin-top:9px;
    font-size:23px;
    font-weight:800;
    letter-spacing:-.4px
}
.summary-sub{margin-top:4px;color:var(--muted);font-size:10px}
.summary-card.green .summary-icon{color:var(--green);background:var(--green-soft)}
.summary-card.yellow .summary-icon{color:var(--yellow);background:var(--yellow-soft)}
.summary-card.red .summary-icon{color:var(--red);background:var(--red-soft)}

.filter-panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    margin-bottom:14px
}
.filter-row{
    display:grid;
    grid-template-columns:minmax(220px,1.8fr) 180px 150px auto;
    gap:9px;
    align-items:center
}
.field{
    height:40px;
    border:1px solid var(--border);
    background:#fff;
    border-radius:8px;
    color:var(--text);
    outline:none;
    padding:0 11px;
    width:100%;
    font-size:12px
}
.field:focus{
    border-color:#8bb0ff;
    box-shadow:0 0 0 3px var(--blue-soft)
}
.search-wrap{position:relative}
.search-wrap i{
    position:absolute;left:12px;top:50%;
    transform:translateY(-50%);
    color:#93a0ad;font-size:12px;pointer-events:none
}
.search-wrap .field{padding-left:34px}
.filter-note{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:10px;
    color:var(--muted);
    font-size:10px
}

.panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
    margin-bottom:14px
}
.panel-head{
    min-height:61px;
    padding:0 17px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    border-bottom:1px solid var(--border)
}
.panel-title b{display:block;color:var(--charcoal);font-size:13px}
.panel-title span{display:block;color:var(--muted);font-size:10px;margin-top:4px}
.period-pill{
    background:#f5f7fa;
    border:1px solid var(--border);
    color:#53616e;
    border-radius:20px;
    padding:7px 11px;
    font-size:10px;
    font-weight:800;
    white-space:nowrap
}
.table-wrap{width:100%;overflow:auto}
table{
    width:100%;
    min-width:1050px;
    border-collapse:collapse
}
th{
    background:#f7f9fb;
    border-bottom:1px solid var(--border);
    color:#657382;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.55px;
    font-weight:800;
    text-align:left;
    padding:12px 13px;
    white-space:nowrap
}
td{
    border-bottom:1px solid #edf0f3;
    padding:10px 13px;
    vertical-align:middle;
    color:#53616d;
    font-size:11px
}
tbody tr:hover{background:#fbfcfd}
.money{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.employee{
    display:flex;align-items:center;gap:10px;min-width:210px
}
.avatar{
    width:34px;height:34px;flex:0 0 34px;
    display:grid;place-items:center;
    border-radius:9px;
    background:var(--blue-soft);
    color:var(--blue);
    font-size:11px;font-weight:800
}
.employee-name b{
    display:block;color:var(--charcoal);font-size:11px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    max-width:220px
}
.employee-name span{
    display:block;color:var(--muted);font-size:9px;margin-top:3px
}
.status{
    display:inline-flex;
    padding:5px 8px;
    border-radius:999px;
    font-size:9px;
    font-weight:800;
    text-transform:uppercase
}
.status.draft{background:#f1f5f9;color:#64748b}
.status.calculated{background:var(--blue-soft);color:var(--blue)}
.status.approved{background:var(--yellow-soft);color:#a36d00}
.status.paid{background:var(--green-soft);color:#08754f}
.status.locked{background:#eef0f2;color:#4b5563}
.payment-form{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin-right:8px}.payment-form label{display:flex;flex-direction:column;gap:4px;font-size:10px;font-weight:700;color:var(--muted)}.payment-form input,.payment-form select{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff;color:var(--text);font:inherit;font-size:12px}.payment-form input:focus,.payment-form select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-soft)}
.action-stack{display:flex;gap:5px;flex-wrap:wrap}

.calc-preview{
    display:grid;
    grid-template-columns:repeat(3,minmax(100px,1fr));
    gap:7px;
    flex:1 1 100%;
    margin-top:3px;
}
.calc-preview div{
    background:#f7f9fc;
    border:1px solid var(--border);
    border-radius:7px;
    padding:7px 9px;
}
.calc-preview span{display:block;color:var(--muted);font-size:9px}
.calc-preview strong{display:block;margin-top:2px;font-size:11px}
.calc-preview .net-preview{background:var(--green-soft);border-color:#bcebd8}
.field-help{display:block;color:var(--muted);font-size:8px;margin-top:3px}
@media(max-width:850px){.calc-preview{grid-template-columns:repeat(2,minmax(100px,1fr))}}

.row-form{
    display:grid;
    grid-template-columns:repeat(8,minmax(90px,1fr)) auto;
    gap:7px;
    padding:10px 13px;
    background:#fbfcfd;
    border-bottom:1px solid var(--border)
}
.row-form label{
    font-size:9px;color:var(--muted);
    display:flex;flex-direction:column;gap:3px
}
.row-form input{
    height:34px;
    border:1px solid var(--border);
    border-radius:7px;
    padding:0 8px;
    font-size:11px;
    min-width:0
}

.salary-panel{
    margin-bottom:14px
}
.salary-note{
    padding:12px 17px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    color:var(--muted);
    font-size:10px;
    line-height:1.6
}
.salary-table{
    min-width:760px;
}
.salary-form{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
}
.salary-input{
    width:145px;
    height:36px;
    border:1px solid var(--border);
    border-radius:7px;
    padding:0 9px;
    font-size:11px;
    outline:none;
}
.salary-input:focus{
    border-color:#8bb0ff;
    box-shadow:0 0 0 3px var(--blue-soft);
}
.salary-actions{
    white-space:nowrap;
}
.salary-master{
    color:var(--charcoal);
    font-weight:800;
}
@media(max-width:700px){
    .salary-form{
        justify-content:flex-start;
    }
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    padding:16px
}
.stat-card{
    border:1px solid var(--border);
    background:var(--soft);
    border-radius:9px;
    padding:13px
}
.stat-card span{display:block;color:var(--muted);font-size:10px;margin-bottom:6px}
.stat-card strong{font-size:17px}

.remit-form{
    padding:14px;
    background:#fbfcfd;
    border-top:1px solid var(--border)
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px
}
.form-field{display:flex;flex-direction:column;gap:5px}
.form-field.full{grid-column:1/-1}
.form-field label{font-size:10px;color:var(--muted);font-weight:700}
.form-field input,.form-field select,.form-field textarea{
    width:100%;
    min-height:38px;
    border:1px solid var(--border);
    border-radius:7px;
    padding:7px 9px;
    font-size:12px;
    background:#fff
}
.form-field textarea{min-height:65px;resize:vertical}

.empty{text-align:center;padding:45px!important;color:var(--muted)}
.footer-total{
    padding:13px 17px;
    display:flex;
    justify-content:space-between;
    gap:15px;
    color:var(--muted);
    font-size:11px
}
.net{color:var(--green);font-weight:800}

/* Salary Template UI */
.salary-template-intro{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:16px 18px;border-bottom:1px solid #e6ebf1;background:#fbfcfe}.salary-template-intro>div:first-child{display:flex;flex-direction:column;gap:4px}.salary-template-intro span{font-size:12px;color:#718096}.template-note{font-size:11px;color:#4d6b8a;background:#eef5ff;border:1px solid #d9e7ff;border-radius:10px;padding:10px 12px}.template-list th,.template-list td{white-space:nowrap}.salary-modal{position:fixed;inset:0;display:none;z-index:9999}.salary-modal.open{display:block}.salary-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.48)}.salary-modal-card{position:relative;width:min(1050px,calc(100% - 30px));max-height:92vh;overflow:auto;margin:4vh auto;background:#fff;border-radius:16px;box-shadow:0 25px 70px rgba(15,23,42,.25)}.salary-modal-head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #e7edf4}.salary-modal-head strong{display:block;font-size:18px}.salary-modal-head span{display:block;font-size:12px;color:#718096;margin-top:3px}.modal-close{border:0;background:#f1f5f9;width:34px;height:34px;border-radius:9px;font-size:22px;cursor:pointer}.template-grid,.component-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px 22px}.template-section{border:1px solid #e1e8f0;border-radius:12px;padding:16px;background:#fff}.template-section h3{margin:0 0 14px;font-size:14px;color:#243447}.template-section label{display:block;font-size:11px;font-weight:700;color:#607086;margin:10px 0 5px}.template-section input,.template-section select{width:100%;border:1px solid #d5dee8;border-radius:9px;padding:10px 11px;font:inherit;font-size:13px;outline:none;background:#fff}.template-section input:focus,.template-section select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:10px}.readonly-field{background:#f5f7fa!important;color:#526174}.template-preview{display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding:10px 12px;background:#f7f9fc;border-radius:9px;font-size:11px;color:#66758a}.template-preview strong{font-size:13px;color:#172437}.component-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.component-head h3{margin:0}.component-row{display:grid;grid-template-columns:1fr 120px 34px;gap:7px;margin-bottom:8px}.component-row input{min-width:0}.remove-component{border:1px solid #e1e7ef;background:#fff;border-radius:8px;color:#dc4c64;cursor:pointer}.salary-modal-foot{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:16px 22px;border-top:1px solid #e7edf4;background:#fbfcfe;font-size:11px;color:#718096}.salary-modal-foot>div{display:flex;gap:8px}.template-list .btn{white-space:nowrap}@media(max-width:800px){.salary-template-intro,.salary-modal-foot{flex-direction:column;align-items:stretch}.template-grid,.component-columns{grid-template-columns:1fr}.salary-modal-card{margin:2vh auto;max-height:96vh}.two-col{grid-template-columns:1fr}.component-row{grid-template-columns:1fr 100px 34px}}
</style>
</head>

<body>

<div class="app">

<?php require_once __DIR__ . '/admin_aside.php'; ?>

<main class="main">

<?php require_once __DIR__ . '/admin_header.php'; ?>

<section class="payroll-content">

<div class="page-heading">

<div>
<h1>Payroll</h1>
<p>
Complete payroll, salary setup, statutory calculations and payment records for
<?= payroll_complete_h($pharmacyName) ?>.
</p>
</div>

<div class="page-actions">

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="prepare_payroll">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn primary" type="submit">
<i class="fas fa-file-circle-plus"></i>
Prepare Payroll
</button>
</form>

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="recalculate">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button
    class="btn"
    type="submit"
    onclick="return confirm('Recalculate all editable payroll records for <?= payroll_complete_h($periodLabel) ?>?');"
>
<i class="fas fa-calculator"></i>
Recalculate
</button>
</form>

<?php if ($view === 'payroll' && in_array($periodStatus, ['approved','paid','locked'], true)): ?>
<form method="post" action="/admin/actions/payslip.php" onsubmit="return confirm('Email payslips to all employees with valid email addresses for <?= payroll_complete_h($periodLabel) ?>?');">
<input type="hidden" name="action" value="send_all_payslips">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn" type="submit">
<i class="fas fa-envelope"></i>
Email Payslips
</button>
</form>
<?php endif; ?>

<?php if ($payslip !== null && in_array((string)($payslip['status'] ?? ''), ['approved','paid','locked'], true)): ?>
<a class="btn" href="/admin/payslip_pdf.php?staff_id=<?= (int)$payslip['staff_id'] ?>&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&download=1">
<i class="fas fa-file-pdf"></i>
Download Official PDF
</a>
<?php else: ?>
<button class="btn" type="button" onclick="window.print()">
<i class="fas fa-print"></i>
Print
</button>
<?php endif; ?>

</div>
</div>

<?php if ($success !== ''): ?>
<div class="notice success">
<i class="fas fa-circle-check"></i>
<?= payroll_complete_h($success) ?>
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="notice error">
<i class="fas fa-circle-exclamation"></i>
<?= payroll_complete_h($error) ?>
</div>
<?php endif; ?>

<nav class="payroll-tabs">

<?php
$tabs = [
    'payroll' => ['fa-file-invoice-dollar','Payroll'],
    'salary' => ['fa-money-bill-wave','Salary Setup'],
    'statutory' => ['fa-landmark','Statutory'],
    'remittance' => ['fa-money-check-dollar','Remittance'],
    'history' => ['fa-clock-rotate-left','History'],
    'ytd' => ['fa-chart-line','YTD'],
];
?>

<?php foreach ($tabs as $key => $tab): ?>
<a
    class="payroll-tab <?= $view === $key ? 'active' : '' ?>"
    href="?view=<?= payroll_complete_h($key) ?>&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>"
>
<i class="fas <?= payroll_complete_h($tab[0]) ?>"></i>
<?= payroll_complete_h($tab[1]) ?>
</a>
<?php endforeach; ?>

</nav>

<?php if ($view === 'payroll'): ?>

<section class="summary-grid">

<div class="summary-card">
<div class="summary-top">
<span>Staff on Payroll</span>
<span class="summary-icon"><i class="fas fa-users"></i></span>
</div>
<div class="summary-value"><?= count($payrollRows) ?></div>
<div class="summary-sub"><?= payroll_complete_h($periodLabel) ?></div>
</div>

<div class="summary-card">
<div class="summary-top">
<span>Gross Payroll</span>
<span class="summary-icon"><i class="fas fa-coins"></i></span>
</div>
<div class="summary-value"><?= payroll_complete_money($totals['gross']) ?></div>
<div class="summary-sub">Total gross earnings</div>
</div>

<div class="summary-card yellow">
<div class="summary-top">
<span>Deductions</span>
<span class="summary-icon"><i class="fas fa-minus-circle"></i></span>
</div>
<div class="summary-value"><?= payroll_complete_money($totals['deductions']) ?></div>
<div class="summary-sub">PAYE + NAPSA + NHIMA + other</div>
</div>

<div class="summary-card green">
<div class="summary-top">
<span>Net Payroll</span>
<span class="summary-icon"><i class="fas fa-wallet"></i></span>
</div>
<div class="summary-value"><?= payroll_complete_money($totals['net']) ?></div>
<div class="summary-sub">Employee take-home</div>
</div>

</section>

<section class="filter-panel">

<form method="get">

<input type="hidden" name="view" value="payroll">
<input type="hidden" name="year" value="<?= $selectedYear ?>">

<div class="filter-row">

<div class="search-wrap">
<i class="fas fa-search"></i>
<input
    class="field"
    name="search"
    value="<?= payroll_complete_h($search) ?>"
    placeholder="Search employee, email or employee number..."
>
</div>

<select class="field" name="branch_id">
<option value="0">All branches</option>
<?php foreach ($branches as $branch): ?>
<option
    value="<?= (int)$branch['id'] ?>"
    <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>
>
<?= payroll_complete_h($branch['branch_name']) ?>
</option>
<?php endforeach; ?>
</select>

<select class="field" name="month">
<?php for ($m=1;$m<=12;$m++): ?>
<option
    value="<?= $m ?>"
    <?= $m === $selectedMonth ? 'selected' : '' ?>
>
<?= payroll_complete_h(date('F',mktime(0,0,0,$m,1,$selectedYear))) ?>
</option>
<?php endfor; ?>
</select>

<button class="btn primary" type="submit">
<i class="fas fa-filter"></i>
Apply
</button>

</div>

<div class="filter-note">
<span>
Payroll period:
<strong><?= payroll_complete_h($periodLabel) ?></strong>
</span>
<span>
Status:
<strong><?= payroll_complete_h(strtoupper($periodStatus)) ?></strong>
</span>
</div>

</form>
</section>

<section class="panel">

<div class="panel-head">
<div class="panel-title">
<b>Monthly Payroll Register</b>
<span>Calculate earnings, statutory deductions, net pay and employer cost before approval.</span>
</div>
<span class="period-pill"><?= payroll_complete_h($periodLabel) ?></span>
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Employee</th>
<th>Role</th>
<th>Status</th>
<th class="money">Basic</th>
<th class="money">Gross</th>
<th class="money">PAYE</th>
<th class="money">NAPSA</th>
<th class="money">NHIMA</th>
<th class="money">Deductions</th>
<th class="money">Net Pay</th>
<th>Payment</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$payrollRows): ?>

<tr>
<td colspan="12" class="empty">
<i class="fas fa-file-invoice-dollar" style="font-size:30px;margin-bottom:10px"></i>
<div>
<strong>No payroll has been prepared for <?= payroll_complete_h($periodLabel) ?>.</strong>
</div>
<div style="margin-top:5px;font-size:10px">
Click <strong>Prepare Payroll</strong> to create records from active staff.
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($payrollRows as $row): ?>

<tr>

<td>
<div class="employee">

<div class="avatar">
<?php
$initials = '';
foreach (
    preg_split('/\s+/', trim((string)$row['staff_name'])) ?: []
    as $part
) {
    if ($part !== '') {
        $initials .= strtoupper($part[0]);
    }
    if (strlen($initials) >= 2) break;
}
echo payroll_complete_h($initials ?: 'ST');
?>
</div>

<div class="employee-name">
<b><?= payroll_complete_h($row['staff_name']) ?></b>
<span>
#<?= $row['employee_number'] !== ''
    ? payroll_complete_h($row['employee_number'])
    : (int)$row['staff_id'] ?>
</span>
</div>

</div>
</td>

<td><?= payroll_complete_h($row['staff_role']) ?></td>

<td>
<span class="status <?= payroll_complete_h($row['status']) ?>">
<?= payroll_complete_h($row['status']) ?>
</span>
</td>

<td class="money"><?= payroll_complete_money($row['basic_salary']) ?></td>
<td class="money"><?= payroll_complete_money($row['gross_salary']) ?></td>
<td class="money"><?= payroll_complete_money($row['paye']) ?></td>
<td class="money"><?= payroll_complete_money($row['napsa']) ?></td>
<td class="money"><?= payroll_complete_money($row['nhima']) ?></td>
<td class="money"><?= payroll_complete_money($row['total_deductions']) ?></td>
<td class="money net"><?= payroll_complete_money($row['net_salary']) ?></td>

<td>
<?php if (($row['status'] ?? '') === 'paid' || ($row['status'] ?? '') === 'locked'): ?>
<div style="font-size:10px"><strong><?= payroll_complete_h($row['payment_method'] ?? 'Paid') ?></strong></div>
<div style="font-size:9px;color:var(--muted)"><?= payroll_complete_h($row['payment_reference'] ?? '') ?></div>
<div style="font-size:9px;color:var(--muted)"><?= !empty($row['payment_date']) ? payroll_complete_h(date('d M Y', strtotime($row['payment_date']))) : '' ?></div>
<?php else: ?>
<span style="font-size:10px;color:var(--muted)">Not paid</span>
<?php endif; ?>
</td>

<td>
<?php if (!empty($row['email'])): ?>
<div style="font-size:9px;color:var(--muted);margin-bottom:5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= payroll_complete_h($row['email']) ?>">
<i class="fas fa-envelope"></i> <?= payroll_complete_h($row['email']) ?>
<?php if (($row['payslip_email_status'] ?? 'pending') === 'sent'): ?>
<span style="color:#08754f;font-weight:700;"> Ã‚Â· SENT</span>
<?php elseif (($row['payslip_email_status'] ?? 'pending') === 'failed'): ?>
<span style="color:#b42318;font-weight:700;"> Ã‚Â· FAILED</span>
<?php endif; ?>
</div>
<?php else: ?>
<div style="font-size:9px;color:#b42318;margin-bottom:5px;">No email on staff record</div>
<?php endif; ?>

<div class="action-stack">

<?php if (!in_array(
    $row['status'],
    ['approved','paid','locked'],
    true
)): ?>

<button
    class="btn small"
    type="button"
    onclick="toggleEdit(<?= (int)$row['id'] ?>)"
>
<i class="fas fa-pen"></i>
</button>

<?php endif; ?>

<a
    class="btn small"
    href="/admin/payslip_pdf.php?staff_id=<?= (int)$row['staff_id'] ?>&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>"
    target="_self"
    title="Official Payslip PDF"
>
<i class="fas fa-receipt"></i>
</a>

<?php if (in_array((string)$row['status'], ['approved','paid','locked'], true)): ?>
<form method="post" action="/admin/actions/payslip.php" style="display:inline" onsubmit="return confirm('Email this payslip to <?= payroll_complete_h($row['email'] ?? '') ?>?');">
<input type="hidden" name="action" value="send_payslip_email">
<input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn small" type="submit" title="Email Payslip" <?= empty($row['email']) ? 'disabled' : '' ?>>
<i class="fas fa-envelope"></i>
</button>
</form>
<?php endif; ?>

</div>
</td>

</tr>

<?php if (!in_array(
    $row['status'],
    ['approved','paid','locked'],
    true
)): ?>

<tr id="edit-<?= (int)$row['id'] ?>" style="display:none">

<td colspan="12" style="padding:0">

<form method="post" class="row-form">

<input type="hidden" name="action" value="save_row">
<input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">

<label>
Basic Salary
<input
    type="number"
    step="0.01"
    min="0"
    name="basic_salary"
    value="<?= payroll_complete_h($row['basic_salary']) ?>"
    readonly
    title="Change the master salary in Salary Setup"
>
<small class="field-help">Managed in Salary Setup</small>
</label>

<label>
Allowances
<input
    type="number"
    step="0.01"
    min="0"
    name="allowances"
    value="<?= payroll_complete_h($row['allowances']) ?>"
>
</label>

<label>
Bonus
<input
    type="number"
    step="0.01"
    min="0"
    name="bonus"
    value="<?= payroll_complete_h($row['bonus']) ?>"
>
</label>

<label>
Overtime
<input
    type="number"
    step="0.01"
    min="0"
    name="overtime"
    value="<?= payroll_complete_h($row['overtime']) ?>"
>
</label>

<label>
Other Earnings
<input
    type="number"
    step="0.01"
    min="0"
    name="other_earnings"
    value="<?= payroll_complete_h($row['other_earnings']) ?>"
>
</label>

<label>
Loan
<input
    type="number"
    step="0.01"
    min="0"
    name="loan_deduction"
    value="<?= payroll_complete_h($row['loan_deduction']) ?>"
>
</label>

<label>
Advance
<input
    type="number"
    step="0.01"
    min="0"
    name="salary_advance"
    value="<?= payroll_complete_h($row['salary_advance']) ?>"
>
</label>

<label>
Other Deduction
<input
    type="number"
    step="0.01"
    min="0"
    name="other_deductions"
    value="<?= payroll_complete_h($row['other_deductions']) ?>"
>
</label>

<div class="calc-preview">
    <div><span>Gross</span><strong data-preview="gross">K0.00</strong></div>
    <div><span>PAYE</span><strong data-preview="paye">K0.00</strong></div>
    <div><span>NAPSA</span><strong data-preview="napsa">K0.00</strong></div>
    <div><span>NHIMA</span><strong data-preview="nhima">K0.00</strong></div>
    <div><span>Total deductions</span><strong data-preview="deductions">K0.00</strong></div>
    <div class="net-preview"><span>Net Pay</span><strong data-preview="net">K0.00</strong></div>
</div>

<button class="btn small green" type="submit">
<i class="fas fa-calculator"></i>
Save &amp; Calculate
</button>

</form>

</td>
</tr>

<?php endif; ?>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

<div class="footer-total">
<span><?= count($payrollRows) ?> employee(s)</span>
<span>
Employer cost:
<strong><?= payroll_complete_money($employerCost) ?></strong>
</span>
</div>

</section>

<?php if ($payrollRows): ?>

<section class="panel">

<div class="panel-head">

<div class="panel-title">
<b>Payroll Workflow</b>
<span>Controlled payroll sequence.</span>
</div>

<div class="action-stack">

<?php if ($periodStatus === 'calculated'): ?>

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="approve">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button
    class="btn yellow"
    type="submit"
    onclick="return confirm('Approve payroll for <?= payroll_complete_h($periodLabel) ?>?');"
>
<i class="fas fa-stamp"></i>
Approve
</button>
</form>

<?php elseif ($periodStatus === 'approved'): ?>

<form method="post" action="/admin/actions/payroll.php" class="payment-form" onsubmit="return confirm('Record this payroll as PAID? Make sure the payment was actually made.');">
<input type="hidden" name="action" value="mark_paid">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<label>
Payment Date
<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
</label>
<label>
Payment Method
<select name="payment_method" required>
<option>Bank Transfer</option>
<option>Mobile Money</option>
<option>Cash</option>
<option>Cheque</option>
<option>Other</option>
</select>
</label>
<label>
Payment Reference
<input type="text" name="payment_reference" maxlength="150" placeholder="e.g. BANK-PAY-2026-08-001" required>
</label>
<button class="btn green" type="submit">
<i class="fas fa-money-bill-transfer"></i>
Record Payment &amp; Mark Paid
</button>
</form>

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="reopen">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn yellow" type="submit" onclick="return confirm('Reopen this approved payroll for editing?');">Reopen</button>
</form>

<?php elseif ($periodStatus === 'paid'): ?>

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="lock">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button
    class="btn"
    type="submit"
    onclick="return confirm('Lock this paid payroll? Locked payroll cannot be edited.');"
>
<i class="fas fa-lock"></i>
Lock Payroll
</button>
</form>

<?php else: ?>

<span class="status <?= payroll_complete_h($periodStatus) ?>">
<?= payroll_complete_h($periodStatus) ?>
</span>

<?php endif; ?>

</div>
</div>

<div class="stat-grid">

<div class="stat-card">
<span>Current Status</span>
<strong><?= payroll_complete_h(ucfirst($periodStatus)) ?></strong>
</div>

<div class="stat-card">
<span>PAYE</span>
<strong><?= payroll_complete_money($totals['paye']) ?></strong>
</div>

<div class="stat-card">
<span>Employee NAPSA</span>
<strong><?= payroll_complete_money($totals['napsa']) ?></strong>
</div>

<div class="stat-card">
<span>Employee NHIMA</span>
<strong><?= payroll_complete_money($totals['nhima']) ?></strong>
</div>

<div class="stat-card">
<span>Employer Cost</span>
<strong><?= payroll_complete_money($employerCost) ?></strong>
</div>

</div>
</section>

<?php endif; ?>


<?php elseif ($view === 'salary'): ?>

<section class="panel salary-template-panel">
<div class="panel-head">
<div class="panel-title">
<b>Salary Templates</b>
<span>Configure each employee's complete recurring salary structure.</span>
</div>
<span class="period-pill"><i class="fas fa-layer-group"></i> Payroll controlled</span>
</div>

<div class="salary-template-intro">
    <div>
        <strong>Employee salary template</strong>
        <span>Basic salary, grade, allowances, deductions, gratuity, overtime and payroll payment details are maintained here.</span>
    </div>
    <div class="template-note"><i class="fas fa-circle-info"></i> The template becomes the starting point when you prepare a monthly payroll.</div>
</div>

<div class="table-wrap">
<table class="salary-table template-list">
<thead><tr>
<th>Employee</th><th>Grade</th><th>Currency</th><th class="money">Basic</th><th class="money">Recurring Allowances</th><th class="money">Recurring Deductions</th><th>Overtime Rate</th><th>Template</th>
</tr></thead>
<tbody>
<?php if (!$staffRows): ?>
<tr><td colspan="8" class="empty"><i class="fas fa-users-slash" style="font-size:30px;margin-bottom:10px"></i><div><strong>No staff members found.</strong></div></td></tr>
<?php else: foreach ($staffRows as $staff):
    $sid = (int)$staff['staff_id'];
    $tpl = $salaryTemplates[$sid] ?? null;
    $allowanceTotal = $tpl ? payroll_template_components_sum($tpl['allowances_json'] ?? '[]') : 0;
    $deductionTotal = $tpl ? payroll_template_components_sum($tpl['deductions_json'] ?? '[]') : 0;
    $grade = $tpl['grade_name'] ?? '';
    $currency = $tpl['currency'] ?? 'ZMW';
    $basic = $tpl ? (float)$tpl['basic_salary'] : (float)$staff['basic_salary'];
    $overtimeRate = $tpl ? (float)$tpl['overtime_rate'] : 0;
    $name = (string)$staff['staff_name'];
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) { if ($part !== '') { $initials .= strtoupper($part[0]); } if (strlen($initials) >= 2) break; }
    if (!$initials) $initials='ST';
?>
<tr>
<td><div class="employee"><div class="avatar"><?= payroll_complete_h($initials) ?></div><div class="employee-name"><b><?= payroll_complete_h($name) ?></b><span>#<?= !empty($staff['employee_number']) ? payroll_complete_h($staff['employee_number']) : $sid ?></span></div></div></td>
<td><?= payroll_complete_h($grade ?: 'Not assigned') ?></td>
<td><?= payroll_complete_h($currency) ?></td>
<td class="money salary-master"><?= payroll_complete_h($currency) ?> <?= number_format($basic,2) ?></td>
<td class="money"><?= payroll_complete_h($currency) ?> <?= number_format($allowanceTotal,2) ?></td>
<td class="money"><?= payroll_complete_h($currency) ?> <?= number_format($deductionTotal,2) ?></td>
<td><?= payroll_complete_h($currency) ?> <?= number_format($overtimeRate,2) ?>/hr</td>
<td><button type="button" class="btn small <?= $tpl ? 'green' : 'primary' ?>" onclick="openSalaryTemplate(<?= $sid ?>)"><i class="fas <?= $tpl ? 'fa-pen-to-square' : 'fa-plus' ?>"></i> <?= $tpl ? 'Edit' : 'Create' ?></button></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</section>

<div id="salaryTemplateModal" class="salary-modal" aria-hidden="true">
<div class="salary-modal-backdrop" onclick="closeSalaryTemplate()"></div>
<div class="salary-modal-card">
<div class="salary-modal-head"><div><strong>Employee Salary Template</strong><span>Recurring compensation and payment profile</span></div><button type="button" class="modal-close" onclick="closeSalaryTemplate()">&times;</button></div>
<form method="post" id="salaryTemplateForm" onsubmit="return prepareSalaryTemplate()">
<input type="hidden" name="action" value="save_salary_template">
<input type="hidden" name="staff_id" id="tpl_staff_id" value="">
<input type="hidden" name="month" value="<?= $selectedMonth ?>"><input type="hidden" name="year" value="<?= $selectedYear ?>">
<div class="template-grid">
<div class="template-section"><h3>Salary</h3>
<label>Employee</label><input id="tpl_employee" class="readonly-field" readonly>
<div class="two-col"><div><label>Salary Grade</label><input name="grade_name" id="tpl_grade" placeholder="e.g. Grade B2"></div><div><label>Currency</label><select name="currency" id="tpl_currency"><option>ZMW</option><option>USD</option><option>EUR</option><option>GBP</option></select></div></div>
<div class="two-col"><div><label>Basic Salary</label><input type="number" min="0" step="0.01" name="basic_salary" id="tpl_basic" required></div><div><label>Gratuity / Month</label><input type="number" min="0" step="0.01" name="gratuity_amount" id="tpl_gratuity"></div></div>
<div class="two-col"><div><label>Hourly Overtime Rate</label><input type="number" min="0" step="0.01" name="overtime_rate" id="tpl_overtime"></div><div><label>Monthly Allowed Leave</label><input type="number" min="0" step="0.01" name="monthly_allowed_leaves" id="tpl_leaves"></div></div>
<label>Effective From</label><input type="date" name="effective_from" id="tpl_effective">
</div>
<div class="template-section"><h3>Payment Details</h3>
<label>Bank Name</label><input name="bank_name" id="tpl_bank" placeholder="Bank name">
<label>Account Name</label><input name="account_name" id="tpl_account_name" placeholder="Account holder name">
<label>Account Number</label><input name="account_number" id="tpl_account_number" placeholder="Account number">
<div class="template-preview"><span>Calculated Monthly Gross</span><strong id="tpl_gross">K0.00</strong></div>
<div class="template-preview"><span>Recurring Deductions</span><strong id="tpl_deduction_total">K0.00</strong></div>
</div>
</div>
<div class="component-columns">
<div class="template-section"><div class="component-head"><h3>Recurring Allowances</h3><button type="button" class="btn small" onclick="addTemplateComponent('allowance')"><i class="fas fa-plus"></i> Add</button></div><div id="allowanceRows"></div></div>
<div class="template-section"><div class="component-head"><h3>Recurring Deductions</h3><button type="button" class="btn small" onclick="addTemplateComponent('deduction')"><i class="fas fa-plus"></i> Add</button></div><div id="deductionRows"></div></div>
</div>
<div class="salary-modal-foot"><span>Changes are owned by Payroll and will be used on the next editable payroll preparation.</span><div><button type="button" class="btn" onclick="closeSalaryTemplate()">Cancel</button><button type="submit" class="btn primary"><i class="fas fa-save"></i> Save Salary Template</button></div></div>
</form>
</div>
</div>

<script>
const salaryTemplateData = <?= json_encode(array_map(function($staff) use ($salaryTemplates) {
    $sid=(int)$staff['staff_id']; $tpl=$salaryTemplates[$sid]??null;
    return ['id'=>$sid,'name'=>(string)$staff['staff_name'],'grade'=>(string)($tpl['grade_name']??''),'currency'=>(string)($tpl['currency']??'ZMW'),'basic'=>(float)($tpl['basic_salary']??$staff['basic_salary']??0),'gratuity'=>(float)($tpl['gratuity_amount']??0),'overtime'=>(float)($tpl['overtime_rate']??0),'leaves'=>(float)($tpl['monthly_allowed_leaves']??0),'bank'=>(string)($tpl['bank_name']??''),'accountName'=>(string)($tpl['account_name']??''),'accountNumber'=>(string)($tpl['account_number']??''),'effective'=>(string)($tpl['effective_from']??date('Y-m-d')),'allowances'=>payroll_template_components_decode($tpl['allowances_json']??'[]'),'deductions'=>payroll_template_components_decode($tpl['deductions_json']??'[]')];
}, $staffRows)) ?>;
function tplMoney(v,c='ZMW'){return c+' '+Number(v||0).toLocaleString('en-ZM',{minimumFractionDigits:2,maximumFractionDigits:2});}
function openSalaryTemplate(id){const d=salaryTemplateData.find(x=>Number(x.id)===Number(id));if(!d)return;document.getElementById('tpl_staff_id').value=d.id;document.getElementById('tpl_employee').value=d.name;document.getElementById('tpl_grade').value=d.grade;document.getElementById('tpl_currency').value=d.currency;document.getElementById('tpl_basic').value=d.basic;document.getElementById('tpl_gratuity').value=d.gratuity;document.getElementById('tpl_overtime').value=d.overtime;document.getElementById('tpl_leaves').value=d.leaves;document.getElementById('tpl_bank').value=d.bank;document.getElementById('tpl_account_name').value=d.accountName;document.getElementById('tpl_account_number').value=d.accountNumber;document.getElementById('tpl_effective').value=d.effective||new Date().toISOString().slice(0,10);document.getElementById('allowanceRows').innerHTML='';document.getElementById('deductionRows').innerHTML='';(d.allowances||[]).forEach(x=>addTemplateComponent('allowance',x.name,x.amount));(d.deductions||[]).forEach(x=>addTemplateComponent('deduction',x.name,x.amount));if(!(d.allowances||[]).length)addTemplateComponent('allowance');if(!(d.deductions||[]).length)addTemplateComponent('deduction');document.getElementById('salaryTemplateModal').classList.add('open');document.getElementById('salaryTemplateModal').setAttribute('aria-hidden','false');updateTemplatePreview();}
function closeSalaryTemplate(){const m=document.getElementById('salaryTemplateModal');m.classList.remove('open');m.setAttribute('aria-hidden','true');}
function addTemplateComponent(type,name='',amount=''){const box=document.getElementById(type==='allowance'?'allowanceRows':'deductionRows');const row=document.createElement('div');row.className='component-row';const n=type==='allowance'?'allowance_name[]':'deduction_name[]';const a=type==='allowance'?'allowance_amount[]':'deduction_amount[]';row.innerHTML='<input name="'+n+'" placeholder="Component name" value="'+String(name).replace(/"/g,'&quot;')+'"><input type="number" min="0" step="0.01" name="'+a+'" placeholder="Amount" value="'+Number(amount||0)+'"><button type="button" class="remove-component" onclick="this.parentElement.remove();updateTemplatePreview()"><i class="fas fa-xmark"></i></button>';box.appendChild(row);updateTemplatePreview();}
function updateTemplatePreview(){const basic=Number(document.getElementById('tpl_basic')?.value||0);let a=0,d=0;document.querySelectorAll('#allowanceRows input[name="allowance_amount[]"]').forEach(x=>a+=Number(x.value||0));document.querySelectorAll('#deductionRows input[name="deduction_amount[]"]').forEach(x=>d+=Number(x.value||0));const c=document.getElementById('tpl_currency')?.value||'ZMW';document.getElementById('tpl_gross').textContent=tplMoney(basic+a,c);document.getElementById('tpl_deduction_total').textContent=tplMoney(d,c);}
document.addEventListener('input',e=>{if(e.target.closest('#salaryTemplateForm'))updateTemplatePreview();});
function prepareSalaryTemplate(){const basic=Number(document.getElementById('tpl_basic').value||0);if(basic<0){alert('Basic salary cannot be negative.');return false;}return true;}
</script>

<?php elseif ($view === 'statutory'): ?>


<section class="summary-grid">

<div class="summary-card">
<div class="summary-top"><span>PAYE</span><span class="summary-icon"><i class="fas fa-landmark"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totals['paye']) ?></div>
<div class="summary-sub">Employee tax liability</div>
</div>

<div class="summary-card">
<div class="summary-top"><span>NAPSA Employee</span><span class="summary-icon"><i class="fas fa-person"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totals['napsa']) ?></div>
<div class="summary-sub">Employee contribution</div>
</div>

<div class="summary-card">
<div class="summary-top"><span>NAPSA Employer</span><span class="summary-icon"><i class="fas fa-building"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totals['employer_napsa']) ?></div>
<div class="summary-sub">Employer contribution</div>
</div>

<div class="summary-card green">
<div class="summary-top"><span>NHIMA Total</span><span class="summary-icon"><i class="fas fa-heart-pulse"></i></span></div>
<div class="summary-value">
<?= payroll_complete_money($totals['nhima'] + $totals['employer_nhima']) ?>
</div>
<div class="summary-sub">Employee + employer</div>
</div>

</section>

<div class="notice info">
<strong>Statutory engine:</strong>
PAYE is progressive; NAPSA is 5% employee and 5% employer subject
to the applicable ceiling; NHIMA is 1% employee and 1% employer
of basic salary.
</div>

<section class="panel">

<div class="panel-head">
<div class="panel-title">
<b>Statutory Payroll Register</b>
<span><?= payroll_complete_h($periodLabel) ?></span>
</div>

<form method="post" action="/admin/actions/payroll.php">
<input type="hidden" name="action" value="recalculate">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn primary" type="submit">
<i class="fas fa-calculator"></i>
Recalculate
</button>
</form>
</div>

<div class="table-wrap">

<table>
<thead>
<tr>
<th>Employee</th>
<th class="money">Gross</th>
<th class="money">PAYE</th>
<th class="money">NAPSA Employee</th>
<th class="money">NHIMA Employee</th>
<th class="money">NAPSA Employer</th>
<th class="money">NHIMA Employer</th>
<th class="money">Net</th>
</tr>
</thead>

<tbody>

<?php if (!$payrollRows): ?>

<tr><td colspan="8" class="empty">Prepare payroll first.</td></tr>

<?php else: ?>

<?php foreach ($payrollRows as $row): ?>

<tr>

<td>
<strong><?= payroll_complete_h($row['staff_name']) ?></strong>
<div style="font-size:9px;color:var(--muted)">
<?= payroll_complete_h($row['staff_role']) ?>
</div>
</td>

<td class="money"><?= payroll_complete_money($row['gross_salary']) ?></td>
<td class="money"><?= payroll_complete_money($row['paye']) ?></td>
<td class="money"><?= payroll_complete_money($row['napsa']) ?></td>
<td class="money"><?= payroll_complete_money($row['nhima']) ?></td>
<td class="money"><?= payroll_complete_money($row['employer_napsa']) ?></td>
<td class="money"><?= payroll_complete_money($row['employer_nhima']) ?></td>
<td class="money net"><?= payroll_complete_money($row['net_salary']) ?></td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>

</div>
</section>

<?php elseif ($view === 'remittance'): ?>

<section class="summary-grid">

<div class="summary-card">
<div class="summary-top"><span>Total Liability</span><span class="summary-icon"><i class="fas fa-scale-balanced"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totalLiability) ?></div>
<div class="summary-sub"><?= payroll_complete_h($periodLabel) ?></div>
</div>

<div class="summary-card green">
<div class="summary-top"><span>Paid</span><span class="summary-icon"><i class="fas fa-circle-check"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totalPaid) ?></div>
<div class="summary-sub">Recorded as paid</div>
</div>

<div class="summary-card red">
<div class="summary-top"><span>Outstanding</span><span class="summary-icon"><i class="fas fa-clock"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($totalOutstanding) ?></div>
<div class="summary-sub">Pending / submitted</div>
</div>

<div class="summary-card yellow">
<div class="summary-top"><span>Due Date</span><span class="summary-icon"><i class="fas fa-calendar-day"></i></span></div>
<div class="summary-value" style="font-size:18px">
<?= payroll_complete_h($dueDate->format('d M Y')) ?>
</div>
<div class="summary-sub">10th of following month</div>
</div>

</section>

<section class="panel">

<div class="panel-head">

<div class="panel-title">
<b>Statutory Remittance Register</b>
<span>Track filing, payment and reference numbers.</span>
</div>

<span class="period-pill"><?= payroll_complete_h($periodLabel) ?></span>

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Statutory</th>
<th class="money">Employee</th>
<th class="money">Employer</th>
<th class="money">Liability</th>
<th>Due</th>
<th>Status</th>
<th>Reference</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($remittances as $remit): ?>

<tr>

<td>
<strong><?= payroll_complete_h($remit['statutory_type']) ?></strong>
<div style="font-size:9px;color:var(--muted)">
<?= $remit['statutory_type'] === 'PAYE' ? 'ZRA' : payroll_complete_h($remit['statutory_type']) ?>
</div>
</td>

<td class="money"><?= payroll_complete_money((float)$remit['employee_amount']) ?></td>
<td class="money"><?= payroll_complete_money((float)$remit['employer_amount']) ?></td>
<td class="money"><strong><?= payroll_complete_money((float)$remit['liability_amount']) ?></strong></td>
<td><?= payroll_complete_h(date('d M Y',strtotime($remit['due_date']))) ?></td>

<td>
<span class="status <?= payroll_complete_h($remit['display_status']) ?>">
<?= payroll_complete_h($remit['display_status']) ?>
</span>
</td>

<td>
<?= payroll_complete_h(
    $remit['payment_reference']
    ?: $remit['return_reference']
    ?: 'â€”'
) ?>
</td>

<td>
<button
    class="btn small"
    type="button"
    onclick="toggleRemittance(<?= (int)$remit['id'] ?>)"
>
<i class="fas fa-pen"></i>
Update
</button>
</td>

</tr>

<tr id="remit-<?= (int)$remit['id'] ?>" style="display:none">

<td colspan="8" style="padding:0">

<form method="post" class="remit-form">

<input type="hidden" name="action" value="save_remittance">
<input type="hidden" name="remittance_id" value="<?= (int)$remit['id'] ?>">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">

<div class="form-grid">

<div class="form-field">
<label>Status</label>
<select name="return_status">
<?php foreach (['pending','submitted','paid','overdue'] as $status): ?>
<option
    value="<?= payroll_complete_h($status) ?>"
    <?= $remit['return_status'] === $status ? 'selected' : '' ?>
>
<?= payroll_complete_h(ucfirst($status)) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="form-field">
<label>Payment Date</label>
<input
    type="date"
    name="payment_date"
    value="<?= payroll_complete_h($remit['payment_date'] ?? '') ?>"
>
</div>

<div class="form-field">
<label>Payment Reference</label>
<input
    name="payment_reference"
    value="<?= payroll_complete_h($remit['payment_reference'] ?? '') ?>"
    placeholder="Bank / portal reference"
>
</div>

<div class="form-field">
<label>Return Reference</label>
<input
    name="return_reference"
    value="<?= payroll_complete_h($remit['return_reference'] ?? '') ?>"
    placeholder="Filing reference"
>
</div>

<div class="form-field full">
<label>Notes</label>
<textarea name="notes" placeholder="Filing/payment notes..."><?= payroll_complete_h($remit['notes'] ?? '') ?></textarea>
</div>

</div>

<div class="action-stack" style="justify-content:flex-end;margin-top:10px">

<button class="btn green" type="submit">
<i class="fas fa-save"></i>
Save
</button>

<button
    class="btn"
    type="button"
    onclick="toggleRemittance(<?= (int)$remit['id'] ?>)"
>
Cancel
</button>

</div>

</form>

</td>
</tr>

<?php endforeach; ?>

</tbody>

</table>
</div>

<div class="footer-total">
<span><?= count($remittances) ?> statutory record(s)</span>
<span>
Outstanding:
<strong style="color:var(--red)">
<?= payroll_complete_money($totalOutstanding) ?>
</strong>
</span>
</div>

</section>

<?php elseif ($view === 'history'): ?>

<section class="panel">

<div class="panel-head">
<div class="panel-title">
<b>Payroll History</b>
<span>Monthly payroll totals.</span>
</div>
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Period</th>
<th>Employees</th>
<th class="money">Gross</th>
<th class="money">PAYE</th>
<th class="money">NAPSA</th>
<th class="money">NHIMA</th>
<th class="money">Deductions</th>
<th class="money">Net</th>
<th>Open</th>
</tr>
</thead>

<tbody>

<?php if (!$history): ?>

<tr><td colspan="9" class="empty">No payroll history found.</td></tr>

<?php else: ?>

<?php foreach ($history as $item): ?>

<?php
$historyDate = $item['payroll_period'] . '-01';
$hm = (int)date('n',strtotime($historyDate));
$hy = (int)date('Y',strtotime($historyDate));
?>

<tr>

<td><strong><?= payroll_complete_h(date('F Y',strtotime($historyDate))) ?></strong></td>
<td><?= (int)$item['staff_count'] ?></td>
<td class="money"><?= payroll_complete_money((float)$item['gross']) ?></td>
<td class="money"><?= payroll_complete_money((float)$item['paye']) ?></td>
<td class="money"><?= payroll_complete_money((float)$item['napsa']) ?></td>
<td class="money"><?= payroll_complete_money((float)$item['nhima']) ?></td>
<td class="money"><?= payroll_complete_money((float)$item['deductions']) ?></td>
<td class="money net"><?= payroll_complete_money((float)$item['net']) ?></td>

<td>
<a
    class="btn small"
    href="?view=payroll&month=<?= $hm ?>&year=<?= $hy ?>"
>
Open
</a>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</section>

<?php elseif ($view === 'ytd'): ?>

<?php
$ytdTotals = [
    'basic'=>0.0,
    'gross'=>0.0,
    'paye'=>0.0,
    'napsa'=>0.0,
    'nhima'=>0.0,
    'deductions'=>0.0,
    'net'=>0.0
];

foreach ($ytdRows as $yr) {
    foreach ($ytdTotals as $key => $_) {
        $ytdTotals[$key] += (float)($yr[$key] ?? 0);
    }
}
?>

<section class="summary-grid">

<div class="summary-card">
<div class="summary-top"><span>YTD Gross</span><span class="summary-icon"><i class="fas fa-coins"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($ytdTotals['gross']) ?></div>
<div class="summary-sub"><?= $selectedYear ?></div>
</div>

<div class="summary-card yellow">
<div class="summary-top"><span>YTD PAYE</span><span class="summary-icon"><i class="fas fa-landmark"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($ytdTotals['paye']) ?></div>
<div class="summary-sub">PAYE accumulated</div>
</div>

<div class="summary-card">
<div class="summary-top"><span>YTD NAPSA</span><span class="summary-icon"><i class="fas fa-piggy-bank"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($ytdTotals['napsa']) ?></div>
<div class="summary-sub">Employee contribution</div>
</div>

<div class="summary-card green">
<div class="summary-top"><span>YTD Net</span><span class="summary-icon"><i class="fas fa-wallet"></i></span></div>
<div class="summary-value"><?= payroll_complete_money($ytdTotals['net']) ?></div>
<div class="summary-sub">Employee take-home</div>
</div>

</section>

<section class="panel">

<div class="panel-head">
<div class="panel-title">
<b>Employee Year-to-Date Register</b>
<span>January through <?= payroll_complete_h($periodLabel) ?>, <?= $selectedYear ?>.</span>
</div>
</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Employee</th>
<th>Role</th>
<th class="money">Basic YTD</th>
<th class="money">Gross YTD</th>
<th class="money">PAYE YTD</th>
<th class="money">NAPSA YTD</th>
<th class="money">NHIMA YTD</th>
<th class="money">Deductions YTD</th>
<th class="money">Net YTD</th>
</tr>
</thead>

<tbody>

<?php if (!$ytdRows): ?>

<tr><td colspan="9" class="empty">No YTD payroll records found for <?= $selectedYear ?>.</td></tr>

<?php else: ?>

<?php foreach ($ytdRows as $yr): ?>

<tr>

<td>
<strong><?= payroll_complete_h($yr['staff_name']) ?></strong>
<div style="font-size:9px;color:var(--muted)">
#<?= (int)$yr['staff_id'] ?>
</div>
</td>

<td><?= payroll_complete_h($yr['role']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['basic']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['gross']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['paye']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['napsa']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['nhima']) ?></td>
<td class="money"><?= payroll_complete_money((float)$yr['deductions']) ?></td>
<td class="money net"><?= payroll_complete_money((float)$yr['net']) ?></td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</section>

<?php endif; ?>

</section><!-- /.payroll-content -->
</main><!-- /.main -->
</div><!-- /.app -->

<script>
function toggleEdit(id){
    const row = document.getElementById('edit-' + id);
    if(!row) return;
    const opening = row.style.display === 'none' || row.style.display === '';
    row.style.display = opening ? 'table-row' : 'none';
    if(opening) calculatePayrollPreview(row);
}

function toggleRemittance(id){
    const row = document.getElementById('remit-' + id);
    if(!row) return;
    row.style.display =
        row.style.display === 'none' || row.style.display === ''
            ? 'table-row'
            : 'none';
}

function money(v){
    return 'K' + Number(v || 0).toLocaleString('en-ZM',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function calculatePayrollPreview(row){
    if(!row) return;
    const value = name => Number(row.querySelector('[name="' + name + '"]')?.value || 0);

    const basic = Math.max(0,value('basic_salary'));
    const allowances = Math.max(0,value('allowances'));
    const bonus = Math.max(0,value('bonus'));
    const overtime = Math.max(0,value('overtime'));
    const otherEarnings = Math.max(0,value('other_earnings'));
    const loan = Math.max(0,value('loan_deduction'));
    const advance = Math.max(0,value('salary_advance'));
    const otherDeductions = Math.max(0,value('other_deductions'));

    const gross = basic + allowances + bonus + overtime + otherEarnings;

    let paye = 0;
    if(gross > 5100) paye += Math.min(gross,7100) - 5100 > 0 ? (Math.min(gross,7100)-5100)*0.20 : 0;
    if(gross > 7100) paye += Math.min(gross,9200) - 7100 > 0 ? (Math.min(gross,9200)-7100)*0.30 : 0;
    if(gross > 9200) paye += (gross-9200)*0.37;

    const napsa = Math.min(Math.min(gross,57840.60)*0.05,2892.03);
    const nhima = basic*0.01;
    const other = loan + advance + otherDeductions;
    const deductions = paye + napsa + nhima + other;
    const net = Math.max(0,gross-deductions);

    const set = (key,val) => {
        const el = row.querySelector('[data-preview="' + key + '"]');
        if(el) el.textContent = money(val);
    };

    set('gross',gross);
    set('paye',paye);
    set('napsa',napsa);
    set('nhima',nhima);
    set('deductions',deductions);
    set('net',net);
}

document.addEventListener('input',function(e){
    const row = e.target.closest('tr[id^="edit-"]');
    if(row) calculatePayrollPreview(row);
});

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('tr[id^="edit-"]').forEach(calculatePayrollPreview);
});
</script>

</body>
</html>
