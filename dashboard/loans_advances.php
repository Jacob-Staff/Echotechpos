<?php
/**
 * ============================================================
 * PHARMACY POS - STAFF LOANS & SALARY ADVANCES
 * ============================================================
 * Location:
 *     dashboard/loans_advances.php
 *
 * Staff can:
 *   - Apply for a loan
 *   - Apply for a salary advance
 *   - View only their own applications
 *   - See approval status and outstanding balance
 *
 * Admin approvals are handled by:
 *     admin/loans_advances.php
 *
 * IMPORTANT:
 * This page writes to the SAME employee_loans and
 * salary_advances tables used by the Admin Payroll module.
 * It does not modify payroll calculations.
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_name   = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff'));

if ($pharmacy_id <= 0 || $branch_id <= 0 || $user_id <= 0) {
    header("Location: ../login.php?error=session_expired");
    exit;
}

/* ------------------------------------------------------------
   Helpers
------------------------------------------------------------ */
function staff_la_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function staff_la_money(float $amount): string
{
    return 'K' . number_format($amount, 2);
}

function staff_la_redirect(string $message = '', string $error = ''): never
{
    $query = [];

    if ($message !== '') {
        $query['saved'] = $message;
    }

    if ($error !== '') {
        $query['error'] = $error;
    }

    $url = basename($_SERVER['PHP_SELF']);

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    header("Location: {$url}");
    exit;
}

/* ------------------------------------------------------------
   Ensure shared tables exist.
   These definitions match the Admin Loans & Salary Advances
   module. IF NOT EXISTS means this does nothing if the tables
   already exist.
------------------------------------------------------------ */
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
    status ENUM('Draft','Pending','Approved','Active','Completed','Rejected','Cancelled')
        NOT NULL DEFAULT 'Pending',
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
    status ENUM('Draft','Pending','Approved','Active','Completed','Rejected','Cancelled')
        NOT NULL DEFAULT 'Pending',
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

/* ------------------------------------------------------------
   Current employee
------------------------------------------------------------ */
$current_employee = null;

$stmt = $conn->prepare("
    SELECT id, full_name, username, role, salary_amount
    FROM users
    WHERE id = ?
      AND pharmacy_id = ?
      AND status = 'Active'
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param('ii', $user_id, $pharmacy_id);
    $stmt->execute();
    $current_employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$current_employee) {
    session_destroy();
    header("Location: ../login.php?error=session_expired");
    exit;
}

$display_name = trim((string)($current_employee['full_name'] ?: $current_employee['username'] ?: $user_name));
$display_role = (string)($current_employee['role'] ?? 'Staff');
$salary_amount = (float)($current_employee['salary_amount'] ?? 0);

