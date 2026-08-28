<?php
/**
 * ============================================================
 * EchoTech POS - Statutory Payroll
 * ============================================================
 *
 * Location:
 *   /admin/actions/payroll_statutory.php
 *
 * Applies the Zambia statutory payroll engine to the current
 * payroll period created in payroll_process.php.
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

function zs_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function zs_money(float $value): string
{
    return 'K' . number_format($value, 2);
}

function zs_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function zs_column_exists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );

    return $result instanceof mysqli_result && $result->num_rows > 0;
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

$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Ensure payroll table exists
|--------------------------------------------------------------------------
*/

if (!zs_table_exists($conn, 'payroll_records')) {
    $error = 'No payroll_records table exists. Run Payroll Processing first.';
}

/*
|--------------------------------------------------------------------------
| Add employer/statutory audit columns if necessary
|--------------------------------------------------------------------------
*/

if ($error === '') {

    $alterColumns = [
        'employer_napsa' => "DECIMAL(15,2) NOT NULL DEFAULT 0",
        'employer_nhima' => "DECIMAL(15,2) NOT NULL DEFAULT 0",
        'statutory_year' => "INT NULL",
        'statutory_calculated_at' => "DATETIME NULL",
    ];

    foreach ($alterColumns as $column => $definition) {
        if (!zs_column_exists($conn, 'payroll_records', $column)) {
            $conn->query(
                "ALTER TABLE payroll_records
                 ADD COLUMN `{$column}` {$definition}"
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Calculate / update statutory values
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'calculate_statutory'
    && $error === '') {

    $stmt = $conn->prepare("
        SELECT
            id,
            basic_salary,
            allowances,
            bonus,
            overtime,
            other_earnings,
            loan_deduction,
            salary_advance,
            other_deductions,
            status
        FROM payroll_records
        WHERE payroll_period = ?
    ");

    if (!$stmt) {
        $error = 'Could not load payroll records: ' . $conn->error;
    } else {

        $stmt->bind_param('s', $period);
        $stmt->execute();
        $result = $stmt->get_result();

        $update = $conn->prepare("
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
                statutory_calculated_at = NOW()
            WHERE id = ?
              AND status NOT IN ('approved','paid','locked')
        ");

        if (!$update) {
            $error = 'Could not prepare statutory update: ' . $conn->error;
        } else {

            $updated = 0;
            $skipped = 0;

            while ($row = $result->fetch_assoc()) {

                if (in_array(
                    strtolower((string)$row['status']),
                    ['approved', 'paid', 'locked'],
                    true
                )) {
                    $skipped++;
                    continue;
                }

                $calculated = zm_calculate_zambia_payroll(
                    (float)$row['basic_salary'],
                    (float)$row['allowances'],
                    (float)$row['bonus'],
                    (float)$row['overtime'],
                    (float)$row['other_earnings'],
                    (float)$row['loan_deduction'],
                    (float)$row['salary_advance'],
                    (float)$row['other_deductions']
                );

                $paye = $calculated['paye'];
                $napsa = $calculated['napsa_employee'];
                $nhima = $calculated['nhima_employee'];
                $employerNapsa = $calculated['napsa_employer'];
                $employerNhima = $calculated['nhima_employer'];
                $gross = $calculated['gross_salary'];
                $totalDeductions = $calculated['total_deductions'];
                $net = $calculated['net_salary'];
                $statutoryYear = $year;
                $id = (int)$row['id'];

                $update->bind_param(
                    'ddddddddii',
                    $paye,
                    $napsa,
                    $nhima,
                    $employerNapsa,
                    $employerNhima,
                    $gross,
                    $totalDeductions,
                    $net,
                    $statutoryYear,
                    $id
                );

                if ($update->execute()) {
                    $updated++;
                }
            }

            $update->close();

            $success =
                $updated . ' payroll record(s) recalculated using the Zambia statutory engine.'
                . ($skipped > 0
                    ? ' ' . $skipped . ' approved/paid/locked record(s) were protected.'
                    : '');
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Load register
|--------------------------------------------------------------------------
*/

$rows = [];

if ($error === '') {

    $sql = "
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
            employer_napsa,
            employer_nhima,
            statutory_year,
            statutory_calculated_at,
            status
        FROM payroll_records
        WHERE payroll_period = ?
        ORDER BY staff_id ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        $stmt->bind_param('s', $period);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

    } else {
        $error = 'Could not load statutory payroll: ' . $conn->error;
    }
}

$totals = [
    'gross' => 0.0,
    'paye' => 0.0,
    'napsa' => 0.0,
    'nhima' => 0.0,
    'deductions' => 0.0,
    'net' => 0.0,
    'employer_napsa' => 0.0,
    'employer_nhima' => 0.0,
    'employer_cost' => 0.0,
];

foreach ($rows as $row) {
    $totals['gross'] += (float)$row['gross_salary'];
    $totals['paye'] += (float)$row['paye'];
    $totals['napsa'] += (float)$row['napsa'];
    $totals['nhima'] += (float)$row['nhima'];
    $totals['deductions'] += (float)$row['total_deductions'];
    $totals['net'] += (float)$row['net_salary'];
    $totals['employer_napsa'] += (float)$row['employer_napsa'];
    $totals['employer_nhima'] += (float)$row['employer_nhima'];
}

$totals['employer_cost'] =
    $totals['gross']
    + $totals['employer_napsa']
    + $totals['employer_nhima'];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statutory Payroll — EchoTech POS</title>

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

.statutory-page{
    max-width:1600px;
    margin:0 auto;
    padding:24px;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
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
    border-color:var(--blue);
    color:#fff;
}

.green{
    background:var(--green);
    border-color:var(--green);
    color:#fff;
}

.alert{
    padding:12px 14px;
    border-radius:8px;
    margin-bottom:15px;
    border:1px solid;
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
    grid-template-columns:repeat(5,minmax(0,1fr));
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
    font-size:11px;
    text-transform:uppercase;
    font-weight:750;
    letter-spacing:.04em;
}

.value{
    margin-top:7px;
    font-size:22px;
    font-weight:760;
}

.value.blue{color:var(--blue)}
.value.green{color:var(--green)}
.value.orange{color:var(--orange)}

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
    min-width:1350px;
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
    font-variant-numeric:tabular-nums;
}

.net{
    color:var(--green);
    font-weight:750;
}

.protected{
    color:#64748b;
    font-size:11px;
}

.bottom{
    padding:15px 18px;
    display:flex;
    justify-content:space-between;
    gap:15px;
    color:var(--muted);
}

.rules{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
    margin-bottom:18px;
}

.rule{
    padding:14px;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:9px;
}

.rule strong{
    display:block;
    margin-bottom:4px;
}

.rule span{
    color:var(--muted);
    font-size:12px;
}

@media(max-width:1100px){
    .summary{grid-template-columns:repeat(3,minmax(0,1fr))}
    .top{flex-direction:column}
}

@media(max-width:700px){
    .statutory-page{padding:14px}
    .summary{grid-template-columns:1fr}
    .rules{grid-template-columns:1fr}
    .toolbar{width:100%}
    .toolbar .control,.toolbar .btn{width:100%}
    .panel-head{align-items:stretch;flex-direction:column}
}

@media print{
    .top .toolbar,
    .rules,
    .alert,
    .panel-head .toolbar{
        display:none!important;
    }

    body{background:#fff}
    .statutory-page{padding:0}
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

<main class="statutory-page">

    <div class="top">
        <div>
            <h1>Statutory Payroll</h1>
            <p>
                Zambia statutory calculations for
                <strong><?= zs_h($periodLabel) ?></strong>.
            </p>
        </div>

        <div class="toolbar">

            <form method="get" class="toolbar">
                <select class="control" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                            <?= zs_h(date('F', mktime(0,0,0,$m,1,$year))) ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select class="control" name="year">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <button class="btn" type="submit">Load</button>
            </form>

            <form method="post" class="toolbar">
                <input type="hidden" name="action" value="calculate_statutory">
                <input type="hidden" name="month" value="<?= $month ?>">
                <input type="hidden" name="year" value="<?= $year ?>">

                <button
                    class="btn primary"
                    type="submit"
                    onclick="return confirm('Calculate PAYE, NAPSA and NHIMA for all editable payroll records in <?= zs_h($periodLabel) ?>?');"
                >
                    Calculate Statutory
                </button>
            </form>

            <a
                class="btn"
                href="payroll_process.php?month=<?= $month ?>&year=<?= $year ?>"
            >
                Payroll Processing
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
        <div class="alert success"><?= zs_h($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= zs_h($error) ?></div>
    <?php endif; ?>

    <div class="alert info">
        <strong>2026 statutory basis:</strong>
        PAYE uses the ZRA monthly bands; NAPSA is 5% employee + 5% employer
        on gross earnings, capped at K37,236 monthly insurable earnings;
        NHIMA is 1% employee + 1% employer on basic salary.
    </div>

    <section class="rules">

        <div class="rule">
            <strong>PAYE</strong>
            <span>
                0% to K5,100 · 20% to K7,100 ·
                30% to K9,200 · 37% above K9,200
            </span>
        </div>

        <div class="rule">
            <strong>NAPSA</strong>
            <span>
                5% employee + 5% employer ·
                K37,236 monthly ceiling · K1,861.80 maximum each
            </span>
        </div>

        <div class="rule">
            <strong>NHIMA</strong>
            <span>
                1% employee + 1% employer of basic salary
            </span>
        </div>

    </section>

    <section class="summary">

        <div class="card summary-card">
            <div class="label">Gross Payroll</div>
            <div class="value blue"><?= zs_money($totals['gross']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">PAYE</div>
            <div class="value orange"><?= zs_money($totals['paye']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Employee NAPSA</div>
            <div class="value"><?= zs_money($totals['napsa']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Employee NHIMA</div>
            <div class="value"><?= zs_money($totals['nhima']) ?></div>
        </div>

        <div class="card summary-card">
            <div class="label">Net Payroll</div>
            <div class="value green"><?= zs_money($totals['net']) ?></div>
        </div>

    </section>

    <section class="card panel">

        <div class="panel-head">

            <div>
                <h2>Statutory Payroll Register</h2>
                <p>
                    Employee deductions and employer statutory cost for
                    <?= zs_h($periodLabel) ?>.
                </p>
            </div>

            <div class="toolbar">
                <span style="color:var(--muted)">
                    Employer NAPSA:
                    <strong><?= zs_money($totals['employer_napsa']) ?></strong>
                </span>

                <span style="color:var(--muted)">
                    Employer NHIMA:
                    <strong><?= zs_money($totals['employer_nhima']) ?></strong>
                </span>
            </div>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                <tr>
                    <th>Staff ID</th>
                    <th>Gross Pay</th>
                    <th>PAYE</th>
                    <th>Employee NAPSA</th>
                    <th>Employee NHIMA</th>
                    <th>Other Deductions</th>
                    <th>Total Deductions</th>
                    <th>Net Pay</th>
                    <th>Employer NAPSA</th>
                    <th>Employer NHIMA</th>
                    <th>Status</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$rows): ?>

                    <tr>
                        <td colspan="11"
                            style="text-align:center;padding:45px;color:var(--muted)">
                            No payroll records found for <?= zs_h($periodLabel) ?>.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($rows as $row): ?>

                        <?php
                        $other =
                            (float)$row['loan_deduction']
                            + (float)$row['salary_advance']
                            + (float)$row['other_deductions'];
                        ?>

                        <tr>

                            <td>
                                <strong>#<?= (int)$row['staff_id'] ?></strong>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['gross_salary']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['paye']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['napsa']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['nhima']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money($other) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['total_deductions']) ?>
                            </td>

                            <td class="money net">
                                <?= zs_money((float)$row['net_salary']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['employer_napsa']) ?>
                            </td>

                            <td class="money">
                                <?= zs_money((float)$row['employer_nhima']) ?>
                            </td>

                            <td>
                                <strong><?= zs_h($row['status']) ?></strong>

                                <?php if ($row['statutory_calculated_at']): ?>
                                    <div class="protected">
                                        Calculated:
                                        <?= zs_h($row['statutory_calculated_at']) ?>
                                    </div>
                                <?php endif; ?>
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
                Employer payroll cost:
                <strong>
                    <?= zs_money($totals['employer_cost']) ?>
                </strong>
            </div>

        </div>

    </section>

</main>

</body>
</html>
