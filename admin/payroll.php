<?php
/**
 * ============================================================
 * EchoTech POS
 * Admin Payroll - Phase 1
 * ============================================================
 *
 * Uses the reusable Admin shell:
 *   /admin/actions/admin_aside.php
 *   /admin/actions/admin_header.php
 *
 * Phase 1:
 * - Staff payroll register
 * - Monthly period selector
 * - Branch filter
 * - Staff search
 * - Basic salary from staff records
 * - Gross / deductions / net summary
 * - Print payroll
 *
 * No payroll table is required for this phase.
 * Payroll figures are calculated from the existing staff records.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conn.php';

require_admin();

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);

if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

$user_role = current_role();
$user_display_name = current_user();

/* ------------------------------------------------------------
| Helpers
------------------------------------------------------------ */

function payroll_esc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function payroll_table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = @$conn->query("SHOW TABLES LIKE '{$table}'");

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function payroll_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $result = @$conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function payroll_first_column(
    mysqli $conn,
    string $table,
    array $candidates
): ?string {
    foreach ($candidates as $column) {
        if (payroll_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function payroll_rows(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = @$conn->prepare($sql);

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

    $result = @$stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

function payroll_scalar(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = [],
    mixed $default = 0
): mixed {
    $stmt = @$conn->prepare($sql);

    if (!$stmt) {
        return $default;
    }

    if ($types !== '') {
        @$stmt->bind_param($types, ...$params);
    }

    if (!@$stmt->execute()) {
        $stmt->close();
        return $default;
    }

    $result = @$stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$row) {
        return $default;
    }

    return array_values($row)[0] ?? $default;
}

function payroll_money(float $amount): string
{
    return 'K' . number_format($amount, 2);
}

/* ------------------------------------------------------------
| Period
------------------------------------------------------------ */

$selected_month = isset($_GET['month'])
    ? max(1, min(12, (int)$_GET['month']))
    : (int)date('n');

$selected_year = isset($_GET['year'])
    ? max(2020, min(2100, (int)$_GET['year']))
    : (int)date('Y');

$period_start = sprintf(
    '%04d-%02d-01',
    $selected_year,
    $selected_month
);

$period_end = date(
    'Y-m-t',
    strtotime($period_start)
);

$period_label = date(
    'F Y',
    strtotime($period_start)
);

$search = trim((string)($_GET['search'] ?? ''));
$branch_filter = (int)($_GET['branch_id'] ?? 0);

/* ------------------------------------------------------------
| Pharmacy
------------------------------------------------------------ */

$pharmacy_name = 'PHARMANOVA';

if (payroll_table_exists($conn, 'pharmacies')) {
    $name_column = payroll_first_column(
        $conn,
        'pharmacies',
        ['name', 'pharmacy_name', 'business_name']
    );

    if ($name_column) {
        $found_name = payroll_scalar(
            $conn,
            "SELECT `{$name_column}`
             FROM pharmacies
             WHERE id = ?
             LIMIT 1",
            'i',
            [$pharmacy_id],
            ''
        );

        if ($found_name !== '') {
            $pharmacy_name = (string)$found_name;
        }
    }
}

/* ------------------------------------------------------------
| Branches
------------------------------------------------------------ */

$branches = [];

if (payroll_table_exists($conn, 'branches')) {
    $branch_name_column = payroll_first_column(
        $conn,
        'branches',
        ['branch_name', 'name', 'branch']
    );

    if ($branch_name_column) {
        $branches = payroll_rows(
            $conn,
            "SELECT id, `{$branch_name_column}` AS branch_name
             FROM branches
             WHERE pharmacy_id = ?
             ORDER BY `{$branch_name_column}` ASC",
            'i',
            [$pharmacy_id]
        );
    }
}

$branch_count = count($branches);

/* ------------------------------------------------------------
| Locate staff table
|
| The project has evolved through different staff/user layouts,
| so Phase 1 detects the existing employee table safely instead
| of assuming a single schema.
------------------------------------------------------------ */

$staff_table = null;

foreach (['staff', 'users', 'employees', 'profiles'] as $candidate) {
    if (payroll_table_exists($conn, $candidate)) {
        $salary_candidate = payroll_first_column(
            $conn,
            $candidate,
            [
                'basic_salary',
                'basicSalary',
                'salary',
                'monthly_salary',
                'base_salary'
            ]
        );

        if ($salary_candidate) {
            $staff_table = $candidate;
            break;
        }
    }
}

/* ------------------------------------------------------------
| Staff columns
------------------------------------------------------------ */

$staff_rows = [];

$staff_id_column = null;
$name_column = null;
$role_column = null;
$branch_id_column = null;
$status_column = null;
$salary_column = null;
$email_column = null;
$employee_number_column = null;

if ($staff_table) {
    $staff_id_column = payroll_first_column(
        $conn,
        $staff_table,
        ['id', 'staff_id', 'user_id', 'employee_id']
    );

    $name_column = payroll_first_column(
        $conn,
        $staff_table,
        ['full_name', 'name', 'staff_name', 'employee_name', 'username']
    );

    $role_column = payroll_first_column(
        $conn,
        $staff_table,
        ['role', 'user_role', 'position', 'designation']
    );

    $branch_id_column = payroll_first_column(
        $conn,
        $staff_table,
        ['branch_id', 'branch']
    );

    $status_column = payroll_first_column(
        $conn,
        $staff_table,
        ['status', 'is_active', 'active']
    );

    $salary_column = payroll_first_column(
        $conn,
        $staff_table,
        [
            'basic_salary',
            'basicSalary',
            'salary',
            'monthly_salary',
            'base_salary'
        ]
    );

    $email_column = payroll_first_column(
        $conn,
        $staff_table,
        ['email', 'email_address']
    );

    $employee_number_column = payroll_first_column(
        $conn,
        $staff_table,
        ['employee_number', 'employee_no', 'staff_number', 'staff_no']
    );

    if ($staff_id_column && $name_column && $salary_column) {

        $select = [
            "s.`{$staff_id_column}` AS staff_id",
            "s.`{$name_column}` AS staff_name",
            "s.`{$salary_column}` AS basic_salary"
        ];

        $select[] = $role_column
            ? "s.`{$role_column}` AS staff_role"
            : "'Staff' AS staff_role";

        $select[] = $branch_id_column
            ? "s.`{$branch_id_column}` AS branch_id"
            : "0 AS branch_id";

        $select[] = $status_column
            ? "s.`{$status_column}` AS staff_status"
            : "'Active' AS staff_status";

        $select[] = $email_column
            ? "s.`{$email_column}` AS email"
            : "'' AS email";

        $select[] = $employee_number_column
            ? "s.`{$employee_number_column}` AS employee_number"
            : "'' AS employee_number";

        $where = [
            "s.`pharmacy_id` = ?"
        ];

        $types = 'i';
        $params = [$pharmacy_id];

        if ($branch_filter > 0 && $branch_id_column) {
            $where[] = "s.`{$branch_id_column}` = ?";
            $types .= 'i';
            $params[] = $branch_filter;
        }

        if ($search !== '') {
            $where[] = "(
                s.`{$name_column}` LIKE ?
                OR " . ($email_column
                    ? "s.`{$email_column}` LIKE ?"
                    : "'' LIKE ?") . "
                OR " . ($employee_number_column
                    ? "s.`{$employee_number_column}` LIKE ?"
                    : "'' LIKE ?") . "
            )";

            $like = '%' . $search . '%';

            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        /*
         * Active staff should be included by default.
         * This deliberately accepts common active representations.
         */
        if ($status_column) {
            $where[] = "(
                s.`{$status_column}` IS NULL
                OR s.`{$status_column}` = ''
                OR s.`{$status_column}` = 'Active'
                OR s.`{$status_column}` = 'active'
                OR s.`{$status_column}` = 1
                OR s.`{$status_column}` = '1'
            )";
        }

        $sql = "
            SELECT " . implode(",\n", $select) . "
            FROM `{$staff_table}` s
            WHERE " . implode("\n AND ", $where) . "
            ORDER BY s.`{$name_column}` ASC
        ";

        $staff_rows = payroll_rows(
            $conn,
            $sql,
            $types,
            $params
        );
    }
}