/* ------------------------------------------------------------
   CSRF token
------------------------------------------------------------ */
if (empty($_SESSION['staff_la_csrf'])) {
    $_SESSION['staff_la_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['staff_la_csrf'];

/* ------------------------------------------------------------
   POST actions
------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf_token, $posted_token)) {
        staff_la_redirect('', 'Your request could not be verified. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');

    /* ========================================================
       STAFF LOAN APPLICATION
    ======================================================== */
    if ($action === 'apply_loan') {

        $principal = round((float)($_POST['principal_amount'] ?? 0), 2);
        $installment = round((float)($_POST['installment_amount'] ?? 0), 2);
        $start_date = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
        $first_month = trim((string)($_POST['first_repayment_month'] ?? date('Y-m')));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($principal <= 0) {
            staff_la_redirect('', 'Please enter a valid loan amount.');
        }

        if ($installment <= 0) {
            staff_la_redirect('', 'Please enter a valid repayment installment.');
        }

        if ($installment > $principal) {
            staff_la_redirect('', 'The monthly installment cannot be greater than the requested loan amount.');
        }

        $date_check = DateTime::createFromFormat('Y-m-d', $start_date);
        if (!$date_check || $date_check->format('Y-m-d') !== $start_date) {
            staff_la_redirect('', 'Please enter a valid loan start date.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $first_month)) {
            staff_la_redirect('', 'Please select a valid first repayment month.');
        }

        /*
         * Prevent accidental duplicate active/pending loans.
         * A staff member may still apply again after a previous
         * application has been completed or cancelled.
         */
        $check = $conn->prepare("
            SELECT id
            FROM employee_loans
            WHERE pharmacy_id = ?
              AND staff_id = ?
              AND status IN ('Pending','Approved','Active')
            LIMIT 1
        ");

        if (!$check) {
            staff_la_redirect('', 'Unable to check your existing loan applications.');
        }

        $check->bind_param('ii', $pharmacy_id, $user_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            staff_la_redirect('', 'You already have a pending or active loan. Please wait for it to be processed before applying again.');
        }

        /*
         * Staff applications do not decide interest.
         * Interest is controlled by the Admin Payroll module.
         * The initial requested total is therefore the principal.
         */
        $interest = 0.00;
        $total = $principal;
        $balance = $total;

        $loan_number = 'LN-' . date('YmdHis') . '-' . random_int(100, 999);

        $stmt = $conn->prepare("
            INSERT INTO employee_loans
            (
                pharmacy_id,
                branch_id,
                staff_id,
                loan_number,
                principal_amount,
                interest_amount,
                total_amount,
                installment_amount,
                amount_paid,
                balance_amount,
                start_date,
                first_repayment_month,
                purpose,
                notes,
                status,
                created_by
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        if (!$stmt) {
            staff_la_redirect('', 'Unable to submit the loan application.');
        }

        $stmt->bind_param(
            'iiisddddssssss',
            $pharmacy_id,
            $branch_id,
            $user_id,
            $loan_number,
            $principal,
            $interest,
            $total,
            $installment,
            $balance,
            $start_date,
            $first_month,
            $purpose,
            $notes,
            $display_name
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            staff_la_redirect('', 'Loan application could not be submitted: ' . $error);
        }

        $stmt->close();

        staff_la_redirect(
            'Loan application ' . $loan_number . ' was submitted and is awaiting approval.'
        );
    }

    /* ========================================================
       STAFF SALARY ADVANCE APPLICATION
    ======================================================== */
    if ($action === 'apply_advance') {

        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $installment = round((float)($_POST['installment_amount'] ?? 0), 2);
        $advance_date = trim((string)($_POST['advance_date'] ?? date('Y-m-d')));
        $repayment_month = trim((string)($_POST['repayment_month'] ?? date('Y-m')));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($amount <= 0) {
            staff_la_redirect('', 'Please enter a valid salary advance amount.');
        }

        if ($installment <= 0) {
            staff_la_redirect('', 'Please enter a valid repayment installment.');
        }

        if ($installment > $amount) {
            staff_la_redirect('', 'The repayment installment cannot be greater than the advance amount.');
        }

        $date_check = DateTime::createFromFormat('Y-m-d', $advance_date);
        if (!$date_check || $date_check->format('Y-m-d') !== $advance_date) {
            staff_la_redirect('', 'Please enter a valid advance date.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $repayment_month)) {
            staff_la_redirect('', 'Please select a valid repayment month.');
        }

        $check = $conn->prepare("
            SELECT id
            FROM salary_advances
            WHERE pharmacy_id = ?
              AND staff_id = ?
              AND status IN ('Pending','Approved','Active')
            LIMIT 1
        ");

        if (!$check) {
            staff_la_redirect('', 'Unable to check your existing salary advance applications.');
        }

        $check->bind_param('ii', $pharmacy_id, $user_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            staff_la_redirect('', 'You already have a pending or active salary advance. Please wait for it to be processed before applying again.');
        }

        $balance = $amount;
        $advance_number = 'ADV-' . date('YmdHis') . '-' . random_int(100, 999);

        $stmt = $conn->prepare("
            INSERT INTO salary_advances
            (
                pharmacy_id,
                branch_id,
                staff_id,
                advance_number,
                amount,
                amount_paid,
                balance_amount,
                advance_date,
                repayment_month,
                installment_amount,
                reason,
                notes,
                status,
                created_by
            )
            VALUES
            (?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        if (!$stmt) {
            staff_la_redirect('', 'Unable to submit the salary advance application.');
        }

        $stmt->bind_param(
            'iiisddssdsss',
            $pharmacy_id,
            $branch_id,
            $user_id,
            $advance_number,
            $amount,
            $balance,
            $advance_date,
            $repayment_month,
            $installment,
            $reason,
            $notes,
            $display_name
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            staff_la_redirect('', 'Salary advance application could not be submitted: ' . $error);
        }

        $stmt->close();

        staff_la_redirect(
            'Salary advance ' . $advance_number . ' was submitted and is awaiting approval.'
        );
    }
}

/* ------------------------------------------------------------
   Fetch this employee's own applications
------------------------------------------------------------ */
$loan_rows = [];
$advance_rows = [];

$stmt = $conn->prepare("
    SELECT
        loan_number,
        principal_amount,
        interest_amount,
        total_amount,
        installment_amount,
        amount_paid,
        balance_amount,
        start_date,
        first_repayment_month,
        purpose,
        status,
        approved_at,
        created_at
    FROM employee_loans
    WHERE pharmacy_id = ?
      AND staff_id = ?
    ORDER BY id DESC
    LIMIT 50
");

if ($stmt) {
    $stmt->bind_param('ii', $pharmacy_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $loan_rows[] = $row;
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        advance_number,
        amount,
        amount_paid,
        balance_amount,
        advance_date,
        repayment_month,
        installment_amount,
        reason,
        status,
        approved_at,
        created_at
    FROM salary_advances
    WHERE pharmacy_id = ?
      AND staff_id = ?
    ORDER BY id DESC
    LIMIT 50
");

if ($stmt) {
    $stmt->bind_param('ii', $pharmacy_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $advance_rows[] = $row;
    }

    $stmt->close();
}

/* ------------------------------------------------------------
   Summary for this employee
------------------------------------------------------------ */
$pending_count = 0;
$active_count = 0;
$outstanding = 0.00;

$stmt = $conn->prepare("
    SELECT
        SUM(status = 'Pending') AS pending_count,
        SUM(status IN ('Approved','Active')) AS active_count,
        COALESCE(SUM(CASE
            WHEN status IN ('Approved','Active') THEN balance_amount
            ELSE 0
        END), 0) AS outstanding
    FROM employee_loans
    WHERE pharmacy_id = ?
      AND staff_id = ?
");

if ($stmt) {
    $stmt->bind_param('ii', $pharmacy_id, $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();

    $pending_count = (int)($r['pending_count'] ?? 0);
    $active_count = (int)($r['active_count'] ?? 0);
    $outstanding = (float)($r['outstanding'] ?? 0);

    $stmt->close();
}

$advance_pending = 0;
$advance_active = 0;
$advance_outstanding = 0.00;

$stmt = $conn->prepare("
    SELECT
        SUM(status = 'Pending') AS pending_count,
        SUM(status IN ('Approved','Active')) AS active_count,
        COALESCE(SUM(CASE
            WHEN status IN ('Approved','Active') THEN balance_amount
            ELSE 0
        END), 0) AS outstanding
    FROM salary_advances
    WHERE pharmacy_id = ?
      AND staff_id = ?
");

if ($stmt) {
    $stmt->bind_param('ii', $pharmacy_id, $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();

    $advance_pending = (int)($r['pending_count'] ?? 0);
    $advance_active = (int)($r['active_count'] ?? 0);
    $advance_outstanding = (float)($r['outstanding'] ?? 0);

    $stmt->close();
}

$success_message = (string)($_GET['saved'] ?? '');
$error_message = (string)($_GET['error'] ?? '');

require_once "../includes/head.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loans & Salary Advances</title>
<style>
    .staff-la-page{
        padding:20px;
        background:#f6f8fb;
        min-height:calc(100vh - 60px);
    }
    .staff-la-wrap{
        max-width:1200px;
        margin:0 auto;
    }
    .staff-la-title{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:15px;
        margin-bottom:18px;
    }
    .staff-la-title h2{
        margin:0;
        font-size:24px;
        font-weight:800;
        color:#202b36;
    }
    .staff-la-title p{
        margin:5px 0 0;
        color:#7b8792;
        font-size:13px;
    }
    .staff-la-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .staff-la-btn{
        border:1px solid #dbe2e8;
        background:#fff;
        color:#34414c;
        border-radius:7px;
        padding:10px 14px;
        font-size:12px;
        font-weight:700;
        cursor:pointer;
    }
    .staff-la-btn.primary{
        background:#166fbd;
        border-color:#166fbd;
        color:#fff;
    }
    .staff-la-btn.advance{
        background:#198754;
        border-color:#198754;
        color:#fff;
    }
    .staff-la-alert{
        padding:11px 14px;
        border-radius:8px;
        margin-bottom:14px;
        font-size:12px;
        font-weight:600;
    }
    .staff-la-alert.success{
        background:#eaf8f1;
        border:1px solid #ccebdd;
        color:#14734e;
    }
    .staff-la-alert.error{
        background:#fff0f1;
        border:1px solid #f1cfd3;
        color:#b13b47;
    }
    .staff-la-cards{
        display:grid;
        grid-template-columns:repeat(4,1fr);
        gap:12px;
        margin-bottom:18px;
    }
    .staff-la-card{
        background:#fff;
        border:1px solid #e1e7ec;
        border-radius:10px;
        padding:15px;
        box-shadow:0 2px 8px rgba(30,50,70,.04);
    }
    .staff-la-card-label{
        color:#75828d;
        font-size:10px;
        font-weight:800;
        text-transform:uppercase;
    }
    .staff-la-card-value{
        color:#26343f;
        font-size:21px;
        font-weight:800;
        margin-top:8px;
    }
    .staff-la-card-note{
        color:#8b969f;
        font-size:10px;
        margin-top:3px;
    }
    .staff-la-section{
        background:#fff;
        border:1px solid #e1e7ec;
        border-radius:10px;
        overflow:hidden;
        margin-bottom:16px;
    }
    .staff-la-section-head{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        padding:15px 17px;
        border-bottom:1px solid #e7ebee;
    }
    .staff-la-section-head h3{
        margin:0;
        font-size:15px;
        color:#26343f;
    }
    .staff-la-section-head span{
        color:#89949d;
        font-size:11px;
    }
    .staff-la-table-wrap{overflow:auto}
    .staff-la-table{
        width:100%;
        min-width:850px;
        border-collapse:collapse;
    }
    .staff-la-table th{
        padding:10px 12px;
        background:#f8fafb;
        color:#6e7b86;
        font-size:9px;
        text-transform:uppercase;
        text-align:left;
        border-bottom:1px solid #e5eaee;
    }
    .staff-la-table td{
        padding:11px 12px;
        color:#46535e;
        font-size:11px;
        border-bottom:1px solid #edf0f2;
    }
    .staff-la-table tr:last-child td{border-bottom:0}
    .staff-la-number{font-weight:800;color:#26343f}
    .staff-la-money{font-weight:800;color:#26343f}
    .staff-la-muted{display:block;color:#8c979f;font-size:9px;margin-top:2px}
    .staff-la-badge{
        display:inline-block;
        padding:5px 8px;
        border-radius:999px;
        font-size:9px;
        font-weight:800;
    }
    .staff-la-badge.Pending{background:#fff6df;color:#986900}
    .staff-la-badge.Active{background:#e8f7ef;color:#14734e}
    .staff-la-badge.Approved{background:#eaf0ff;color:#315fa7}
    .staff-la-badge.Completed{background:#edf1f4;color:#586671}
    .staff-la-badge.Rejected,
    .staff-la-badge.Cancelled{background:#fff0f1;color:#ad3c48}
    .staff-la-empty{
        padding:35px;
        text-align:center;
        color:#89949d;
        font-size:12px;
    }
    .staff-la-modal{
        display:none;
        position:fixed;
        inset:0;
        z-index:9999;
        background:rgba(20,29,38,.48);
        padding:25px;
        overflow:auto;
    }
    .staff-la-modal.show{display:block}
    .staff-la-modal-box{
        max-width:650px;
        margin:25px auto;
        background:#fff;
        border-radius:12px;
        box-shadow:0 20px 60px rgba(0,0,0,.2);
        overflow:hidden;
    }
    .staff-la-modal-head{
        padding:17px 20px;
        border-bottom:1px solid #e5eaee;
        display:flex;
        justify-content:space-between;
        align-items:center;
    }
    .staff-la-modal-head h3{
        margin:0;
        font-size:17px;
        color:#25333e;
    }
    .staff-la-close{
        border:0;
        background:none;
        font-size:22px;
        color:#7e8a94;
        cursor:pointer;
    }
    .staff-la-form{
        padding:20px;
    }
    .staff-la-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:13px;
    }
    .staff-la-field{
        display:flex;
        flex-direction:column;
        gap:5px;
    }
    .staff-la-field.full{grid-column:1/-1}
    .staff-la-field label{
        font-size:10px;
        font-weight:800;
        color:#66737d;
    }
    .staff-la-field input,
    .staff-la-field textarea{
        width:100%;
        border:1px solid #d8e0e6;
        border-radius:7px;
        padding:10px;
        font:inherit;
        font-size:12px;
        outline:none;
    }
    .staff-la-field input:focus,
    .staff-la-field textarea:focus{
        border-color:#7caed5;
        box-shadow:0 0 0 2px rgba(22,111,189,.08);
    }
    .staff-la-field textarea{
        min-height:75px;
        resize:vertical;
    }
    .staff-la-help{
        font-size:9px;
        color:#8b969f;
        line-height:1.4;
    }
    .staff-la-modal-foot{
        padding:14px 20px;
        border-top:1px solid #e5eaee;
        display:flex;
        justify-content:flex-end;
        gap:8px;
    }
    @media(max-width:900px){
        .staff-la-cards{grid-template-columns:repeat(2,1fr)}
    }
    @media(max-width:650px){
        .staff-la-page{padding:14px}
        .staff-la-title{flex-direction:column}
        .staff-la-actions{width:100%}
        .staff-la-btn{flex:1}
        .staff-la-cards{grid-template-columns:1fr 1fr}
        .staff-la-grid{grid-template-columns:1fr}
        .staff-la-field.full{grid-column:auto}
    }
</style>
</head>
<body>

<div id="main-wrapper">

    <?php
    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }
    ?>

    <div class="page-wrapper">

        <div class="staff-la-page">
            <div class="staff-la-wrap">

                <div class="staff-la-title">
                    <div>
                        <h2>Loans & Salary Advances</h2>
                        <p>Welcome, <?= staff_la_escape($display_name) ?>. Submit a request and wait for approval.</p>
                    </div>

                    <div class="staff-la-actions">
                        <button type="button" class="staff-la-btn advance" onclick="staffLAOpen('advanceModal')">
                            <i class="fas fa-hand-holding-dollar"></i>
                            Salary Advance
                        </button>

                        <button type="button" class="staff-la-btn primary" onclick="staffLAOpen('loanModal')">
                            <i class="fas fa-money-bill-transfer"></i>
                            Apply for Loan
                        </button>
                    </div>
                </div>

                <?php if ($success_message !== ''): ?>
                    <div class="staff-la-alert success">
                        <i class="fas fa-circle-check"></i>
                        <?= staff_la_escape($success_message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message !== ''): ?>
                    <div class="staff-la-alert error">
                        <i class="fas fa-circle-exclamation"></i>
                        <?= staff_la_escape($error_message) ?>
                    </div>
                <?php endif; ?>

                <div class="staff-la-cards">
                    <div class="staff-la-card">
                        <div class="staff-la-card-label">Pending Loans</div>
                        <div class="staff-la-card-value"><?= number_format($pending_count) ?></div>
                        <div class="staff-la-card-note">Waiting for approval</div>
                    </div>

                    <div class="staff-la-card">
                        <div class="staff-la-card-label">Active Loans</div>
                        <div class="staff-la-card-value"><?= number_format($active_count) ?></div>
                        <div class="staff-la-card-note"><?= staff_la_money($outstanding) ?> outstanding</div>
                    </div>

                    <div class="staff-la-card">
                        <div class="staff-la-card-label">Pending Advances</div>
                        <div class="staff-la-card-value"><?= number_format($advance_pending) ?></div>
                        <div class="staff-la-card-note">Waiting for approval</div>
                    </div>

                    <div class="staff-la-card">
                        <div class="staff-la-card-label">Active Advances</div>
                        <div class="staff-la-card-value"><?= number_format($advance_active) ?></div>
                        <div class="staff-la-card-note"><?= staff_la_money($advance_outstanding) ?> outstanding</div>
                    </div>
                </div>

                <!-- LOANS -->
                <div class="staff-la-section">
                    <div class="staff-la-section-head">
                        <div>
                            <h3>My Loan Applications</h3>
                            <span>Only your applications are visible here.</span>
                        </div>
                    </div>

                    <div class="staff-la-table-wrap">
                        <table class="staff-la-table">
                            <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Requested</th>
                                <th>Installment</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Repayment Start</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$loan_rows): ?>
                                <tr>
                                    <td colspan="7" class="staff-la-empty">
                                        You have not submitted a loan application yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loan_rows as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="staff-la-number"><?= staff_la_escape($row['loan_number']) ?></span>
                                            <?php if (!empty($row['purpose'])): ?>
                                                <span class="staff-la-muted"><?= staff_la_escape($row['purpose']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="staff-la-money"><?= staff_la_money((float)$row['principal_amount']) ?></td>
                                        <td><?= staff_la_money((float)$row['installment_amount']) ?></td>
                                        <td><?= staff_la_money((float)$row['amount_paid']) ?></td>
                                        <td class="staff-la-money"><?= staff_la_money((float)$row['balance_amount']) ?></td>
                                        <td>
                                            <?= staff_la_escape($row['first_repayment_month']) ?>
                                            <span class="staff-la-muted"><?= staff_la_escape($row['start_date']) ?></span>
                                        </td>
                                        <td>
                                            <span class="staff-la-badge <?= staff_la_escape($row['status']) ?>">
                                                <?= staff_la_escape($row['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SALARY ADVANCES -->
                <div class="staff-la-section">
                    <div class="staff-la-section-head">
                        <div>
                            <h3>My Salary Advance Applications</h3>
                            <span>Approved advances will be handled through payroll recovery.</span>
                        </div>
                    </div>

                    <div class="staff-la-table-wrap">
                        <table class="staff-la-table">
                            <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Amount</th>
                                <th>Installment</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Repayment Month</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$advance_rows): ?>
                                <tr>
                                    <td colspan="7" class="staff-la-empty">
                                        You have not submitted a salary advance application yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($advance_rows as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="staff-la-number"><?= staff_la_escape($row['advance_number']) ?></span>
                                            <?php if (!empty($row['reason'])): ?>
                                                <span class="staff-la-muted"><?= staff_la_escape($row['reason']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="staff-la-money"><?= staff_la_money((float)$row['amount']) ?></td>
                                        <td><?= staff_la_money((float)$row['installment_amount']) ?></td>
                                        <td><?= staff_la_money((float)$row['amount_paid']) ?></td>
                                        <td class="staff-la-money"><?= staff_la_money((float)$row['balance_amount']) ?></td>
                                        <td>
                                            <?= staff_la_escape($row['repayment_month']) ?>
                                            <span class="staff-la-muted"><?= staff_la_escape($row['advance_date']) ?></span>
                                        </td>
                                        <td>
                                            <span class="staff-la-badge <?= staff_la_escape($row['status']) ?>">
                                                <?= staff_la_escape($row['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- ============================================================
     LOAN APPLICATION MODAL
============================================================ -->
<div class="staff-la-modal" id="loanModal">
    <div class="staff-la-modal-box">

        <div class="staff-la-modal-head">
            <h3><i class="fas fa-money-bill-transfer"></i> Apply for Employee Loan</h3>
            <button type="button" class="staff-la-close" onclick="staffLAClose('loanModal')">&times;</button>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="apply_loan">
            <input type="hidden" name="csrf_token" value="<?= staff_la_escape($csrf_token) ?>">

            <div class="staff-la-form">
                <div class="staff-la-grid">

                    <div class="staff-la-field">
                        <label>EMPLOYEE</label>
                        <input type="text" value="<?= staff_la_escape($display_name) ?>" readonly>
                        <span class="staff-la-help">This application is automatically linked to your staff account.</span>
                    </div>

                    <div class="staff-la-field">
                        <label>ROLE</label>
                        <input type="text" value="<?= staff_la_escape($display_role) ?>" readonly>
                    </div>

                    <div class="staff-la-field">
                        <label>REQUESTED LOAN AMOUNT</label>
                        <input type="number" name="principal_amount" min="0.01" step="0.01" required placeholder="e.g. 5000">
                    </div>

                    <div class="staff-la-field">
                        <label>MONTHLY INSTALLMENT</label>
                        <input type="number" name="installment_amount" min="0.01" step="0.01" required placeholder="e.g. 500">
                    </div>

                    <div class="staff-la-field">
                        <label>START DATE</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="staff-la-field">
                        <label>FIRST REPAYMENT MONTH</label>
                        <input type="month" name="first_repayment_month" value="<?= date('Y-m') ?>" required>
                    </div>

                    <div class="staff-la-field full">
                        <label>PURPOSE</label>
                        <input type="text" name="purpose" maxlength="255" placeholder="Why are you requesting the loan?">
                    </div>

                    <div class="staff-la-field full">
                        <label>ADDITIONAL NOTES</label>
                        <textarea name="notes" placeholder="Optional additional information for the administrator"></textarea>
                    </div>

                    <div class="staff-la-field full">
                        <span class="staff-la-help">
                            Your request will be submitted as <strong>Pending</strong>.
                            An administrator must approve it before it becomes active.
                        </span>
                    </div>

                </div>
            </div>

            <div class="staff-la-modal-foot">
                <button type="button" class="staff-la-btn" onclick="staffLAClose('loanModal')">Cancel</button>
                <button type="submit" class="staff-la-btn primary">
                    <i class="fas fa-paper-plane"></i> Submit Loan Application
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     SALARY ADVANCE MODAL
============================================================ -->
<div class="staff-la-modal" id="advanceModal">
    <div class="staff-la-modal-box">

        <div class="staff-la-modal-head">
            <h3><i class="fas fa-hand-holding-dollar"></i> Apply for Salary Advance</h3>
            <button type="button" class="staff-la-close" onclick="staffLAClose('advanceModal')">&times;</button>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="apply_advance">
            <input type="hidden" name="csrf_token" value="<?= staff_la_escape($csrf_token) ?>">

            <div class="staff-la-form">
                <div class="staff-la-grid">

                    <div class="staff-la-field">
                        <label>EMPLOYEE</label>
                        <input type="text" value="<?= staff_la_escape($display_name) ?>" readonly>
                        <span class="staff-la-help">This application is automatically linked to your staff account.</span>
                    </div>

                    <div class="staff-la-field">
                        <label>MONTHLY SALARY</label>
                        <input type="text" value="<?= staff_la_money($salary_amount) ?>" readonly>
                    </div>

                    <div class="staff-la-field">
                        <label>ADVANCE AMOUNT</label>
                        <input type="number" name="amount" min="0.01" step="0.01" required placeholder="e.g. 1000">
                    </div>

                    <div class="staff-la-field">
                        <label>REPAYMENT INSTALLMENT</label>
                        <input type="number" name="installment_amount" min="0.01" step="0.01" required placeholder="e.g. 500">
                    </div>

                    <div class="staff-la-field">
                        <label>ADVANCE DATE</label>
                        <input type="date" name="advance_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="staff-la-field">
                        <label>REPAYMENT MONTH</label>
                        <input type="month" name="repayment_month" value="<?= date('Y-m') ?>" required>
                    </div>

                    <div class="staff-la-field full">
                        <label>REASON</label>
                        <input type="text" name="reason" maxlength="255" placeholder="Reason for the salary advance">
                    </div>

                    <div class="staff-la-field full">
                        <label>ADDITIONAL NOTES</label>
                        <textarea name="notes" placeholder="Optional additional information for the administrator"></textarea>
                    </div>

                    <div class="staff-la-field full">
                        <span class="staff-la-help">
                            Your request will be submitted as <strong>Pending</strong>.
                            An administrator must approve it before it becomes active.
                        </span>
                    </div>

                </div>
            </div>

            <div class="staff-la-modal-foot">
                <button type="button" class="staff-la-btn" onclick="staffLAClose('advanceModal')">Cancel</button>
                <button type="submit" class="staff-la-btn advance">
                    <i class="fas fa-paper-plane"></i> Submit Advance Application
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function staffLAOpen(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.add('show');
}

function staffLAClose(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.remove('show');
}

document.addEventListener('click', function (event) {
    if (event.target.classList.contains('staff-la-modal')) {
        event.target.classList.remove('show');
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.staff-la-modal.show').forEach(function (modal) {
            modal.classList.remove('show');
        });
    }
});
</script>

</body>
</html>
