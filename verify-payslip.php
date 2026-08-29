<?php
/**
 * EchoTech POS - Official Payslip Verification
 *
 * Public endpoint. It uses the SAME database connection locations as
 * /admin/actions/payroll.php and does not require an admin session.
 */
declare(strict_types=1);

function verify_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function verify_money(mixed $value): string {
    return 'K' . number_format((float)$value, 2);
}

function verify_log(string $message): void {
    error_log('[PAYSLIP VERIFY] ' . $message);
}

/** Load the same connection file that the working Payroll controller uses. */
function verify_load_connection(): ?mysqli {
    $candidates = [
        // Public file at /var/www/html/verify-payslip.php
        __DIR__ . '/includes/conn.php',
        __DIR__ . '/config.php',
        __DIR__ . '/db.php',

        // Same paths used by /admin/actions/payroll.php.
        __DIR__ . '/admin/actions/../../includes/conn.php',
        __DIR__ . '/admin/actions/../../config.php',
        __DIR__ . '/admin/actions/../../db.php',
    ];

    foreach (array_unique($candidates) as $file) {
        if (!is_file($file)) {
            continue;
        }

        try {
            require_once $file;
        } catch (Throwable $e) {
            verify_log('Connection include failed: ' . $file . ' :: ' . $e->getMessage());
            continue;
        }

        foreach (['conn', 'db', 'mysqli'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli) {
                return $GLOBALS[$name];
            }
        }
    }

    return null;
}

/** Fetch one associative row without depending on mysqlnd/get_result(). */
function verify_stmt_row(mysqli_stmt $stmt): ?array {
    $meta = $stmt->result_metadata();
    if (!$meta) {
        return null;
    }

    $fields = $meta->fetch_fields();
    $values = [];
    $refs = [];

    foreach ($fields as $field) {
        $values[$field->name] = null;
        $refs[] = &$values[$field->name];
    }

    if (!call_user_func_array([$stmt, 'bind_result'], $refs)) {
        $meta->free();
        return null;
    }

    $row = null;
    if ($stmt->fetch()) {
        $row = [];
        foreach ($values as $key => $value) {
            $row[$key] = $value;
        }
    }

    $meta->free();
    return $row;
}

function verify_days(string $period): int {
    $parts = explode('-', $period);
    $year = isset($parts[0]) ? (int)$parts[0] : (int)date('Y');
    $month = isset($parts[1]) ? (int)$parts[1] : (int)date('n');
    if ($year < 2000 || $year > 2100) $year = (int)date('Y');
    if ($month < 1 || $month > 12) $month = 1;
    return (int)date('t', mktime(0, 0, 0, $month, 1, $year));
}

/** Must exactly match the fingerprint created by Payroll. */
function verify_hash(array $row, string $token): string {
    $parts = [
        (string)($row['pharmacy_id'] ?? ''),
        (string)($row['staff_id'] ?? ''),
        (string)($row['payroll_period'] ?? ''),
        (string)($row['basic_salary'] ?? 0),
        (string)($row['allowances'] ?? 0),
        (string)($row['bonus'] ?? 0),
        (string)($row['overtime'] ?? 0),
        (string)($row['other_earnings'] ?? 0),
        (string)($row['paye'] ?? 0),
        (string)($row['napsa'] ?? 0),
        (string)($row['nhima'] ?? 0),
        (string)($row['loan_deduction'] ?? 0),
        (string)($row['salary_advance'] ?? 0),
        (string)($row['other_deductions'] ?? 0),
        (string)($row['gross_salary'] ?? 0),
        (string)($row['total_deductions'] ?? 0),
        (string)($row['net_salary'] ?? 0),
        (string)($row['employer_napsa'] ?? 0),
        (string)($row['employer_nhima'] ?? 0),
        $token,
    ];

    return hash('sha256', implode('|', $parts));
}

function verify_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = @$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function verify_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = @$conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function verify_company_name(mysqli $conn, int $pharmacyId): string {
    $default = 'PHARMANOVA';
    if ($pharmacyId <= 0 || !verify_table_exists($conn, 'pharmacies')) {
        return $default;
    }

    $columns = [];
    $result = @$conn->query('SHOW COLUMNS FROM `pharmacies`');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string)$row['Field'];
        }
    }

    $nameColumn = null;
    foreach (['name', 'pharmacy_name', 'business_name'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $nameColumn = $candidate;
            break;
        }
    }

    if ($nameColumn === null) {
        return $default;
    }

    $stmt = $conn->prepare("SELECT `{$nameColumn}` FROM `pharmacies` WHERE id=? LIMIT 1");
    if (!$stmt) {
        verify_log('Pharmacy name query prepare failed: ' . $conn->error);
        return $default;
    }

    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $row = verify_stmt_row($stmt);
    $stmt->close();

    return !empty($row[$nameColumn]) ? (string)$row[$nameColumn] : $default;
}