/* ------------------------------------------------------------
| Branch lookup
------------------------------------------------------------ */

$branch_names = [];

foreach ($branches as $branch) {
    $branch_names[(int)$branch['id']] = $branch['branch_name'];
}

/* ------------------------------------------------------------
| Payroll calculations
|
| Phase 1 intentionally starts from the existing basic salary.
| Allowances and deductions are zero until their dedicated
| payroll controls are introduced.
------------------------------------------------------------ */

$payroll = [];

$total_basic = 0.0;
$total_allowances = 0.0;
$total_deductions = 0.0;
$total_net = 0.0;

foreach ($staff_rows as $staff) {

    $basic = is_numeric($staff['basic_salary'])
        ? (float)$staff['basic_salary']
        : 0.0;

    $allowances = 0.0;
    $deductions = 0.0;

    $gross = $basic + $allowances;
    $net = max(0, $gross - $deductions);

    $branch_id = (int)($staff['branch_id'] ?? 0);

    $status_raw = (string)($staff['staff_status'] ?? 'Active');

    $active = !in_array(
        strtolower($status_raw),
        ['0', 'inactive', 'disabled', 'terminated'],
        true
    );

    $payroll[] = [
        'staff_id' => (int)$staff['staff_id'],
        'employee_number' => (string)($staff['employee_number'] ?? ''),
        'staff_name' => (string)($staff['staff_name'] ?? 'Unnamed Staff'),
        'role' => (string)($staff['staff_role'] ?? 'Staff'),
        'branch_id' => $branch_id,
        'branch_name' => $branch_names[$branch_id] ?? 'Main Branch',
        'basic' => $basic,
        'allowances' => $allowances,
        'gross' => $gross,
        'deductions' => $deductions,
        'net' => $net,
        'active' => $active
    ];

    $total_basic += $basic;
    $total_allowances += $allowances;
    $total_deductions += $deductions;
    $total_net += $net;
}

