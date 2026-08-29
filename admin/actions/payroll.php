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
 *   - Printable payslip
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
    $url = '../payroll.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
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

/* ---------- POST actions ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {

    $action = (string)($_POST['action'] ?? '');

    /*
     * Salary Setup:
     * Payroll is the only module allowed to change the master salary.
     * The staff-management page deliberately does not expose this field.
     */
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
                pharmacy_id,
                branch_id,
                staff_id,
                payroll_period,
                basic_salary,
                allowances,
                bonus,
                overtime,
                other_earnings,
                paye,
                napsa,
                nhima,
                gross_salary,
                total_deductions,
                net_salary,
                employer_napsa,
                employer_nhima,
                statutory_year,
                statutory_calculated_at,
                status,
                created_by,
                updated_by
            )
            VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'calculated', ?, ?)
            ON DUPLICATE KEY UPDATE
                branch_id = VALUES(branch_id),
                basic_salary = VALUES(basic_salary),
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
            $basic = max(0, (float)$staff['basic_salary']);

            /*
             * Phase 2: preparing payroll also performs the base calculation.
             * This means Basic Salary immediately produces Gross, PAYE, NAPSA,
             * NHIMA and Net Pay instead of leaving the register at K0.00.
             * Additional earnings/deductions remain zero until the payroll
             * editor is used.
             */
            $calc = payroll_complete_calculate(
                $basic,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0
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
                'iiisdddddddddiss',
                $pharmacyId,
                $branchId,
                $staffId,
                $period,
                $basic,
                $paye,
                $napsa,
                $nhima,
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

    foreach ([
        'approve' => ['from' => 'calculated', 'to' => 'approved'],
        'mark_paid' => ['from' => 'approved', 'to' => 'paid'],
        'lock' => ['from' => 'paid', 'to' => 'locked'],
        'reopen' => ['from' => 'approved', 'to' => 'calculated'],
    ] as $workflowAction => $flow) {

        if ($action !== $workflowAction) {
            continue;
        }

        $stmt = $conn->prepare("
            UPDATE payroll_records
            SET status = ?, updated_by = ?
            WHERE pharmacy_id = ?
              AND payroll_period = ?
              AND status = ?
        ");

        if ($stmt) {

            $to = $flow['to'];
            $user = payroll_complete_user();
            $from = $flow['from'];

            $stmt->bind_param(
                'ssiss',
                $to,
                $user,
                $pharmacyId,
                $period,
                $from
            );

            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            payroll_complete_redirect([
                'month' => $selectedMonth,
                'year' => $selectedYear,
                'view' => 'payroll',
                'saved' => $changed . ' payroll record(s) moved to ' . strtoupper($to) . '.'
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

/* ---------- Payslip ---------- */

$payslipStaffId = (int)($_GET['payslip'] ?? 0);
$payslip = null;

if ($payslipStaffId > 0) {
    foreach ($payrollRows as $row) {
        if ((int)$row['staff_id'] === $payslipStaffId) {
            $payslip = $row;
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

.payslip-sheet{
    display:none;
    width:800px;
    max-width:100%;
    margin:0 auto;
    background:#fff;
    padding:30px;
}
.payslip-sheet .company{
    text-align:center;
    border-bottom:2px solid var(--charcoal);
    padding-bottom:16px
}
.payslip-sheet h2{margin:0 0 5px}
.payslip-sheet p{margin:3px;color:var(--muted)}
.ps-heading{
    display:flex;
    justify-content:space-between;
    margin:20px 0;
    gap:20px
}
.ps-employee{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    padding:13px;
    background:var(--soft);
    border:1px solid var(--border)
}
.ps-line{
    display:flex;
    justify-content:space-between;
    padding:6px 0
}
.ps-section{margin-top:20px}
.ps-section h3{
    font-size:13px;
    border-bottom:1px solid var(--border);
    padding-bottom:6px
}
.ps-total{border-top:1px solid #ccd4dc;font-weight:800;padding-top:9px}
.ps-net{
    display:flex;
    justify-content:space-between;
    padding:14px;
    margin-top:20px;
    background:var(--green-soft);
    color:#08754f;
    font-size:18px;
    font-weight:800
}

@media(max-width:1200px){
    .summary-grid{grid-template-columns:repeat(2,1fr)}
    .filter-row{grid-template-columns:1fr 1fr}
    .stat-grid{grid-template-columns:repeat(3,1fr)}
    .row-form{grid-template-columns:repeat(4,1fr)}
}
@media(max-width:900px){
    .main{margin-left:0}
    .payroll-content{padding:20px 16px 32px}
    .page-heading{align-items:flex-start;flex-direction:column}
    .page-actions{width:100%}
}
@media(max-width:600px){
    .summary-grid{grid-template-columns:1fr}
    .filter-row{grid-template-columns:1fr}
    .stat-grid{grid-template-columns:1fr}
    .form-grid{grid-template-columns:1fr}
    .form-field.full{grid-column:auto}
    .page-heading h1{font-size:23px}
    .payroll-content{padding:16px 12px 30px}
}
@media print{
    .admin-aside,.admin-header,.payroll-tabs,.filter-panel,
    .page-actions,.row-form,.action-stack,.notice,.footer-total{
        display:none!important
    }
    .main{margin-left:0}
    .payroll-content{padding:0;max-width:none}
    .panel,.summary-card{box-shadow:none}
}
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

<form method="post">
<input type="hidden" name="action" value="prepare_payroll">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn primary" type="submit">
<i class="fas fa-file-circle-plus"></i>
Prepare Payroll
</button>
</form>

<form method="post">
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

<button class="btn" type="button" onclick="window.print()">
<i class="fas fa-print"></i>
Print
</button>

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

<?php if ($view === 'salary'): ?>

<section class="panel salary-panel">

<div class="panel-head">
<div class="panel-title">
<b>Salary Setup</b>
<span>Set and maintain each employee's master monthly basic salary.</span>
</div>
<span class="period-pill">
<i class="fas fa-lock"></i>
Payroll controlled
</span>
</div>

<div class="salary-note">
    <strong>Salary ownership:</strong>
    Staff Management creates staff accounts only. Monthly salary is maintained here
    and stored as the employee's master salary. When you click <strong>Prepare Payroll</strong>,
    this value becomes the starting Basic Salary for the selected payroll period.
    Approved, paid and locked payroll records remain protected.
</div>

<div class="table-wrap">

<table class="salary-table">

<thead>
<tr>
<th>Employee</th>
<th>Role</th>
<th>Branch</th>
<th class="money">Current Basic Salary</th>
<th class="salary-actions">Set Monthly Salary</th>
</tr>
</thead>

<tbody>

<?php if (!$staffRows): ?>

<tr>
<td colspan="5" class="empty">
<i class="fas fa-users-slash" style="font-size:30px;margin-bottom:10px"></i>
<div><strong>No staff members found.</strong></div>
<div style="margin-top:5px;font-size:10px">
Register the staff account in Staff Management first.
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($staffRows as $staff): ?>

<?php
$salaryStaffName = (string)($staff['staff_name'] ?? 'Staff');
$salaryInitials = '';

foreach (
    preg_split('/\s+/', trim($salaryStaffName)) ?: []
    as $part
) {
    if ($part !== '') {
        $salaryInitials .= strtoupper($part[0]);
    }
    if (strlen($salaryInitials) >= 2) break;
}

$salaryInitials = $salaryInitials ?: 'ST';
?>

<tr>

<td>
<div class="employee">

<div class="avatar">
<?= payroll_complete_h($salaryInitials) ?>
</div>

<div class="employee-name">
<b><?= payroll_complete_h($salaryStaffName) ?></b>
<span>
#<?= !empty($staff['employee_number'])
    ? payroll_complete_h($staff['employee_number'])
    : (int)$staff['staff_id'] ?>
</span>
</div>

</div>
</td>

<td><?= payroll_complete_h($staff['staff_role'] ?? 'Staff') ?></td>

<td>
<?= payroll_complete_h(
    $branchNames[(int)($staff['branch_id'] ?? 0)] ?? 'Main Branch'
) ?>
</td>

<td class="money salary-master">
<?= payroll_complete_money((float)($staff['basic_salary'] ?? 0)) ?>
</td>

<td class="salary-actions">

<form method="post" class="salary-form">

<input type="hidden" name="action" value="set_salary">
<input type="hidden" name="staff_id" value="<?= (int)$staff['staff_id'] ?>">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">

<input
    class="salary-input"
    type="number"
    name="salary"
    min="0"
    step="0.01"
    value="<?= payroll_complete_h((float)($staff['basic_salary'] ?? 0)) ?>"
    aria-label="Monthly salary for <?= payroll_complete_h($salaryStaffName) ?>"
    required
>

<button class="btn small green" type="submit">
<i class="fas fa-save"></i>
Save
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

<section class="filter-panel">

<form method="get">

<input type="hidden" name="view" value="salary">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
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

<div></div>

<button class="btn primary" type="submit">
<i class="fas fa-filter"></i>
Apply
</button>

</div>

<div class="filter-note">
<span>
<?= count($staffRows) ?> staff available for salary setup
</span>

<span>
Master salary only
</span>
</div>

</form>

</section>

<?php elseif ($view === 'payroll'): ?>

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
<th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$payrollRows): ?>

<tr>
<td colspan="11" class="empty">
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
    href="?view=payroll&month=<?= $selectedMonth ?>&year=<?= $selectedYear ?>&payslip=<?= (int)$row['staff_id'] ?>&print=1"
    target="_blank"
    title="Payslip"
>
<i class="fas fa-receipt"></i>
</a>

</div>
</td>

</tr>

<?php if (!in_array(
    $row['status'],
    ['approved','paid','locked'],
    true
)): ?>

<tr id="edit-<?= (int)$row['id'] ?>" style="display:none">

<td colspan="11" style="padding:0">

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

<form method="post">
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

<form method="post">
<input type="hidden" name="action" value="mark_paid">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button
    class="btn green"
    type="submit"
    onclick="return confirm('Mark payroll as PAID?');"
>
<i class="fas fa-money-bill-transfer"></i>
Mark Paid
</button>
</form>

<form method="post">
<input type="hidden" name="action" value="reopen">
<input type="hidden" name="month" value="<?= $selectedMonth ?>">
<input type="hidden" name="year" value="<?= $selectedYear ?>">
<button class="btn yellow" type="submit">Reopen</button>
</form>

<?php elseif ($periodStatus === 'paid'): ?>

<form method="post">
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

<form method="post">
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
    ?: '—'
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

<?php
/*
|--------------------------------------------------------------------------
| Inline printable payslip.
|
| It is deliberately part of this same payroll action file.
|--------------------------------------------------------------------------
*/

if ($payslip !== null && isset($_GET['print']) && $_GET['print'] === '1'):
?>

<script>
(function(){
    const body = document.body;
    const main = document.querySelector('.app');
    if (main) {
        main.style.display = 'none';
    }

    const sheet = document.createElement('section');
    sheet.className = 'payslip-sheet';
    sheet.style.display = 'block';

    sheet.innerHTML = `
        <div class="company">
            <h2><?= payroll_complete_h($pharmacyName) ?></h2>
            <p>Employee Payslip</p>
        </div>

        <div class="ps-heading">
            <div>
                <h2><?= payroll_complete_h($payslip['staff_name']) ?></h2>
                <div class="muted"><?= payroll_complete_h($periodLabel) ?></div>
            </div>

            <div style="text-align:right">
                <strong><?= payroll_complete_h(strtoupper($payslip['status'])) ?></strong><br>
                <span class="muted">
                    Employee #<?= (int)$payslip['staff_id'] ?>
                </span>
            </div>
        </div>

        <div class="ps-employee">
            <div><span>Role</span><strong><?= payroll_complete_h($payslip['staff_role']) ?></strong></div>
            <div><span>Branch</span><strong><?= payroll_complete_h($payslip['branch_name']) ?></strong></div>
            <div><span>Basic Salary</span><strong><?= payroll_complete_money($payslip['basic_salary']) ?></strong></div>
            <div><span>Period</span><strong><?= payroll_complete_h($periodLabel) ?></strong></div>
        </div>

        <div class="ps-section">
            <h3>Earnings</h3>
            <div class="ps-line"><span>Basic Salary</span><strong><?= payroll_complete_money($payslip['basic_salary']) ?></strong></div>
            <div class="ps-line"><span>Allowances</span><strong><?= payroll_complete_money($payslip['allowances']) ?></strong></div>
            <div class="ps-line"><span>Bonus</span><strong><?= payroll_complete_money($payslip['bonus']) ?></strong></div>
            <div class="ps-line"><span>Overtime</span><strong><?= payroll_complete_money($payslip['overtime']) ?></strong></div>
            <div class="ps-line"><span>Other Earnings</span><strong><?= payroll_complete_money($payslip['other_earnings']) ?></strong></div>
            <div class="ps-line ps-total"><span>Gross Salary</span><strong><?= payroll_complete_money($payslip['gross_salary']) ?></strong></div>
        </div>

        <div class="ps-section">
            <h3>Deductions</h3>
            <div class="ps-line"><span>PAYE</span><strong><?= payroll_complete_money($payslip['paye']) ?></strong></div>
            <div class="ps-line"><span>NAPSA</span><strong><?= payroll_complete_money($payslip['napsa']) ?></strong></div>
            <div class="ps-line"><span>NHIMA</span><strong><?= payroll_complete_money($payslip['nhima']) ?></strong></div>
            <div class="ps-line"><span>Loan</span><strong><?= payroll_complete_money($payslip['loan_deduction']) ?></strong></div>
            <div class="ps-line"><span>Salary Advance</span><strong><?= payroll_complete_money($payslip['salary_advance']) ?></strong></div>
            <div class="ps-line"><span>Other Deductions</span><strong><?= payroll_complete_money($payslip['other_deductions']) ?></strong></div>
            <div class="ps-line ps-total"><span>Total Deductions</span><strong><?= payroll_complete_money($payslip['total_deductions']) ?></strong></div>
        </div>

        <div class="ps-net">
            <span>NET PAY</span>
            <span><?= payroll_complete_money($payslip['net_salary']) ?></span>
        </div>

        <p style="text-align:center;margin-top:25px;color:#71808f;font-size:10px">
            Generated by EchoTech POS · <?= payroll_complete_h(date('d M Y H:i')) ?>
        </p>
    `;

    body.appendChild(sheet);

    window.addEventListener('load', function(){
        setTimeout(function(){
            window.print();
        }, 200);
    });
})();
</script>

<?php endif; ?>

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