function verify_staff(mysqli $conn, int $staffId, int $pharmacyId, int $recordBranchId): array {
    $data = [
        'name' => 'Staff #' . $staffId,
        'role' => 'Staff',
        'employee_number' => (string)$staffId,
        'branch' => 'Main Branch',
    ];

    if ($staffId <= 0 || !verify_table_exists($conn, 'users')) {
        return $data;
    }

    /* Use only columns known to exist in the POS staff table. */
    $userColumns = [];
    $cols = @$conn->query('SHOW COLUMNS FROM `users`');
    if ($cols instanceof mysqli_result) {
        while ($c = $cols->fetch_assoc()) {
            $userColumns[] = (string)$c['Field'];
        }
    }

    $nameExpression = 'CONCAT(\'Staff #\',id)';
    if (in_array('full_name', $userColumns, true)) {
        $nameExpression = "COALESCE(NULLIF(TRIM(full_name),''), CONCAT('Staff #',id))";
    } elseif (in_array('name', $userColumns, true)) {
        $nameExpression = "COALESCE(NULLIF(TRIM(name),''), CONCAT('Staff #',id))";
    } elseif (in_array('username', $userColumns, true)) {
        $nameExpression = "COALESCE(NULLIF(TRIM(username),''), CONCAT('Staff #',id))";
    }

    $roleExpression = in_array('role', $userColumns, true) ? "COALESCE(role,'Staff')" : "'Staff'";
    $pharmacyWhere = in_array('pharmacy_id', $userColumns, true) ? ' AND pharmacy_id=?' : '';

    $sql = "SELECT {$nameExpression} AS staff_name, {$roleExpression} AS staff_role, id";
    if (in_array('branch_id', $userColumns, true)) {
        $sql .= ', branch_id';
    }
    $sql .= " FROM users WHERE id=?{$pharmacyWhere} LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        verify_log('Staff query prepare failed: ' . $conn->error);
        return $data;
    }

    if ($pharmacyWhere !== '') {
        $stmt->bind_param('ii', $staffId, $pharmacyId);
    } else {
        $stmt->bind_param('i', $staffId);
    }

    $stmt->execute();
    $staff = verify_stmt_row($stmt);
    $stmt->close();

    if (!$staff) {
        return $data;
    }

    $data['name'] = (string)($staff['staff_name'] ?? $data['name']);
    $data['role'] = (string)($staff['staff_role'] ?? $data['role']);
    $data['employee_number'] = (string)($staff['id'] ?? $staffId);

    $branchId = $recordBranchId;
    if ($branchId <= 0 && isset($staff['branch_id'])) {
        $branchId = (int)$staff['branch_id'];
    }

    if ($branchId > 0 && verify_table_exists($conn, 'branches')) {
        $branchColumns = [];
        $bc = @$conn->query('SHOW COLUMNS FROM `branches`');
        if ($bc instanceof mysqli_result) {
            while ($c = $bc->fetch_assoc()) {
                $branchColumns[] = (string)$c['Field'];
            }
        }

        $branchNameColumn = null;
        foreach (['branch_name', 'name', 'branch'] as $candidate) {
            if (in_array($candidate, $branchColumns, true)) {
                $branchNameColumn = $candidate;
                break;
            }
        }

        if ($branchNameColumn !== null) {
            $stmt = $conn->prepare("SELECT `{$branchNameColumn}` FROM `branches` WHERE id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $branchId);
                $stmt->execute();
                $branch = verify_stmt_row($stmt);
                $stmt->close();
                if ($branch && !empty($branch[$branchNameColumn])) {
                    $data['branch'] = (string)$branch[$branchNameColumn];
                }
            }
        }
    }

    return $data;
}

$conn = verify_load_connection();
$token = strtoupper(trim((string)($_GET['token'] ?? '')));
$record = null;
$companyName = 'PHARMANOVA';
$error = '';
$valid = false;
$technicalError = false;

