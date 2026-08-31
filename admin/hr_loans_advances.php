<?php
/**
 * EchoTech POS
 * Admin - Loans & Salary Advances
 *
 * Standalone Payroll sub-module.
 * Does NOT modify payroll.php or payroll calculations.
 * Tables are created safely if they do not already exist.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conn.php';

require_login();

$userRole = trim((string)($_SESSION['role'] ?? ''));
if ($userRole !== 'Human Resource') {
    http_response_code(403);
    exit('Access denied. Loans & Advances is restricted to Human Resource.');
}

$pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
$branchId   = (int)($_SESSION['branch_id'] ?? ($_SESSION['current_branch_id'] ?? 0));
$userName   = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Admin');

if ($pharmacyId <= 0) {
    header('Location: index.php?error=session_expired');
    exit;
}

function la_esc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function la_money(float $value): string
{
    return 'K' . number_format($value, 2);
}

function la_redirect(string $message = '', string $error = ''): never
{
    $params = [];
    if ($message !== '') $params['saved'] = $message;
    if ($error !== '') $params['error'] = $error;

    header('Location: loans_advances.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function la_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = @$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

/*
|--------------------------------------------------------------------------
| Tables
|--------------------------------------------------------------------------
*/
$conn->query("
CREATE TABLE IF NOT EXISTS employee_loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    staff_id INT UNSIGNED NOT NULL,
    loan_number VARCHAR(40) NOT NULL,
    principal_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    installment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    start_date DATE NOT NULL,
    first_repayment_month CHAR(7) NOT NULL,
    purpose VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('Draft','Pending','Approved','Active','Completed','Rejected','Cancelled','Written Off')
        NOT NULL DEFAULT 'Pending',
    write_off_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    write_off_reason VARCHAR(255) DEFAULT NULL,
    written_off_by VARCHAR(150) DEFAULT NULL,
    written_off_at DATETIME DEFAULT NULL,
    approved_by VARCHAR(150) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_by VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_loans_number (loan_number),
    KEY idx_employee_loans_pharmacy (pharmacy_id),
    KEY idx_employee_loans_staff (pharmacy_id, staff_id),
    KEY idx_employee_loans_status (pharmacy_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$conn->query("
CREATE TABLE IF NOT EXISTS salary_advances (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    staff_id INT UNSIGNED NOT NULL,
    advance_number VARCHAR(40) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    balance_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    advance_date DATE NOT NULL,
    repayment_month CHAR(7) NOT NULL,
    installment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('Draft','Pending','Approved','Active','Completed','Rejected','Cancelled','Written Off')
        NOT NULL DEFAULT 'Pending',
    write_off_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    write_off_reason VARCHAR(255) DEFAULT NULL,
    written_off_by VARCHAR(150) DEFAULT NULL,
    written_off_at DATETIME DEFAULT NULL,
    approved_by VARCHAR(150) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_by VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salary_advances_number (advance_number),
    KEY idx_salary_advances_pharmacy (pharmacy_id),
    KEY idx_salary_advances_staff (pharmacy_id, staff_id),
    KEY idx_salary_advances_status (pharmacy_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/*
|--------------------------------------------------------------------------
| Staff source
|--------------------------------------------------------------------------
| The current POS database uses users as the staff/employee source.
*/
$staff = [];
$stmt = $conn->prepare("
    SELECT id, full_name, username, role, branch_id, salary_amount
    FROM users
    WHERE pharmacy_id = ?
      AND status = 'Active'
    ORDER BY COALESCE(NULLIF(full_name,''), username) ASC
");
if ($stmt) {
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| Write-off fields for existing installations
|--------------------------------------------------------------------------
| These ALTER statements only add missing columns / status value.
| Existing loan and advance records are not recreated or deleted.
*/
foreach ([
    'employee_loans' => [
        'write_off_amount' => "ALTER TABLE employee_loans ADD COLUMN write_off_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER balance_amount",
        'write_off_reason' => "ALTER TABLE employee_loans ADD COLUMN write_off_reason VARCHAR(255) DEFAULT NULL AFTER write_off_amount",
        'written_off_by' => "ALTER TABLE employee_loans ADD COLUMN written_off_by VARCHAR(150) DEFAULT NULL AFTER write_off_reason",
        'written_off_at' => "ALTER TABLE employee_loans ADD COLUMN written_off_at DATETIME DEFAULT NULL AFTER written_off_by",
    ],
    'salary_advances' => [
        'write_off_amount' => "ALTER TABLE salary_advances ADD COLUMN write_off_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER balance_amount",
        'write_off_reason' => "ALTER TABLE salary_advances ADD COLUMN write_off_reason VARCHAR(255) DEFAULT NULL AFTER write_off_amount",
        'written_off_by' => "ALTER TABLE salary_advances ADD COLUMN written_off_by VARCHAR(150) DEFAULT NULL AFTER written_off_by",
        'written_off_at' => "ALTER TABLE salary_advances ADD COLUMN written_off_at DATETIME DEFAULT NULL AFTER written_off_by",
    ],
] as $table => $columns) {
    foreach ($columns as $column => $alterSql) {
        $existsStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        if ($existsStmt) {
            $existsStmt->bind_param('ss', $table, $column);
            $existsStmt->execute();
            $existsStmt->bind_result($columnCount);
            $existsStmt->fetch();
            $existsStmt->close();

            if ((int)$columnCount === 0) {
                @$conn->query($alterSql);
            }
        }
    }
}

/* Add Written Off to the existing ENUM only if it is not already present. */
foreach (['employee_loans', 'salary_advances'] as $table) {
    $statusStmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = 'status'
        LIMIT 1
    ");

    if ($statusStmt) {
        $statusStmt->bind_param('s', $table);
        $statusStmt->execute();
        $statusStmt->bind_result($columnType);
        $statusStmt->fetch();
        $statusStmt->close();

        if (strpos((string)$columnType, "'Written Off'") === false && strpos((string)$columnType, 'enum(') === 0) {
            $newEnum = str_replace(
                "enum(",
                "ENUM(",
                (string)$columnType
            );
            $newEnum = rtrim($newEnum, ')') . ",'Written Off')";
            @$conn->query("ALTER TABLE {$table} MODIFY status {$newEnum} NOT NULL DEFAULT 'Pending'");
        }
    }
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['approve_loan','approve_advance'], true)) {
        la_redirect('', 'HR can prepare and submit loans/advances, but Admin gives the final approval.');
    }
    /* ============================================================
       WRITE OFF LOAN
       Clears ONLY the remaining balance and records who/why.
    ============================================================ */
    if ($action === 'write_off_loan') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($id <= 0 || $reason === '') {
            la_redirect('', 'A valid loan and a write-off reason are required.');
        }

        $stmt = $conn->prepare("
            SELECT balance_amount, status
            FROM employee_loans
            WHERE id = ? AND pharmacy_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            la_redirect('', 'Could not read the loan.');
        }

        $stmt->bind_param('ii', $id, $pharmacyId);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            la_redirect('', 'Loan not found.');
        }

        if (!in_array($loan['status'], ['Approved','Active'], true)) {
            la_redirect('', 'Only an Approved or Active loan can be written off.');
        }

        $balance = max(0.00, (float)$loan['balance_amount']);

        $stmt = $conn->prepare("
            UPDATE employee_loans
            SET
                write_off_amount = ?,
                write_off_reason = ?,
                written_off_by = ?,
                written_off_at = NOW(),
                balance_amount = 0.00,
                status = 'Written Off'
            WHERE id = ?
              AND pharmacy_id = ?
              AND status IN ('Approved','Active')
        ");

        if (!$stmt) {
            la_redirect('', 'Could not write off the loan.');
        }

        $stmt->bind_param('dssii', $balance, $reason, $userName, $id, $pharmacyId);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            ($ok && $changed > 0) ? 'Loan written off successfully. The remaining balance has been cleared.' : '',
            ($ok && $changed > 0) ? '' : 'The loan could not be written off. It may already have been processed.'
        );
    }

    /* ============================================================
       WRITE OFF SALARY ADVANCE
    ============================================================ */
    if ($action === 'write_off_advance') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($id <= 0 || $reason === '') {
            la_redirect('', 'A valid salary advance and a write-off reason are required.');
        }

        $stmt = $conn->prepare("
            SELECT balance_amount, status
            FROM salary_advances
            WHERE id = ? AND pharmacy_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            la_redirect('', 'Could not read the salary advance.');
        }

        $stmt->bind_param('ii', $id, $pharmacyId);
        $stmt->execute();
        $advance = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$advance) {
            la_redirect('', 'Salary advance not found.');
        }

        if (!in_array($advance['status'], ['Approved','Active'], true)) {
            la_redirect('', 'Only an Approved or Active salary advance can be written off.');
        }

        $balance = max(0.00, (float)$advance['balance_amount']);

        $stmt = $conn->prepare("
            UPDATE salary_advances
            SET
                write_off_amount = ?,
                write_off_reason = ?,
                written_off_by = ?,
                written_off_at = NOW(),
                balance_amount = 0.00,
                status = 'Written Off'
            WHERE id = ?
              AND pharmacy_id = ?
              AND status IN ('Approved','Active')
        ");

        if (!$stmt) {
            la_redirect('', 'Could not write off the salary advance.');
        }

        $stmt->bind_param('dssii', $balance, $reason, $userName, $id, $pharmacyId);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            ($ok && $changed > 0) ? 'Salary advance written off successfully. The remaining balance has been cleared.' : '',
            ($ok && $changed > 0) ? '' : 'The salary advance could not be written off. It may already have been processed.'
        );
    }


    if ($action === 'create_loan') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $principal = round((float)($_POST['principal_amount'] ?? 0), 2);
        $interest = round((float)($_POST['interest_amount'] ?? 0), 2);
        $installment = round((float)($_POST['installment_amount'] ?? 0), 2);
        $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
        $firstMonth = trim((string)($_POST['first_repayment_month'] ?? date('Y-m')));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($staffId <= 0 || $principal <= 0 || $installment <= 0) {
            la_redirect('', 'Employee, loan amount and installment are required.');
        }

        if ($interest < 0) {
            la_redirect('', 'Interest amount cannot be negative.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $firstMonth)) {
            la_redirect('', 'Invalid first repayment month.');
        }

        $verify = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ? AND pharmacy_id = ? AND status = 'Active'
            LIMIT 1
        ");
        if (!$verify) la_redirect('', 'Could not validate the employee.');
        $verify->bind_param('ii', $staffId, $pharmacyId);
        $verify->execute();
        $valid = $verify->get_result()->fetch_assoc();
        $verify->close();

        if (!$valid) la_redirect('', 'Selected employee is not valid for this pharmacy.');

        $activeCheck = $conn->prepare("SELECT id FROM employee_loans WHERE pharmacy_id=? AND staff_id=? AND status IN ('Pending','Approved','Active') LIMIT 1");
        if ($activeCheck) {
            $activeCheck->bind_param('ii', $pharmacyId, $staffId);
            $activeCheck->execute();
            $alreadyInProgress = (bool)$activeCheck->get_result()->fetch_assoc();
            $activeCheck->close();
            if ($alreadyInProgress) {
                la_redirect('', 'This employee already has a loan in progress (Pending, Approved or Active). A new loan cannot be created until the existing loan is completed, rejected or cancelled.');
            }
        }

        $total = round($principal + $interest, 2);
        $installment = min($installment, $total);
        $loanNumber = 'LN-' . date('YmdHis') . '-' . random_int(100, 999);

        $stmt = $conn->prepare("
            INSERT INTO employee_loans
            (
                pharmacy_id, branch_id, staff_id, loan_number,
                principal_amount, interest_amount, total_amount,
                installment_amount, amount_paid, balance_amount,
                start_date, first_repayment_month, purpose, notes,
                status, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        if (!$stmt) {
            la_redirect('', 'Could not create loan: ' . $conn->error);
        }

        $balance = $total;
        $stmt->bind_param(
            'iiisddddssssss',
            $pharmacyId,
            $branchId,
            $staffId,
            $loanNumber,
            $principal,
            $interest,
            $total,
            $installment,
            $balance,
            $startDate,
            $firstMonth,
            $purpose,
            $notes,
            $userName
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            la_redirect('', 'Could not create loan: ' . $error);
        }

        $stmt->close();
        la_redirect('Loan ' . $loanNumber . ' created and awaiting approval.');
    }

    if ($action === 'create_advance') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $installment = round((float)($_POST['installment_amount'] ?? 0), 2);
        $advanceDate = trim((string)($_POST['advance_date'] ?? date('Y-m-d')));
        $repaymentMonth = trim((string)($_POST['repayment_month'] ?? date('Y-m')));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($staffId <= 0 || $amount <= 0 || $installment <= 0) {
            la_redirect('', 'Employee, advance amount and installment are required.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $repaymentMonth)) {
            la_redirect('', 'Invalid repayment month.');
        }

        $verify = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ? AND pharmacy_id = ? AND status = 'Active'
            LIMIT 1
        ");
        if (!$verify) la_redirect('', 'Could not validate the employee.');
        $verify->bind_param('ii', $staffId, $pharmacyId);
        $verify->execute();
        $valid = $verify->get_result()->fetch_assoc();
        $verify->close();

        if (!$valid) la_redirect('', 'Selected employee is not valid for this pharmacy.');

        $activeCheck = $conn->prepare("SELECT id FROM salary_advances WHERE pharmacy_id=? AND staff_id=? AND status IN ('Pending','Approved','Active') LIMIT 1");
        if ($activeCheck) {
            $activeCheck->bind_param('ii', $pharmacyId, $staffId);
            $activeCheck->execute();
            $alreadyInProgress = (bool)$activeCheck->get_result()->fetch_assoc();
            $activeCheck->close();
            if ($alreadyInProgress) {
                la_redirect('', 'This employee already has a salary advance in progress (Pending, Approved or Active). A new advance cannot be created until the existing advance is completed, rejected or cancelled.');
            }
        }

        $installment = min($installment, $amount);
        $advanceNumber = 'ADV-' . date('YmdHis') . '-' . random_int(100, 999);

        $stmt = $conn->prepare("
            INSERT INTO salary_advances
            (
                pharmacy_id, branch_id, staff_id, advance_number,
                amount, amount_paid, balance_amount,
                advance_date, repayment_month, installment_amount,
                reason, notes, status, created_by
            )
            VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        if (!$stmt) {
            la_redirect('', 'Could not create salary advance: ' . $conn->error);
        }

        $balance = $amount;
        $stmt->bind_param(
            'iiisddssdsss',
            $pharmacyId,
            $branchId,
            $staffId,
            $advanceNumber,
            $amount,
            $balance,
            $advanceDate,
            $repaymentMonth,
            $installment,
            $reason,
            $notes,
            $userName
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            la_redirect('', 'Could not create salary advance: ' . $error);
        }

        $stmt->close();
        la_redirect('Salary advance ' . $advanceNumber . ' created and awaiting approval.');
    }

    if ($action === 'approve_loan') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE employee_loans
            SET status = 'Active', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND pharmacy_id = ? AND status = 'Pending'
        ");
        if (!$stmt) la_redirect('', 'Could not approve loan.');
        $stmt->bind_param('sii', $userName, $id, $pharmacyId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            $changed > 0 ? 'Loan approved and activated.' : '',
            $changed > 0 ? '' : 'Loan could not be approved. It may already have been processed.'
        );
    }

    if ($action === 'approve_advance') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE salary_advances
            SET status = 'Active', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND pharmacy_id = ? AND status = 'Pending'
        ");
        if (!$stmt) la_redirect('', 'Could not approve salary advance.');
        $stmt->bind_param('sii', $userName, $id, $pharmacyId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            $changed > 0 ? 'Salary advance approved and activated.' : '',
            $changed > 0 ? '' : 'Salary advance could not be approved. It may already have been processed.'
        );
    }

    if ($action === 'cancel_loan') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE employee_loans
            SET status = 'Cancelled'
            WHERE id = ? AND pharmacy_id = ? AND status IN ('Draft','Pending')
        ");
        if (!$stmt) la_redirect('', 'Could not cancel loan.');
        $stmt->bind_param('ii', $id, $pharmacyId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            $changed > 0 ? 'Loan cancelled.' : '',
            $changed > 0 ? '' : 'Only draft or pending loans can be cancelled.'
        );
    }

    if ($action === 'cancel_advance') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE salary_advances
            SET status = 'Cancelled'
            WHERE id = ? AND pharmacy_id = ? AND status IN ('Draft','Pending')
        ");
        if (!$stmt) la_redirect('', 'Could not cancel salary advance.');
        $stmt->bind_param('ii', $id, $pharmacyId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        la_redirect(
            $changed > 0 ? 'Salary advance cancelled.' : '',
            $changed > 0 ? '' : 'Only draft or pending advances can be cancelled.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$tab = (($_GET['tab'] ?? 'loans') === 'advances') ? 'advances' : 'loans';
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$loanRows = [];
$advanceRows = [];

$staffJoin = "
    LEFT JOIN users u
      ON u.id = l.staff_id
     AND u.pharmacy_id = l.pharmacy_id
";

$sql = "
    SELECT
        l.*,
        COALESCE(NULLIF(u.full_name,''), u.username, CONCAT('Staff #', l.staff_id)) AS staff_name,
        u.role AS staff_role
    FROM employee_loans l
    {$staffJoin}
    WHERE l.pharmacy_id = ?
";
$params = [$pharmacyId];
$types = 'i';

if ($search !== '') {
    $sql .= " AND (
        l.loan_number LIKE ?
        OR u.full_name LIKE ?
        OR u.username LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($statusFilter !== '') {
    $sql .= " AND l.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql .= " ORDER BY l.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $loanRows[] = $row;
    $stmt->close();
}

$sql = "
    SELECT
        a.*,
        COALESCE(NULLIF(u.full_name,''), u.username, CONCAT('Staff #', a.staff_id)) AS staff_name,
        u.role AS staff_role
    FROM salary_advances a
    LEFT JOIN users u
      ON u.id = a.staff_id
     AND u.pharmacy_id = a.pharmacy_id
    WHERE a.pharmacy_id = ?
";
$params = [$pharmacyId];
$types = 'i';

if ($search !== '') {
    $sql .= " AND (
        a.advance_number LIKE ?
        OR u.full_name LIKE ?
        OR u.username LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($statusFilter !== '') {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql .= " ORDER BY a.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $advanceRows[] = $row;
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/
$activeLoans = 0;
$loanBalance = 0;
$activeAdvances = 0;
$advanceBalance = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(balance_amount),0) AS balance
    FROM employee_loans
    WHERE pharmacy_id = ? AND status = 'Active'
");
if ($stmt) {
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $activeLoans = (int)($r['total_count'] ?? 0);
    $loanBalance = (float)($r['balance'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(balance_amount),0) AS balance
    FROM salary_advances
    WHERE pharmacy_id = ? AND status = 'Active'
");
if ($stmt) {
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $activeAdvances = (int)($r['total_count'] ?? 0);
    $advanceBalance = (float)($r['balance'] ?? 0);
    $stmt->close();
}

$success = (string)($_GET['saved'] ?? '');
$error = (string)($_GET['error'] ?? '');

require_once __DIR__ . '/actions/admin_aside.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loans & Salary Advances</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fa;color:#26323d;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
.main{margin-left:250px;min-height:100vh}
.page{max-width:1500px;margin:0 auto;padding:28px}
.heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:18px}
.heading h1{margin:0;font-size:25px;color:#202b35}
.heading p{margin:6px 0 0;color:#7a8792;font-size:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid #dce3e8;background:#fff;color:#43505c;border-radius:7px;padding:10px 14px;font-size:12px;font-weight:700;cursor:pointer}
.btn:hover{background:#f7f9fb}
.btn.primary{background:#1677c8;border-color:#1677c8;color:#fff}
.btn.success{background:#17865b;border-color:#17865b;color:#fff}
.btn.danger{color:#b43b46}
.notice{padding:11px 14px;border-radius:8px;margin-bottom:15px;font-size:12px;font-weight:600}
.notice.success{background:#eaf8f1;color:#14734e;border:1px solid #ccebdd}
.notice.error{background:#fff0f1;color:#b13b47;border:1px solid #f1cfd3}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.card{background:#fff;border:1px solid #e3e8ed;border-radius:10px;padding:15px;box-shadow:0 2px 8px rgba(31,45,61,.04)}
.card-top{display:flex;justify-content:space-between;align-items:center;color:#71808c;font-size:11px;font-weight:700}
.icon{width:32px;height:32px;border-radius:8px;background:#eef5fb;display:grid;place-items:center;color:#1677c8}
.value{font-size:22px;font-weight:800;margin-top:10px;color:#27343f}
.sub{font-size:10px;color:#8a959e;margin-top:3px}
.tabs{display:flex;gap:4px;background:#fff;border:1px solid #e1e6ea;border-radius:9px;padding:5px;margin-bottom:14px}
.tab{padding:9px 14px;border-radius:6px;text-decoration:none;color:#66737e;font-size:12px;font-weight:700}
.tab.active{background:#edf5fb;color:#1677c8}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
.search{display:flex;gap:7px;flex:1;max-width:700px}
.search input,.search select{border:1px solid #d9e0e5;background:#fff;border-radius:7px;padding:10px 11px;font-size:12px;outline:none}
.search input{flex:1}
.table-card{background:#fff;border:1px solid #e1e6ea;border-radius:10px;overflow:hidden}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1050px}
th{background:#f8fafb;color:#697681;font-size:10px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:11px;border-bottom:1px solid #e2e7eb}
td{padding:11px;border-bottom:1px solid #edf0f2;font-size:11px;color:#43505b}
tr:last-child td{border-bottom:0}
.employee{font-weight:800;color:#27343d}
.muted{display:block;color:#8a949d;font-size:9px;margin-top:3px}
.money{font-weight:800;color:#27343d}
.badge{display:inline-block;padding:5px 8px;border-radius:999px;font-size:9px;font-weight:800}
.badge.Active{background:#e8f7ef;color:#14734e}
.badge.Pending{background:#fff6df;color:#9a6b00}
.badge.Completed{background:#eaf0ff;color:#315fa7}
.badge.Cancelled,.badge.Rejected{background:#fff0f1;color:#ae3c48}
.badge.Draft{background:#eef1f4;color:#65717b}
.row-actions{display:flex;gap:5px;align-items:center}
.inline{display:inline}
.empty{padding:45px;text-align:center;color:#8a959e;font-size:12px}
.modal{display:none;position:fixed;inset:0;background:rgba(20,29,37,.45);z-index:1000;padding:30px;overflow:auto}
.modal.show{display:block}
.modal-box{background:#fff;max-width:700px;margin:30px auto;border-radius:12px;box-shadow:0 15px 50px rgba(0,0,0,.18);overflow:hidden}
.modal-head{padding:17px 20px;border-bottom:1px solid #e7ebee;display:flex;justify-content:space-between;align-items:center}
.modal-head h2{margin:0;font-size:17px}
.close{border:0;background:none;font-size:20px;color:#7b8790;cursor:pointer}
.modal-body{padding:20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:5px}
.field.full{grid-column:1/-1}
.field label{font-size:10px;font-weight:800;color:#65727d}
.field input,.field select,.field textarea{border:1px solid #d9e0e5;border-radius:7px;padding:10px;font-size:12px;font-family:inherit}
.field textarea{min-height:75px;resize:vertical}
.modal-foot{padding:14px 20px;border-top:1px solid #e7ebee;display:flex;justify-content:flex-end;gap:8px}
@media(max-width:1100px){.cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){.main{margin-left:0}.page{padding:18px}.heading{flex-direction:column}.cards{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.toolbar{flex-direction:column;align-items:stretch}.search{max-width:none}}

.badge.Written-Off{background:#eef0f3;color:#58636d}
</style>
</head>
<body>
<?php require_once __DIR__ . '/actions/hr_aside.php'; ?>
<main class="main">
<?php require_once __DIR__ . '/actions/admin_header.php'; ?>

<div class="page">
    <div class="heading">
        <div>
            <h1>Loans & Salary Advances</h1>
            <p>Prepare employee loans and salary advances for Admin approval, and monitor repayment balances.</p>
        </div>
        <div style="margin-top:8px;font-size:11px;color:#6f7d88;line-height:1.5;">Loan and advance installments are automatically included in Payroll from their first repayment month. Use Payroll to excuse or lower the current month's deduction; the unpaid balance carries forward.</div>
        <div class="actions">
            <button class="btn" type="button" onclick="openModal('advanceModal')">
                <i class="fa-solid fa-hand-holding-dollar"></i> Salary Advance
            </button>
            <button class="btn primary" type="button" onclick="openModal('loanModal')">
                <i class="fa-solid fa-money-bill-transfer"></i> New Loan
            </button>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="notice success"><i class="fa-solid fa-circle-check"></i> <?= la_esc($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><i class="fa-solid fa-circle-exclamation"></i> <?= la_esc($error) ?></div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">
            <div class="card-top"><span>Active Loans</span><span class="icon"><i class="fa-solid fa-money-bill-transfer"></i></span></div>
            <div class="value"><?= number_format($activeLoans) ?></div>
            <div class="sub">Currently being repaid</div>
        </div>
        <div class="card">
            <div class="card-top"><span>Loan Balance</span><span class="icon"><i class="fa-solid fa-scale-balanced"></i></span></div>
            <div class="value"><?= la_money($loanBalance) ?></div>
            <div class="sub">Outstanding loan balance</div>
        </div>
        <div class="card">
            <div class="card-top"><span>Active Advances</span><span class="icon"><i class="fa-solid fa-hand-holding-dollar"></i></span></div>
            <div class="value"><?= number_format($activeAdvances) ?></div>
            <div class="sub">Currently being recovered</div>
        </div>
        <div class="card">
            <div class="card-top"><span>Advance Balance</span><span class="icon"><i class="fa-solid fa-wallet"></i></span></div>
            <div class="value"><?= la_money($advanceBalance) ?></div>
            <div class="sub">Outstanding advances</div>
        </div>
    </div>

    <div class="tabs">
        <a class="tab <?= $tab === 'loans' ? 'active' : '' ?>" href="?tab=loans"><i class="fa-solid fa-money-bill-transfer"></i> Loans</a>
        <a class="tab <?= $tab === 'advances' ? 'active' : '' ?>" href="?tab=advances"><i class="fa-solid fa-hand-holding-dollar"></i> Salary Advances</a>
    </div>

    <div style="margin:0 0 12px;padding:10px 12px;border:1px solid #dfe6ec;border-radius:8px;background:#fff;font-size:11px;color:#65727d;line-height:1.5;">
    <strong>Repayment controls:</strong> Use <strong>Payroll</strong> to excuse or lower the current month's scheduled installment.
    Use <strong>Write Off</strong> only when Admin decides the remaining debt should be permanently cleared.
</div>

<div class="toolbar">
        <form class="search" method="get">
            <input type="hidden" name="tab" value="<?= la_esc($tab) ?>">
            <input type="text" name="q" value="<?= la_esc($search) ?>" placeholder="Search employee or reference...">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['Pending','Approved','Active','Completed','Written Off','Cancelled','Rejected','Draft'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        </form>
    </div>

    <div class="table-card">
        <div class="table-wrap">
        <?php if ($tab === 'loans'): ?>
            <table>
                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Loan No.</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Installment</th>
                    <th>Start</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$loanRows): ?>
                    <tr><td colspan="11" class="empty">No loan records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($loanRows as $row): ?>
                    <tr>
                        <td><span class="employee"><?= la_esc($row['staff_name']) ?></span><span class="muted"><?= la_esc($row['staff_role'] ?? 'Staff') ?></span></td>
                        <td><?= la_esc($row['loan_number']) ?></td>
                        <td class="money"><?= la_money((float)$row['principal_amount']) ?></td>
                        <td><?= la_money((float)$row['interest_amount']) ?></td>
                        <td class="money"><?= la_money((float)$row['total_amount']) ?></td>
                        <td><?= la_money((float)$row['amount_paid']) ?></td>
                        <td class="money"><?= la_money((float)$row['balance_amount']) ?></td>
                        <td><?= la_money((float)$row['installment_amount']) ?></td>
                        <td><?= la_esc($row['start_date']) ?></td>
                        <td><span class="badge <?= la_esc($row['status']) ?>"><?= la_esc($row['status']) ?></span></td>
                        <td>
                            <div class="row-actions">
                            <?php if ($row['status'] === 'Pending'): ?>
                                <form class="inline" method="post" onsubmit="return confirm('Cancel this loan?');">
                                    <input type="hidden" name="action" value="cancel_loan">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn danger" type="submit" title="Cancel"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php if (in_array($row['status'], ['Pending','Approved','Active'], true)): ?>
                                <a class="btn" href="hr_payroll.php?month=<?= (int)date('n') ?>&year=<?= (int)date('Y') ?>" title="Excuse or lower this month's repayment in Payroll"><i class="fa-solid fa-calculator"></i></a>
                            <?php endif; ?>

                            <?php if (in_array($row['status'], ['Approved','Active'], true) && (float)$row['balance_amount'] > 0): ?>
                                <button class="btn danger"
                                        type="button"
                                        title="Write off remaining loan balance"
                                        onclick="openWriteOff('write_off_loan', <?= (int)$row['id'] ?>, 'Loan <?= la_esc($row['loan_number']) ?>')">
                                    <i class="fa-solid fa-file-circle-xmark"></i>
                                </button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Advance No.</th>
                    <th>Amount</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Installment</th>
                    <th>Advance Date</th>
                    <th>Repayment Month</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$advanceRows): ?>
                    <tr><td colspan="10" class="empty">No salary advance records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($advanceRows as $row): ?>
                    <tr>
                        <td><span class="employee"><?= la_esc($row['staff_name']) ?></span><span class="muted"><?= la_esc($row['staff_role'] ?? 'Staff') ?></span></td>
                        <td><?= la_esc($row['advance_number']) ?></td>
                        <td class="money"><?= la_money((float)$row['amount']) ?></td>
                        <td><?= la_money((float)$row['amount_paid']) ?></td>
                        <td class="money"><?= la_money((float)$row['balance_amount']) ?></td>
                        <td><?= la_money((float)$row['installment_amount']) ?></td>
                        <td><?= la_esc($row['advance_date']) ?></td>
                        <td><?= la_esc($row['repayment_month']) ?></td>
                        <td><span class="badge <?= la_esc($row['status']) ?>"><?= la_esc($row['status']) ?></span></td>
                        <td>
                            <div class="row-actions">
                            <?php if ($row['status'] === 'Pending'): ?>
                                <form class="inline" method="post" onsubmit="return confirm('Cancel this salary advance?');">
                                    <input type="hidden" name="action" value="cancel_advance">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn danger" type="submit" title="Cancel"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php if (in_array($row['status'], ['Pending','Approved','Active'], true)): ?>
                                <a class="btn" href="hr_payroll.php?month=<?= (int)date('n') ?>&year=<?= (int)date('Y') ?>" title="Excuse or lower this month's repayment in Payroll"><i class="fa-solid fa-calculator"></i></a>
                            <?php endif; ?>

                            <?php if (in_array($row['status'], ['Approved','Active'], true) && (float)$row['balance_amount'] > 0): ?>
                                <button class="btn danger"
                                        type="button"
                                        title="Write off remaining salary advance balance"
                                        onclick="openWriteOff('write_off_advance', <?= (int)$row['id'] ?>, 'Advance <?= la_esc($row['advance_number']) ?>')">
                                    <i class="fa-solid fa-file-circle-xmark"></i>
                                </button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>
</div>
</main>

<!-- Loan modal -->
<div class="modal" id="loanModal">
<div class="modal-box">
    <div class="modal-head">
        <h2><i class="fa-solid fa-money-bill-transfer"></i> New Employee Loan</h2>
        <button class="close" type="button" onclick="closeModal('loanModal')">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="create_loan">
        <div class="modal-body">
            <div class="form-grid">
                <div class="field full">
                    <label>EMPLOYEE</label>
                    <select name="staff_id" required>
                        <option value="">Select employee</option>
                        <?php foreach ($staff as $s): ?>
                            <option value="<?= (int)$s['id'] ?>">
                                <?= la_esc(($s['full_name'] ?: $s['username']) . ' — ' . $s['role']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>PRINCIPAL AMOUNT</label>
                    <input type="number" name="principal_amount" min="0.01" step="0.01" required>
                </div>
                <div class="field">
                    <label>INTEREST AMOUNT</label>
                    <input type="number" name="interest_amount" min="0" step="0.01" value="0">
                </div>
                <div class="field">
                    <label>MONTHLY INSTALLMENT</label>
                    <input type="number" name="installment_amount" min="0.01" step="0.01" required>
                </div>
                <div class="field">
                    <label>START DATE</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label>FIRST REPAYMENT MONTH</label>
                    <input type="month" name="first_repayment_month" value="<?= date('Y-m') ?>" required>
                </div>
                <div class="field">
                    <label>PURPOSE</label>
                    <input type="text" name="purpose" placeholder="e.g. Emergency, school fees">
                </div>
                <div class="field full">
                    <label>NOTES</label>
                    <textarea name="notes" placeholder="Optional notes"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" type="button" onclick="closeModal('loanModal')">Cancel</button>
            <button class="btn primary" type="submit">Create Loan</button>
        </div>
    </form>
</div>
</div>

<!-- Advance modal -->
<div class="modal" id="advanceModal">
<div class="modal-box">
    <div class="modal-head">
        <h2><i class="fa-solid fa-hand-holding-dollar"></i> New Salary Advance</h2>
        <button class="close" type="button" onclick="closeModal('advanceModal')">&times;</button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="create_advance">
        <div class="modal-body">
            <div class="form-grid">
                <div class="field full">
                    <label>EMPLOYEE</label>
                    <select name="staff_id" required>
                        <option value="">Select employee</option>
                        <?php foreach ($staff as $s): ?>
                            <option value="<?= (int)$s['id'] ?>">
                                <?= la_esc(($s['full_name'] ?: $s['username']) . ' — ' . $s['role']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>ADVANCE AMOUNT</label>
                    <input type="number" name="amount" min="0.01" step="0.01" required>
                </div>
                <div class="field">
                    <label>REPAYMENT INSTALLMENT</label>
                    <input type="number" name="installment_amount" min="0.01" step="0.01" required>
                </div>
                <div class="field">
                    <label>ADVANCE DATE</label>
                    <input type="date" name="advance_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label>REPAYMENT MONTH</label>
                    <input type="month" name="repayment_month" value="<?= date('Y-m') ?>" required>
                </div>
                <div class="field full">
                    <label>REASON</label>
                    <input type="text" name="reason" placeholder="Reason for salary advance">
                </div>
                <div class="field full">
                    <label>NOTES</label>
                    <textarea name="notes" placeholder="Optional notes"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" type="button" onclick="closeModal('advanceModal')">Cancel</button>
            <button class="btn primary" type="submit">Create Advance</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
window.addEventListener('click',function(e){
    if(e.target.classList.contains('modal')) e.target.classList.remove('show');
});
</script>

<!-- ============================================================
     ADMIN WRITE-OFF MODAL
============================================================ -->
<div class="modal" id="writeOffModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-head">
            <h2><i class="fa-solid fa-file-circle-xmark"></i> Write Off</h2>
            <button class="close" type="button" onclick="closeWriteOff()">&times;</button>
        </div>

        <form method="post" onsubmit="return confirmWriteOff();">
            <input type="hidden" name="action" id="writeOffAction">
            <input type="hidden" name="id" id="writeOffId">

            <div style="padding:18px 20px;">
                <div id="writeOffTarget"
                     style="margin-bottom:13px;padding:10px 12px;background:#f7f9fb;border:1px solid #e0e6eb;border-radius:7px;font-size:12px;font-weight:700;color:#293641;">
                </div>

                <label style="display:block;font-size:10px;font-weight:800;color:#66737d;margin-bottom:5px;">
                    REASON FOR WRITE-OFF
                </label>

                <textarea name="reason"
                          id="writeOffReason"
                          required
                          maxlength="255"
                          style="width:100%;min-height:90px;border:1px solid #d6dee5;border-radius:7px;padding:10px;font:inherit;font-size:12px;resize:vertical;"
                          placeholder="Enter the reason for writing off the remaining balance..."></textarea>

                <div style="margin-top:8px;font-size:10px;color:#7b8790;line-height:1.5;">
                    <strong>Important:</strong> Writing off clears the remaining balance permanently and marks the record
                    <strong>Written Off</strong>. The amount, reason, administrator and date are recorded.
                </div>
            </div>

            <div class="modal-foot">
                <button class="btn" type="button" onclick="closeWriteOff()">Cancel</button>
                <button class="btn danger" type="submit">
                    <i class="fa-solid fa-file-circle-xmark"></i> Confirm Write-Off
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openWriteOff(action, id, label) {
    const modal = document.getElementById('writeOffModal');
    const actionInput = document.getElementById('writeOffAction');
    const idInput = document.getElementById('writeOffId');
    const target = document.getElementById('writeOffTarget');
    const reason = document.getElementById('writeOffReason');

    if (!modal || !actionInput || !idInput) return;

    actionInput.value = action;
    idInput.value = id;
    target.textContent = label;
    reason.value = '';

    modal.classList.add('show');

    setTimeout(function () {
        reason.focus();
    }, 50);
}

function closeWriteOff() {
    const modal = document.getElementById('writeOffModal');
    if (modal) modal.classList.remove('show');
}

function confirmWriteOff() {
    return confirm(
        'Are you sure you want to write off this remaining balance? This action will permanently clear the outstanding balance.'
    );
}

document.addEventListener('click', function (event) {
    const modal = document.getElementById('writeOffModal');

    if (modal && event.target === modal) {
        closeWriteOff();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeWriteOff();
    }
});
</script>

</body>
</html>
