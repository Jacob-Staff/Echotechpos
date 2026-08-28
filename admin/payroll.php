<?php
/**
 * ============================================================
 * EchoTech POS - PAYROLL
 * Phase 1: Payroll Dashboard + Database Foundation
 * ============================================================
 *
 * Basic Salary source:
 *     users.salary_amount
 *
 * This page does NOT calculate statutory deductions yet.
 * Payroll calculation/review/payment will be added in later phases.
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Existing POS connection + authorization
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../includes/conn.php';

if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
}

if (function_exists('require_login')) {
    require_login();
}

/*
|--------------------------------------------------------------------------
| Tenant context
|--------------------------------------------------------------------------
*/
$pharmacyId = function_exists('current_pharmacy')
    ? (int)(current_pharmacy() ?? ($_SESSION['pharmacy_id'] ?? 0))
    : (int)($_SESSION['pharmacy_id'] ?? 0);

$branchId = function_exists('current_branch')
    ? (int)(current_branch() ?? ($_SESSION['branch_id'] ?? 0))
    : (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacyId <= 0) {
    http_response_code(403);
    exit('No valid pharmacy is assigned to this account.');
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function payroll_money(float $amount): string
{
    return 'K ' . number_format($amount, 2);
}

function payroll_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Current period
|--------------------------------------------------------------------------
*/
$year  = (int)date('Y');
$month = (int)date('n');

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

/*
|--------------------------------------------------------------------------
| Branch filter
|--------------------------------------------------------------------------
*/
$selectedBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : $branchId;

if ($selectedBranch <= 0) {
    $selectedBranch = $branchId;
}

/*
|--------------------------------------------------------------------------
| Fetch branches belonging to this pharmacy
|--------------------------------------------------------------------------
*/
$branches = [];

$stmt = $conn->prepare(
    "SELECT id, branch_name
     FROM branches
     WHERE pharmacy_id = ?
       AND is_active = 1
     ORDER BY branch_name"
);

if ($stmt) {
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Employee totals
|--------------------------------------------------------------------------
| Active staff only. Salary comes directly from users.salary_amount.
|--------------------------------------------------------------------------
*/
$totalEmployees = 0;
$grossPayroll   = 0.00;
$zeroSalary     = 0;

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS employee_count,
        COALESCE(SUM(salary_amount), 0) AS gross_amount,
        COALESCE(SUM(CASE WHEN salary_amount <= 0 THEN 1 ELSE 0 END), 0) AS zero_salary
     FROM users
     WHERE pharmacy_id = ?
       AND branch_id = ?
       AND status = 'Active'"
);

if ($stmt) {
    $stmt->bind_param('ii', $pharmacyId, $selectedBranch);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $totalEmployees = (int)($row['employee_count'] ?? 0);
    $grossPayroll   = (float)($row['gross_amount'] ?? 0);
    $zeroSalary     = (int)($row['zero_salary'] ?? 0);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Existing payroll payment history for current month
|--------------------------------------------------------------------------
*/
$paidEmployees = 0;
$paidAmount    = 0.00;

$stmt = $conn->prepare(
    "SELECT
        COUNT(DISTINCT ph.user_id) AS paid_employees,
        COALESCE(SUM(ph.amount_paid), 0) AS paid_amount
     FROM payroll_history ph
     INNER JOIN users u ON u.id = ph.user_id
     WHERE u.pharmacy_id = ?
       AND u.branch_id = ?
       AND ph.payment_date >= ?
       AND ph.payment_date < DATE_ADD(?, INTERVAL 1 DAY)"
);

if ($stmt) {
    $stmt->bind_param(
        'iiss',
        $pharmacyId,
        $selectedBranch,
        $monthStart,
        $monthEnd
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $paidEmployees = (int)($row['paid_employees'] ?? 0);
    $paidAmount    = (float)($row['paid_amount'] ?? 0);

    $stmt->close();
}

$unpaidEmployees = max(0, $totalEmployees - $paidEmployees);

/*
|--------------------------------------------------------------------------
| Current payroll run, if Phase 1 SQL has been installed
|--------------------------------------------------------------------------
*/
$currentRun = null;

$stmt = $conn->prepare(
    "SELECT *
     FROM payroll_runs
     WHERE pharmacy_id = ?
       AND (branch_id = ? OR branch_id IS NULL)
       AND period_year = ?
       AND period_month = ?
     ORDER BY id DESC
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('iiii', $pharmacyId, $selectedBranch, $year, $month);
    $stmt->execute();
    $currentRun = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Recent payroll history
|--------------------------------------------------------------------------
*/
$history = [];

$stmt = $conn->prepare(
    "SELECT
        ph.payment_month,
        COUNT(DISTINCT ph.user_id) AS employees,
        COALESCE(SUM(ph.amount_paid), 0) AS amount_paid,
        MAX(ph.payment_date) AS last_payment
     FROM payroll_history ph
     INNER JOIN users u ON u.id = ph.user_id
     WHERE u.pharmacy_id = ?
       AND u.branch_id = ?
     GROUP BY ph.payment_month
     ORDER BY MAX(ph.payment_date) DESC
     LIMIT 6"
);

if ($stmt) {
    $stmt->bind_param('ii', $pharmacyId, $selectedBranch);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Page shell
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll | EchoTech POS</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root{
            --charcoal:#283847;
            --charcoal-dark:#202c37;
            --blue:#1687f5;
            --green:#0aa86b;
            --orange:#f08a19;
            --red:#dc3545;
            --purple:#6557e8;
            --bg:#f4f6f9;
            --card:#ffffff;
            --border:#e3e8ee;
            --text:#1d2935;
            --muted:#718096;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            background:var(--bg);
            color:var(--text);
            font-family:Inter,Arial,sans-serif;
            font-size:14px;
        }

        .payroll-wrap{
            padding:22px;
            max-width:1450px;
            margin:0 auto;
        }

        .payroll-head{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:20px 22px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            margin-bottom:18px;
        }

        .title-area h1{
            margin:0;
            font-size:26px;
            font-weight:800;
        }

        .title-area p{
            margin:5px 0 0;
            color:var(--muted);
            font-size:14px;
        }

        .head-actions{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }

        select,.btn{
            height:40px;
            border:1px solid var(--border);
            border-radius:8px;
            background:#fff;
            padding:0 13px;
            font-size:14px;
        }

        .btn{
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            text-decoration:none;
            color:var(--text);
        }

        .btn-primary{
            background:var(--blue);
            color:#fff;
            border-color:var(--blue);
            font-weight:700;
        }

        .stats{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:15px;
            margin-bottom:18px;
        }

        .stat{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:19px;
            position:relative;
            overflow:hidden;
        }

        .stat-icon{
            width:42px;
            height:42px;
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#edf5ff;
            color:var(--blue);
            margin-bottom:13px;
            font-size:18px;
        }

        .stat:nth-child(2) .stat-icon{
            background:#edf9f4;
            color:var(--green);
        }

        .stat:nth-child(3) .stat-icon{
            background:#fff5e9;
            color:var(--orange);
        }

        .stat:nth-child(4) .stat-icon{
            background:#fff0f2;
            color:var(--red);
        }

        .stat-label{
            color:var(--muted);
            font-size:13px;
            margin-bottom:5px;
        }

        .stat-value{
            font-size:24px;
            font-weight:800;
        }

        .grid{
            display:grid;
            grid-template-columns:minmax(0,2fr) minmax(300px,1fr);
            gap:18px;
        }

        .card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            overflow:hidden;
        }

        .card-head{
            padding:17px 19px;
            border-bottom:1px solid var(--border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .card-head h2{
            margin:0;
            font-size:17px;
        }

        .card-head span{
            color:var(--muted);
            font-size:13px;
        }

        .run-body{
            padding:20px;
        }

        .period-box{
            background:#f7f9fb;
            border:1px solid var(--border);
            border-radius:10px;
            padding:17px;
            margin-bottom:16px;
        }

        .period-title{
            font-size:19px;
            font-weight:800;
        }

        .period-meta{
            color:var(--muted);
            margin-top:5px;
        }

        .run-steps{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:10px;
            margin:18px 0;
        }

        .step{
            border:1px solid var(--border);
            border-radius:9px;
            padding:13px;
            background:#fff;
        }

        .step strong{
            display:block;
            font-size:13px;
            margin-bottom:4px;
        }

        .step span{
            font-size:12px;
            color:var(--muted);
        }

        .step.active{
            border-color:#a9d5ff;
            background:#f3f9ff;
        }

        .run-summary{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:10px;
            margin-bottom:18px;
        }

        .mini{
            border:1px solid var(--border);
            border-radius:9px;
            padding:13px;
        }

        .mini label{
            display:block;
            color:var(--muted);
            font-size:12px;
            margin-bottom:5px;
        }

        .mini strong{
            font-size:17px;
        }

        .notice{
            padding:12px 14px;
            border-radius:9px;
            background:#fff7e9;
            border:1px solid #f4d6a4;
            color:#805b1c;
            font-size:13px;
            margin-bottom:15px;
        }

        .side-list{
            padding:6px 0;
        }

        .side-row{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            padding:13px 18px;
            border-bottom:1px solid #eef1f4;
        }

        .side-row:last-child{border-bottom:0}

        .side-row b{font-size:13px}
        .side-row span{
            color:var(--muted);
            font-size:12px;
        }

        .history{
            margin-top:18px;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th,td{
            padding:12px 14px;
            border-bottom:1px solid #eef1f4;
            text-align:left;
        }

        th{
            background:#f8fafb;
            color:#657382;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.02em;
        }

        td{
            font-size:13px;
        }

        .empty{
            padding:28px;
            text-align:center;
            color:var(--muted);
        }

        .badge{
            display:inline-flex;
            align-items:center;
            padding:5px 9px;
            border-radius:999px;
            background:#edf9f4;
            color:#087b51;
            font-size:11px;
            font-weight:700;
        }

        @media(max-width:1000px){
            .stats{grid-template-columns:repeat(2,1fr)}
            .grid{grid-template-columns:1fr}
        }

        @media(max-width:650px){
            .payroll-wrap{padding:12px}
            .payroll-head{align-items:flex-start;flex-direction:column}
            .stats{grid-template-columns:1fr}
            .run-steps,.run-summary{grid-template-columns:1fr}
            .title-area h1{font-size:23px}
            table{min-width:650px}
            .card{overflow-x:auto}
            .card-head{min-width:650px}
        }
    </style>
</head>

<body>

<?php
/*
 * Keep the existing POS shell.
 * The existing aside/header files remain untouched.
 */
if (file_exists(__DIR__ . '/../includes/header.php')) {
    require_once __DIR__ . '/../includes/header.php';
}

if (file_exists(__DIR__ . '/../includes/aside.php')) {
    require_once __DIR__ . '/../includes/aside.php';
}
?>

<main class="payroll-wrap">

    <section class="payroll-head">
        <div class="title-area">
            <h1>Payroll</h1>
            <p>Manage staff salaries, payroll periods and employee payments.</p>
        </div>

        <div class="head-actions">
            <form method="get" style="display:flex;gap:8px;margin:0;">
                <select name="branch" onchange="this.form.submit()">
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id']; ?>"
                            <?= $selectedBranch === (int)$branch['id'] ? 'selected' : ''; ?>>
                            <?= payroll_h($branch['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <button class="btn btn-primary" type="button"
                    onclick="alert('Payroll calculation will be enabled in Phase 4.');">
                <i class="fa-solid fa-calculator"></i>
                Calculate Payroll
            </button>
        </div>
    </section>

    <section class="stats">

        <div class="stat">
            <div class="stat-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-label">Active Employees</div>
            <div class="stat-value"><?= number_format($totalEmployees); ?></div>
        </div>

        <div class="stat">
            <div class="stat-icon">
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
            <div class="stat-label">Monthly Basic Payroll</div>
            <div class="stat-value"><?= payroll_money($grossPayroll); ?></div>
        </div>

        <div class="stat">
            <div class="stat-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="stat-label">Paid This Month</div>
            <div class="stat-value"><?= payroll_money($paidAmount); ?></div>
        </div>

        <div class="stat">
            <div class="stat-icon">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div class="stat-label">Unpaid Employees</div>
            <div class="stat-value"><?= number_format($unpaidEmployees); ?></div>
        </div>

    </section>

    <section class="grid">

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Payroll</h2>
                    <span><?= payroll_h($monthLabel); ?> · Current branch</span>
                </div>

                <?php if ($currentRun): ?>
                    <span class="badge">
                        <?= payroll_h($currentRun['status']); ?>
                    </span>
                <?php else: ?>
                    <span class="badge">Not started</span>
                <?php endif; ?>
            </div>

            <div class="run-body">

                <div class="period-box">
                    <div class="period-title"><?= payroll_h($monthLabel); ?> Payroll</div>
                    <div class="period-meta">
                        <?= payroll_h(date('d M Y', strtotime($monthStart))); ?>
                        –
                        <?= payroll_h(date('d M Y', strtotime($monthEnd))); ?>
                    </div>
                </div>

                <?php if ($zeroSalary > 0): ?>
                    <div class="notice">
                        <strong><?= number_format($zeroSalary); ?> active employee(s)</strong>
                        currently have a Basic Salary of K 0.00.
                        Review their salary in Staff Management before calculating payroll.
                    </div>
                <?php endif; ?>

                <div class="run-steps">
                    <div class="step active">
                        <strong>1. Prepare</strong>
                        <span>Review active staff and salaries</span>
                    </div>

                    <div class="step">
                        <strong>2. Calculate</strong>
                        <span>Generate the payroll figures</span>
                    </div>

                    <div class="step">
                        <strong>3. Approve</strong>
                        <span>Management review</span>
                    </div>

                    <div class="step">
                        <strong>4. Pay</strong>
                        <span>Record employee payments</span>
                    </div>
                </div>

                <div class="run-summary">
                    <div class="mini">
                        <label>Employees</label>
                        <strong><?= number_format($totalEmployees); ?></strong>
                    </div>

                    <div class="mini">
                        <label>Basic Salary Total</label>
                        <strong><?= payroll_money($grossPayroll); ?></strong>
                    </div>

                    <div class="mini">
                        <label>Already Paid</label>
                        <strong><?= payroll_money($paidAmount); ?></strong>
                    </div>
                </div>

                <button class="btn btn-primary" type="button"
                        onclick="alert('Payroll calculation will be enabled in Phase 4.');">
                    <i class="fa-solid fa-play"></i>
                    Start Payroll Preparation
                </button>

            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Payroll Overview</h2>
                    <span><?= payroll_h($monthLabel); ?></span>
                </div>
            </div>

            <div class="side-list">

                <div class="side-row">
                    <div>
                        <b>Basic payroll</b>
                        <span>Active staff salaries</span>
                    </div>
                    <b><?= payroll_money($grossPayroll); ?></b>
                </div>

                <div class="side-row">
                    <div>
                        <b>Employees paid</b>
                        <span>Recorded this month</span>
                    </div>
                    <b><?= number_format($paidEmployees); ?></b>
                </div>

                <div class="side-row">
                    <div>
                        <b>Employees pending</b>
                        <span>Not yet recorded as paid</span>
                    </div>
                    <b><?= number_format($unpaidEmployees); ?></b>
                </div>

                <div class="side-row">
                    <div>
                        <b>Salary records needing review</b>
                        <span>Basic salary is K 0.00</span>
                    </div>
                    <b><?= number_format($zeroSalary); ?></b>
                </div>

            </div>
        </div>

    </section>

    <section class="card history">
        <div class="card-head">
            <div>
                <h2>Payroll History</h2>
                <span>Previously recorded employee payments</span>
            </div>
        </div>

        <?php if (!$history): ?>

            <div class="empty">
                <i class="fa-solid fa-receipt"
                   style="font-size:28px;margin-bottom:10px;"></i>
                <div>No payroll payment history has been recorded for this branch yet.</div>
            </div>

        <?php else: ?>

            <div style="overflow-x:auto;">
                <table>
                    <thead>
                    <tr>
                        <th>Payroll Period</th>
                        <th>Employees Paid</th>
                        <th>Amount Paid</th>
                        <th>Last Payment</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?= payroll_h((string)$row['payment_month']); ?></td>
                            <td><?= number_format((int)$row['employees']); ?></td>
                            <td><?= payroll_money((float)$row['amount_paid']); ?></td>
                            <td>
                                <?= payroll_h(
                                    date(
                                        'd M Y H:i',
                                        strtotime((string)$row['last_payment'])
                                    )
                                ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

</main>

</body>
</html>