if (!$conn) {
    http_response_code(500);
    $error = 'The verification service could not connect to the payroll database.';
    $technicalError = true;
    verify_log('No mysqli connection was available.');
} elseif ($token === '' || !preg_match('/^[A-F0-9]{48}$/', $token)) {
    http_response_code(404);
    $error = 'This payslip verification code is invalid.';
} elseif (!verify_table_exists($conn, 'payroll_records')) {
    http_response_code(500);
    $error = 'The official payroll verification record is not available.';
    $technicalError = true;
    verify_log('payroll_records table was not found.');
} elseif (!verify_column_exists($conn, 'payroll_records', 'verification_token')) {
    http_response_code(500);
    $error = 'This Payroll installation has not completed payslip verification setup.';
    $technicalError = true;
    verify_log('verification_token column was not found in payroll_records.');
} else {
    $stmt = $conn->prepare(
        'SELECT * FROM `payroll_records` WHERE `verification_token`=? LIMIT 1'
    );

    if (!$stmt) {
        http_response_code(500);
        $error = 'The verification service could not read the official payroll record.';
        $technicalError = true;
        verify_log('Payroll record query prepare failed: ' . $conn->error);
    } else {
        $stmt->bind_param('s', $token);
        if (!$stmt->execute()) {
            http_response_code(500);
            $error = 'The verification service could not read the official payroll record.';
            $technicalError = true;
            verify_log('Payroll record query execution failed: ' . $stmt->error);
        } else {
            $record = verify_stmt_row($stmt);
            if (!$record) {
                http_response_code(404);
                $error = 'This payslip could not be found in the official PHARMANOVA payroll records.';
            }
        }
        $stmt->close();
    }
}