$staff_count = count($payroll);

/* ------------------------------------------------------------
| Admin shell variables
------------------------------------------------------------ */

$admin_page_title = 'Payroll';

/* ------------------------------------------------------------
| Months
------------------------------------------------------------ */

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$year_options = range(
    (int)date('Y') - 2,
    (int)date('Y') + 2
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Payroll | <?php echo payroll_esc($pharmacy_name); ?></title>

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
    --surface-soft:#f8fafc;
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
    --purple:#7658e8;
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

button,
input,
select{
    font:inherit;
}

button,
select{
    cursor:pointer;
}

a{
    text-decoration:none;
    color:inherit;
}

.main{
    margin-left:250px;
    min-height:100vh;
}

.payroll-content{
    max-width:1600px;
    margin:auto;
    padding:24px 28px 40px;
}

/* ------------------------------------------------------------
| Page heading
------------------------------------------------------------ */

.page-heading{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:20px;
    margin-bottom:18px;
}

.page-heading h1{
    margin:0;
    font-size:27px;
    line-height:1.1;
    font-weight:800;
    letter-spacing:-.6px;
}

.page-heading p{
    margin:7px 0 0;
    color:var(--muted);
    font-size:12px;
}

.page-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    height:39px;
    border:1px solid var(--border);
    background:#fff;
    color:#52606d;
    border-radius:8px;
    padding:0 14px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}

.btn:hover{
    border-color:#b8c7da;
    box-shadow:0 3px 10px rgba(30,50,80,.08);
}

.btn.primary{
    background:var(--blue);
    border-color:var(--blue);
    color:#fff;
}

.btn.green{
    background:var(--green);
    border-color:var(--green);
    color:#fff;
}

/* ------------------------------------------------------------
| Payroll navigation
------------------------------------------------------------ */

.payroll-tabs{
    display:flex;
    align-items:center;
    gap:6px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:6px;
    box-shadow:var(--shadow);
    margin-bottom:16px;
    overflow:auto;
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
    white-space:nowrap;
}

.payroll-tab:hover{
    background:#f3f6fa;
    color:var(--text);
}

.payroll-tab.active{
    background:var(--blue-soft);
    color:var(--blue);
}

/* ------------------------------------------------------------
| Summary cards
------------------------------------------------------------ */

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:13px;
    margin-bottom:16px;
}

.summary-card{
    position:relative;
    overflow:hidden;
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px 17px;
    min-height:112px;
}

.summary-card:after{
    content:"";
    position:absolute;
    width:85px;
    height:85px;
    right:-34px;
    bottom:-42px;
    border-radius:50%;
    background:rgba(36,107,254,.05);
}

.summary-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    color:#74808c;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.8px;
}

.summary-icon{
    width:34px;
    height:34px;
    display:grid;
    place-items:center;
    border-radius:8px;
    background:var(--blue-soft);
    color:var(--blue);
}

.summary-value{
    margin-top:9px;
    font-size:23px;
    font-weight:800;
    letter-spacing:-.4px;
}

.summary-sub{
    margin-top:4px;
    color:var(--muted);
    font-size:10px;
}

.summary-card.green .summary-icon{
    color:var(--green);
    background:var(--green-soft);
}

.summary-card.yellow .summary-icon{
    color:var(--yellow);
    background:var(--yellow-soft);
}

.summary-card.red .summary-icon{
    color:var(--red);
    background:var(--red-soft);
}

/* ------------------------------------------------------------
| Filters
------------------------------------------------------------ */

.filter-panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    margin-bottom:14px;
}

.filter-row{
    display:grid;
    grid-template-columns:minmax(220px,1.8fr) 180px 150px auto;
    gap:9px;
    align-items:center;
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
    font-size:12px;
}

.field:focus{
    border-color:#8bb0ff;
    box-shadow:0 0 0 3px var(--blue-soft);
}

.search-wrap{
    position:relative;
}

.search-wrap i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#93a0ad;
    font-size:12px;
    pointer-events:none;
}

.search-wrap .field{
    padding-left:34px;
}

.filter-note{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:10px;
    color:var(--muted);
    font-size:10px;
}

/* ------------------------------------------------------------
| Payroll table
------------------------------------------------------------ */

.payroll-panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
}

.panel-head{
    min-height:61px;
    padding:0 17px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    border-bottom:1px solid var(--border);
}

.panel-title b{
    display:block;
    color:var(--charcoal);
    font-size:13px;
}

.panel-title span{
    display:block;
    color:var(--muted);
    font-size:10px;
    margin-top:4px;
}

.period-pill{
    background:#f5f7fa;
    border:1px solid var(--border);
    color:#53616e;
    border-radius:20px;
    padding:7px 11px;
    font-size:10px;
    font-weight:800;
    white-space:nowrap;
}

.table-wrap{
    width:100%;
    overflow:auto;
}

.payroll-table{
    width:100%;
    min-width:980px;
    border-collapse:collapse;
}

.payroll-table th{
    background:#f7f9fb;
    border-bottom:1px solid var(--border);
    color:#657382;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.55px;
    font-weight:800;
    text-align:left;
    padding:12px 13px;
    white-space:nowrap;
}

.payroll-table th.money,
.payroll-table td.money{
    text-align:right;
}

.payroll-table td{
    border-bottom:1px solid #edf0f3;
    padding:12px 13px;
    vertical-align:middle;
    color:#53616d;
    font-size:11px;
}

.payroll-table tbody tr:hover{
    background:#fbfcfd;
}

.payroll-table tbody tr:last-child td{
    border-bottom:0;
}

.employee{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:220px;
}

.employee-avatar{
    width:34px;
    height:34px;
    flex:0 0 34px;
    display:grid;
    place-items:center;
    border-radius:9px;
    background:var(--blue-soft);
    color:var(--blue);
    font-size:11px;
    font-weight:800;
}