if (is_array($record)) {
    $pharmacyId = (int)($record['pharmacy_id'] ?? 0);
    $companyName = verify_company_name($conn, $pharmacyId);

    $status = strtolower(trim((string)($record['status'] ?? 'draft')));
    $storedHash = strtolower(trim((string)($record['document_hash'] ?? '')));
    $calculatedHash = verify_hash($record, $token);
    $hashOk = $storedHash !== '' && hash_equals($storedHash, $calculatedHash);
    $notRevoked = empty($record['revoked_at']);
    $finalStatus = in_array($status, ['approved', 'paid', 'locked'], true);
    $valid = $finalStatus && $notRevoked && $hashOk;

    if ($valid) {
        $id = (int)($record['id'] ?? 0);
        if ($id > 0 && verify_column_exists($conn, 'payroll_records', 'verified_at')) {
            $stmt = $conn->prepare('UPDATE `payroll_records` SET `verified_at`=NOW() WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $staff = verify_staff(
        $conn,
        (int)($record['staff_id'] ?? 0),
        $pharmacyId,
        (int)($record['branch_id'] ?? 0)
    );

    $period = (string)($record['payroll_period'] ?? date('Y-m'));
    $periodTimestamp = strtotime($period . '-01');
    $periodLabel = $periodTimestamp !== false ? date('F Y', $periodTimestamp) : verify_h($period);
    $payslipId = 'PS-' . substr($token, 0, 8) . '-' . substr($token, 8, 8);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payslip Verification | <?= verify_h($companyName) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6f9;color:#17212b;font-family:Arial,Helvetica,sans-serif}
.page{width:min(820px,calc(100% - 28px));margin:40px auto}
.card{background:#fff;border:1px solid #dce3ea;border-radius:14px;box-shadow:0 12px 35px rgba(15,23,42,.08);overflow:hidden}
.head{padding:24px 26px;border-bottom:1px solid #e4e9ee;display:flex;justify-content:space-between;gap:20px;align-items:center}
.brand{font-size:20px;font-weight:800;letter-spacing:.2px}.sub{margin-top:4px;color:#6f7d8b;font-size:13px}
.badge{padding:9px 13px;border-radius:999px;font-weight:800;font-size:12px}.valid{background:#e8f7f0;color:#08744b}.invalid{background:#fff0f2;color:#b4233c}
.body{padding:26px}.verify-title{font-size:22px;font-weight:800;margin:0 0 5px}.verify-sub{color:#71808f;font-size:13px;margin-bottom:22px}
.grid{display:grid;grid-template-columns:1fr 1fr;border:1px solid #dfe5eb}.item{padding:12px 14px;border-bottom:1px solid #e8edf1}.item:nth-child(odd){border-right:1px solid #e8edf1}.item:nth-last-child(-n+2){border-bottom:0}.label{font-size:10px;text-transform:uppercase;color:#778594;font-weight:800}.value{margin-top:4px;font-size:14px;font-weight:700}
.summary{margin-top:20px;border:1px solid #dfe5eb}.summary-row{display:flex;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #e8edf1;font-size:13px}.summary-row:last-child{border-bottom:0}.summary-row strong{font-size:14px}.net{font-size:17px!important}
.notice{margin-top:18px;padding:14px;border-radius:10px;background:#f6f8fa;color:#5f6d7a;font-size:12px;line-height:1.5}.danger{background:#fff3f4;color:#9f2339}
.footer{padding:16px 26px;border-top:1px solid #e4e9ee;color:#758391;font-size:11px;text-align:center}
.error{padding:50px 28px;text-align:center}.error h1{margin:12px 0 8px;font-size:24px}.error p{color:#6f7d8b;line-height:1.6}.error .technical{font-size:11px;color:#9b5b00;margin-top:18px}
@media(max-width:620px){.head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.item:nth-child(odd){border-right:0}.item:nth-last-child(-n+2){border-bottom:1px solid #e8edf1}.item:last-child{border-bottom:0}.page{margin:18px auto}.body{padding:18px}}
</style>
</head>
<body>
<div class="page">
<div class="card">
<?php if ($error !== ''): ?>
    <div class="error">
        <div class="brand"><?= verify_h($companyName) ?></div>
        <h1>Payslip Verification <?= $technicalError ? 'Unavailable' : 'Failed' ?></h1>
        <p><?= verify_h($error) ?></p>
        <?php if (!$technicalError): ?>
            <div class="badge invalid">âœ• NOT VERIFIED</div>
        <?php endif; ?>
        <?php if ($technicalError): ?>
            <div class="technical">Please contact the payroll administrator if this problem continues.</div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="head">
        <div><div class="brand"><?= verify_h($companyName) ?></div><div class="sub">Official Payroll Verification</div></div>
        <div class="badge <?= $valid ? 'valid' : 'invalid' ?>"><?= $valid ? 'âœ“ VERIFIED' : 'âœ• NOT VERIFIED' ?></div>
    </div>
    <div class="body">
        <h1 class="verify-title">Payslip Verification</h1>
        <div class="verify-sub">This information is retrieved directly from the official payroll record.</div>

        <?php if (!$valid): ?>
        <div class="notice danger">
            <strong>This payslip is not currently valid.</strong><br>
            <?php if (!$finalStatus): ?>The payroll record has not reached an approved, paid or locked status.
            <?php elseif (!$notRevoked): ?>This payslip has been revoked by the company.
            <?php elseif (!$hashOk): ?>The payroll integrity fingerprint does not match the issued record. The document should not be accepted as authentic.
            <?php else: ?>The payslip could not be verified.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="grid" style="margin-top:18px">
            <div class="item"><div class="label">Employee</div><div class="value"><?= verify_h($staff['name']) ?></div></div>
            <div class="item"><div class="label">Employee No.</div><div class="value"><?= verify_h($staff['employee_number']) ?></div></div>
            <div class="item"><div class="label">Designation</div><div class="value"><?= verify_h($staff['role']) ?></div></div>
            <div class="item"><div class="label">Branch / Location</div><div class="value"><?= verify_h($staff['branch']) ?></div></div>
            <div class="item"><div class="label">Payroll Period</div><div class="value"><?= verify_h($periodLabel) ?></div></div>
            <div class="item"><div class="label">Payslip ID</div><div class="value"><?= verify_h($payslipId) ?></div></div>
        </div>

        <div class="summary">
            <div class="summary-row"><span>Basic Salary</span><strong><?= verify_money($record['basic_salary'] ?? 0) ?></strong></div>
            <div class="summary-row"><span>Gross Salary</span><strong><?= verify_money($record['gross_salary'] ?? 0) ?></strong></div>
            <div class="summary-row"><span>Total Deductions</span><strong><?= verify_money($record['total_deductions'] ?? 0) ?></strong></div>
            <div class="summary-row"><span class="net">Net Salary</span><strong class="net"><?= verify_money($record['net_salary'] ?? 0) ?></strong></div>
        </div>

        <div class="notice">
            <strong>Official verification record.</strong><br>
            These figures were retrieved directly from <?= verify_h($companyName) ?>'s payroll database. Verification code: <strong><?= verify_h($token) ?></strong>.
        </div>
    </div>
    <div class="footer">Generated electronically by <?= verify_h($companyName) ?> Payroll. This verification page is the authoritative source for this payslip.</div>
<?php endif; ?>
</div>
</div>
</body>
</html>