.employee-name{
    min-width:0;
}

.employee-name b{
    display:block;
    color:var(--charcoal);
    font-size:11px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.employee-name span{
    display:block;
    margin-top:3px;
    color:#87939f;
    font-size:9px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.role{
    color:#667582;
    white-space:nowrap;
}

.branch-name{
    white-space:nowrap;
}

.money-basic{
    color:var(--charcoal);
    font-weight:800;
}

.money-gross{
    color:var(--blue);
    font-weight:800;
}

.money-net{
    color:var(--green);
    font-weight:800;
}

.status{
    display:inline-flex;
    align-items:center;
    gap:5px;
    border-radius:20px;
    padding:5px 8px;
    background:var(--green-soft);
    color:var(--green);
    font-size:9px;
    font-weight:800;
}

.status i{
    font-size:6px;
}

.action-btn{
    width:34px;
    height:34px;
    border:1px solid var(--border);
    background:#fff;
    border-radius:7px;
    color:#71808d;
    display:grid;
    place-items:center;
}

.action-btn:hover{
    color:var(--blue);
    border-color:#9eb9e8;
    background:var(--blue-soft);
}

.table-total td{
    background:#f7f9fb;
    border-top:1px solid var(--border);
    font-weight:800;
    color:var(--charcoal);
}

.table-total td:first-child{
    border-radius:0;
}

.empty-state{
    padding:55px 20px;
    text-align:center;
    color:#7c8996;
}

.empty-state i{
    display:block;
    font-size:30px;
    margin-bottom:12px;
    color:#a8b3bd;
}

.empty-state b{
    display:block;
    color:#56636f;
    font-size:13px;
}

.empty-state span{
    display:block;
    margin-top:5px;
    font-size:10px;
}

/* ------------------------------------------------------------
| Footer note
------------------------------------------------------------ */

.payroll-footer{
    margin-top:12px;
    display:flex;
    justify-content:space-between;
    gap:15px;
    color:#87929d;
    font-size:9px;
}

/* ------------------------------------------------------------
| Print
------------------------------------------------------------ */

@media print{
    body{
        background:#fff;
    }

    .admin-aside,
    .admin-header,
    .payroll-tabs,
    .filter-panel,
    .page-actions,
    .action-column,
    .payroll-footer{
        display:none!important;
    }

    .main{
        margin-left:0;
    }

    .payroll-content{
        padding:0;
        max-width:none;
    }

    .summary-grid{
        grid-template-columns:repeat(4,1fr);
    }

    .summary-card,
    .payroll-panel{
        box-shadow:none;
    }

    .payroll-table{
        min-width:0;
    }

    .payroll-table th,
    .payroll-table td{
        font-size:9px;
        padding:7px;
    }
}

/* ------------------------------------------------------------
| Responsive
------------------------------------------------------------ */

@media(max-width:1200px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .filter-row{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:900px){
    .main{
        margin-left:0;
    }

    .payroll-content{
        padding:20px 16px 32px;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }

    .page-actions{
        width:100%;
    }
}

@media(max-width:560px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .filter-row{
        grid-template-columns:1fr;
    }

    .page-heading h1{
        font-size:23px;
    }

    .payroll-footer{
        flex-direction:column;
    }
}
</style>
</head>

<body>

<?php
/*
 * Reusable Admin header/aside.
 *
 * IMPORTANT:
 * The aside and header are independent files under /admin/actions/.
 */
?>

<div class="app">

    <?php require_once __DIR__ . '/actions/admin_aside.php'; ?>

    <main class="main">

        <?php require_once __DIR__ . '/actions/admin_header.php'; ?>

        <section class="payroll-content">

            <!-- Page heading -->
            <div class="page-heading">
                <div>
                    <h1>Payroll</h1>
                    <p>
                        Manage monthly staff payroll, salary totals and payroll records
                        for <?php echo payroll_esc($pharmacy_name); ?>.
                    </p>
                </div>

                <div class="page-actions">
                    <button
                        type="button"
                        class="btn"
                        onclick="window.print()"
                    >
                        <i class="fas fa-print"></i>
                        Print Payroll
                    </button>

                    <button
                        type="button"
                        class="btn primary"
                        onclick="window.location.href='payroll.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>'"
                    >
                        <i class="fas fa-arrows-rotate"></i>
                        Refresh
                    </button>
                </div>
            </div>

            <!-- Payroll tabs -->
            <nav class="payroll-tabs" aria-label="Payroll navigation">
                <a class="payroll-tab active" href="payroll.php">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Payroll
                </a>

                <a class="payroll-tab" href="staff_management.php">
                    <i class="fas fa-users"></i>
                    Staff
                </a>

                <a class="payroll-tab" href="payroll.php">
                    <i class="fas fa-calendar-days"></i>
                    Monthly Payroll
                </a>

                <a class="payroll-tab" href="payroll.php">
                    <i class="fas fa-receipt"></i>
                    Payroll Register
                </a>
            </nav>

            <!-- Summary -->
            <div class="summary-grid">

                <div class="summary-card">
                    <div class="summary-top">
                        <span>Staff on Payroll</span>
                        <span class="summary-icon">
                            <i class="fas fa-users"></i>
                        </span>
                    </div>

                    <div class="summary-value">
                        <?php echo number_format($staff_count); ?>
                    </div>

                    <div class="summary-sub">
                        Active staff records
                    </div>
                </div>

                <div class="summary-card green">
                    <div class="summary-top">
                        <span>Total Basic Salary</span>
                        <span class="summary-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </span>
                    </div>

                    <div class="summary-value">
                        <?php echo payroll_money($total_basic); ?>
                    </div>

                    <div class="summary-sub">
                        Monthly basic salary
                    </div>
                </div>

                <div class="summary-card yellow">
                    <div class="summary-top">
                        <span>Gross Payroll</span>
                        <span class="summary-icon">
                            <i class="fas fa-calculator"></i>
                        </span>
                    </div>

                    <div class="summary-value">
                        <?php echo payroll_money($total_basic + $total_allowances); ?>
                    </div>

                    <div class="summary-sub">
                        Basic + allowances
                    </div>
                </div>

                <div class="summary-card red">
                    <div class="summary-top">
                        <span>Net Payroll</span>
                        <span class="summary-icon">
                            <i class="fas fa-wallet"></i>
                        </span>
                    </div>

                    <div class="summary-value">
                        <?php echo payroll_money($total_net); ?>
                    </div>

                    <div class="summary-sub">
                        After deductions
                    </div>
                </div>

            </div>

            <!-- Filters -->
            <form
                class="filter-panel"
                method="get"
                action="payroll.php"
            >

                <div class="filter-row">

                    <div class="search-wrap">
                        <i class="fas fa-magnifying-glass"></i>

                        <input
                            class="field"
                            type="search"
                            name="search"
                            value="<?php echo payroll_esc($search); ?>"
                            placeholder="Search staff name, employee number or email..."
                        >
                    </div>

                    <select
                        class="field"
                        name="month"
                        aria-label="Payroll month"
                    >
                        <?php foreach ($months as $month_number => $month_name): ?>
                            <option
                                value="<?php echo $month_number; ?>"
                                <?php echo $month_number === $selected_month ? 'selected' : ''; ?>
                            >
                                <?php echo payroll_esc($month_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select
                        class="field"
                        name="year"
                        aria-label="Payroll year"
                    >
                        <?php foreach ($year_options as $year): ?>
                            <option
                                value="<?php echo $year; ?>"
                                <?php echo $year === $selected_year ? 'selected' : ''; ?>
                            >
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select
                        class="field"
                        name="branch_id"
                        aria-label="Branch"
                    >
                        <option value="0">All Branches</option>

                        <?php foreach ($branches as $branch): ?>
                            <option
                                value="<?php echo (int)$branch['id']; ?>"
                                <?php echo $branch_filter === (int)$branch['id'] ? 'selected' : ''; ?>
                            >
                                <?php echo payroll_esc($branch['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div class="filter-note">
                    <span>
                        Payroll period:
                        <strong>
                            <?php echo payroll_esc($period_label); ?>
                        </strong>
                    </span>

                    <span>
                        <?php echo number_format($staff_count); ?> staff loaded
                    </span>
                </div>

            </form>

            <!-- Payroll register -->
            <section class="payroll-panel">

                <div class="panel-head">
                    <div class="panel-title">
                        <b>Payroll Register</b>
                        <span>
                            Staff salary register for <?php echo payroll_esc($period_label); ?>
                        </span>
                    </div>

                    <div class="period-pill">
                        <i class="fas fa-calendar"></i>
                        <?php echo payroll_esc($period_label); ?>
                    </div>
                </div>

                <?php if (!$staff_table): ?>

                    <div class="empty-state">
                        <i class="fas fa-database"></i>
                        <b>Staff salary source not found</b>
                        <span>
                            No staff table with a basic salary field could be detected.
                            The payroll page is ready, but the staff salary source must exist.
                        </span>
                    </div>

                <?php elseif (!$payroll): ?>

                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <b>No staff found</b>
                        <span>
                            There are no matching active staff records for the selected filters.
                        </span>
                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table class="payroll-table">

                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th class="money">Basic Salary</th>
                                    <th class="money">Allowances</th>
                                    <th class="money">Gross Pay</th>
                                    <th class="money">Deductions</th>
                                    <th class="money">Net Pay</th>
                                    <th class="action-column">Action</th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($payroll as $employee): ?>

                                <tr>

                                    <td>
                                        <div class="employee">

                                            <div class="employee-avatar">
                                                <?php
                                                $initial = strtoupper(
                                                    substr(
                                                        trim($employee['staff_name']) ?: 'S',
                                                        0,
                                                        1
                                                    )
                                                );

                                                echo payroll_esc($initial);
                                                ?>
                                            </div>

                                            <div class="employee-name">

                                                <b>
                                                    <?php
                                                    echo payroll_esc(
                                                        $employee['staff_name']
                                                    );
                                                    ?>
                                                </b>

                                                <span>
                                                    <?php
                                                    if ($employee['employee_number'] !== '') {
                                                        echo payroll_esc(
                                                            $employee['employee_number']
                                                        );
                                                    } else {
                                                        echo 'Staff ID #' .
                                                            (int)$employee['staff_id'];
                                                    }
                                                    ?>
                                                </span>

                                            </div>

                                        </div>
                                    </td>

                                    <td class="role">
                                        <?php echo payroll_esc($employee['role']); ?>
                                    </td>

                                    <td class="branch-name">
                                        <?php echo payroll_esc($employee['branch_name']); ?>
                                    </td>

                                    <td>
                                        <span class="status">
                                            <i class="fas fa-circle"></i>
                                            Active
                                        </span>
                                    </td>

                                    <td class="money money-basic">
                                        <?php echo payroll_money($employee['basic']); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($employee['allowances']); ?>
                                    </td>

                                    <td class="money money-gross">
                                        <?php echo payroll_money($employee['gross']); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($employee['deductions']); ?>
                                    </td>

                                    <td class="money money-net">
                                        <?php echo payroll_money($employee['net']); ?>
                                    </td>

                                    <td class="action-column">

                                        <button
                                            type="button"
                                            class="action-btn"
                                            title="Payroll details"
                                            data-name="<?php echo payroll_esc($employee['staff_name']); ?>"
                                            data-basic="<?php echo payroll_esc(payroll_money($employee['basic'])); ?>"
                                            data-gross="<?php echo payroll_esc(payroll_money($employee['gross'])); ?>"
                                            data-net="<?php echo payroll_esc(payroll_money($employee['net'])); ?>"
                                            onclick="showPayrollDetails(this)"
                                        >
                                            <i class="fas fa-arrow-right"></i>
                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                            <tfoot>

                                <tr class="table-total">

                                    <td colspan="4">
                                        TOTAL PAYROLL
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($total_basic); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($total_allowances); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($total_basic + $total_allowances); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($total_deductions); ?>
                                    </td>

                                    <td class="money">
                                        <?php echo payroll_money($total_net); ?>
                                    </td>

                                    <td></td>

                                </tr>

                            </tfoot>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <div class="payroll-footer">
                <span>
                    Payroll period:
                    <?php echo payroll_esc($period_start); ?>
                    to
                    <?php echo payroll_esc($period_end); ?>
                </span>

                <span>
                    Phase 1 · Salary register
                </span>
            </div>

        </section>

    </main>

</div>

<!-- Payroll details modal -->
<div
    id="payrollModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.48);
        z-index:5000;
        align-items:center;
        justify-content:center;
        padding:20px;
    "
>
    <div
        style="
            width:min(440px,100%);
            background:#fff;
            border-radius:14px;
            border:1px solid #dfe4e9;
            box-shadow:0 20px 60px rgba(0,0,0,.18);
            overflow:hidden;
        "
    >

        <div
            style="
                padding:17px 18px;
                border-bottom:1px solid #e5e9ed;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:10px;
            "
        >
            <div>
                <strong
                    id="modalEmployeeName"
                    style="display:block;font-size:14px;color:#202831"
                ></strong>

                <span
                    style="display:block;margin-top:4px;font-size:10px;color:#788593"
                >
                    Payroll details
                </span>
            </div>

            <button
                type="button"
                onclick="closePayrollDetails()"
                style="
                    width:32px;
                    height:32px;
                    border:1px solid #dfe4e9;
                    border-radius:7px;
                    background:#fff;
                    color:#6f7d8a;
                    cursor:pointer;
                "
            >
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div style="padding:18px">

            <div
                style="
                    display:grid;
                    grid-template-columns:1fr 1fr;
                    gap:9px;
                "
            >

                <div style="padding:13px;background:#f7f9fb;border-radius:9px">
                    <span style="display:block;font-size:9px;color:#7b8793">Basic Salary</span>
                    <strong id="modalBasic" style="display:block;margin-top:5px;font-size:15px;color:#202831"></strong>
                </div>

                <div style="padding:13px;background:#f7f9fb;border-radius:9px">
                    <span style="display:block;font-size:9px;color:#7b8793">Gross Pay</span>
                    <strong id="modalGross" style="display:block;margin-top:5px;font-size:15px;color:#246bfe"></strong>
                </div>

                <div style="padding:13px;background:#e8f7f0;border-radius:9px;grid-column:1/-1">
                    <span style="display:block;font-size:9px;color:#5f796e">Net Pay</span>
                    <strong id="modalNet" style="display:block;margin-top:5px;font-size:19px;color:#159a68"></strong>
                </div>

            </div>

            <p
                style="
                    margin:15px 0 0;
                    font-size:10px;
                    line-height:1.6;
                    color:#7a8793;
                "
            >
                Phase 1 uses the existing staff basic salary as the payroll
                starting value. Allowances, statutory deductions, loans,
                advances, attendance and payslip processing will be added
                as the next payroll modules are implemented.
            </p>

        </div>

    </div>
</div>

<script>
function showPayrollDetails(button) {
    const modal = document.getElementById('payrollModal');

    document.getElementById('modalEmployeeName').textContent =
        button.dataset.name || 'Staff';

    document.getElementById('modalBasic').textContent =
        button.dataset.basic || 'K0.00';

    document.getElementById('modalGross').textContent =
        button.dataset.gross || 'K0.00';

    document.getElementById('modalNet').textContent =
        button.dataset.net || 'K0.00';

    modal.style.display = 'flex';
}

function closePayrollDetails() {
    const modal = document.getElementById('payrollModal');

    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('payrollModal')?.addEventListener('click', function (event) {
    if (event.target === this) {
        closePayrollDetails();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closePayrollDetails();
    }
});
</script>

</body>
</html>
