<?php
/**
 * EchoTech POS - Public Payslip Verification
 *
 * This page intentionally does NOT require an administrator login.
 * It verifies a payslip using the random verification token stored against
 * the official payroll record, then recomputes the payroll fingerprint.
 */
declare(strict_types=1);

function verify_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function verify_money(float $value): string {
    return 'K' . number_format($value, 2);
}

function verify_days(string $period): int {
    $parts = explode('-', $period);
    $year = isset($parts[0]) ? (int)$parts[0] : (int)date('Y');
    $month = isset($parts[1]) ? (int)$parts[1] : (int)date('n');
    if ($month < 1 || $month > 12) $month = 1;
    return (int)date('t', mktime(0, 0, 0, $month, 1, $year));
}

function verify_hash(array $row): string {
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
        (string)($row['verification_token'] ?? ''),
    ];
    return hash('sha256', implode('|', $parts));
}

function db_connection(): ?mysqli {
    $files = [
        __DIR__ . '/includes/conn.php',
        __DIR__ . '/config.php',
        __DIR__ . '/db.php',
    ];

    foreach ($files as $file) {
        if (is_file($file)) {
            require_once $file;
            if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                return $GLOBALS['conn'];
            }
        }
    }
    return null;
}

$conn = db_connection();
$token = strtoupper(trim((string)($_GET['token'] ?? '')));
$record = null;
$companyName = 'PHARMANOVA';
$error = '';
$valid = false;

if (!$conn) {
    http_response_code(500);
    $error = 'Verification service is temporarily unavailable.';
} elseif ($token === '' || !preg_match('/^[A-F0-9]{48}$/', $token)) {
    http_response_code(404);
    $error = 'This payslip verification code is invalid.';
} else {
    $conn->set_charset('utf8mb4');

    $stmt = $conn->prepare(
        "SELECT * FROM payroll_records
         WHERE verification_token = ?
         LIMIT 1"
    );

    if (!$stmt) {
        http_response_code(500);
        $error = 'Verification service is temporarily unavailable.';
    } else {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$record) {
            http_response_code(404);
            $error = 'This payslip could not be found in the official payroll records.';
        }
    }
}

if (is_array($record)) {
    $pharmacyId = (int)($record['pharmacy_id'] ?? 0);

    if ($pharmacyId > 0 && $conn) {
        $tables = @$conn->query("SHOW TABLES LIKE 'pharmacies'");
        if ($tables instanceof mysqli_result && $tables->num_rows > 0) {
            $columns = [];
            $cols = @$conn->query("SHOW COLUMNS FROM pharmacies");
            if ($cols instanceof mysqli_result) {
                while ($c = $cols->fetch_assoc()) {
                    $columns[] = $c['Field'];
                }
            }

            $nameCol = null;
            foreach (['name', 'pharmacy_name', 'business_name'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $nameCol = $candidate;
                    break;
                }
            }

            if ($nameCol) {
                $stmt = $conn->prepare("SELECT `{$nameCol}` AS name FROM pharmacies WHERE id=? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $pharmacyId);
                    $stmt->execute();
                    $r = $stmt->get_result();
                    $name = $r ? $r->fetch_assoc() : null;
                    if (!empty($name['name'])) $companyName = (string)$name['name'];
                    $stmt->close();
                }
            }
        }
    }

    $status = strtolower((string)($record['status'] ?? 'draft'));
    $storedHash = strtolower(trim((string)($record['document_hash'] ?? '')));
    $calculatedHash = verify_hash($record);
    $hashOk = $storedHash !== '' && hash_equals($storedHash, $calculatedHash);
    $notRevoked = empty($record['revoked_at']);
    $finalStatus = in_array($status, ['approved', 'paid', 'locked'], true);
    $valid = $finalStatus && $notRevoked && $hashOk;

    if ($valid && $conn) {
        $id = (int)$record['id'];
        $stmt = $conn->prepare("UPDATE payroll_records SET verified_at=NOW() WHERE id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $staffName = 'Staff #' . (int)$record['staff_id'];
    $role = 'Staff';
    $branchName = 'Main Branch';
    $employeeNumber = (string)$record['staff_id'];

    if ($conn) {
        $stmt = $conn->prepare(
            "SELECT
                COALESCE(NULLIF(TRIM(full_name),''), NULLIF(TRIM(username),''), CONCAT('Staff #',id)) AS staff_name,
                COALESCE(role,'Staff') AS staff_role,
                id AS employee_number,
                branch_id
             FROM users WHERE id=? AND pharmacy_id=? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $record['staff_id'], $pharmacyId);
            $stmt->execute();
            $r = $stmt->get_result();
            $staff = $r ? $r->fetch_assoc() : null;
            if ($staff) {
                $staffName = (string)$staff['staff_name'];
                $role = (string)$staff['staff_role'];
                $employeeNumber = (string)$staff['employee_number'];
                $branchId = (int)($record['branch_id'] ?? $staff['branch_id'] ?? 0);
                if ($branchId > 0) {
                    $bt = @$conn->query("SHOW TABLES LIKE 'branches'");
                    if ($bt instanceof mysqli_result && $bt->num_rows > 0) {
                        $bc = [];
                        $br = @$conn->query("SHOW COLUMNS FROM branches");
                        if ($br instanceof mysqli_result) while ($c=$br->fetch_assoc()) $bc[]=$c['Field'];
                        $branchCol = null;
                        foreach (['branch_name','name','branch'] as $candidate) if (in_array($candidate,$bc,true)) {$branchCol=$candidate;break;}
                        if ($branchCol) {
                            $bs = $conn->prepare("SELECT `{$branchCol}` AS branch_name FROM branches WHERE id=? LIMIT 1");
                            if ($bs) {
                                $bs->bind_param('i',$branchId);
                                $bs->execute();
                                $brs=$bs->get_result();
                                $b=$brs?$brs->fetch_assoc():null;
                                if ($b && !empty($b['branch_name'])) $branchName=(string)$b['branch_name'];
                                $bs->close();
                            }
                        }
                    }
                }
            }
            $stmt->close();
        }
    }

    $period = (string)$record['payroll_period'];
    $periodLabel = date('F Y', strtotime($period . '-01'));
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
.error{padding:50px 28px;text-align:center}.error h1{margin:0 0 8px;font-size:24px}.error p{color:#6f7d8b}
@media(max-width:620px){.head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.item:nth-child(odd){border-right:0}.item:nth-last-child(-n+2){border-bottom:1px solid #e8edf1}.item:last-child{border-bottom:0}.page{margin:18px auto}.body{padding:18px}}
</style>
</head>
<body>
<div class="page">
<div class="card">
<?php if ($error !== ''): ?>
    <div class="error">
        <div class="brand"><?= verify_h($companyName) ?></div>
        <h1>Payslip verification unavailable</h1>
        <p><?= verify_h($error) ?></p>
    </div>
<?php else: ?>
    <div class="head">
        <div><div class="brand"><?= verify_h($companyName) ?></div><div class="sub">Official Payroll Verification</div></div>
        <div class="badge <?= $valid ? 'valid' : 'invalid' ?>"><?= $valid ? '✓ VERIFIED' : '✕ NOT VERIFIED' ?></div>
    </div>
    <div class="body">
        <h1 class="verify-title">Payslip Verification</h1>
        <div class="verify-sub">This information is retrieved from the official payroll record, not from the document supplied by the person presenting it.</div>

        <?php if (!$valid): ?>
        <div class="notice danger">
            <strong>This payslip is not currently valid.</strong><br>
            <?php if (!$finalStatus): ?>The payroll record has not reached an approved/paid/locked status.<?php elseif (!$notRevoked): ?>This payslip has been revoked by the company.<?php elseif (!$hashOk): ?>The payroll record's integrity fingerprint does not match the issued record. The document should not be accepted as authentic.<?php else: ?>The payslip could not be verified.<?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="grid" style="margin-top:18px">
            <div class="item"><div class="label">Employee</div><div class="value"><?= verify_h($staffName) ?></div></div>
            <div class="item"><div class="label">Employee No.</div><div class="value"><?= verify_h($employeeNumber) ?></div></div>
            <div class="item"><div class="label">Designation</div><div class="value"><?= verify_h($role) ?></div></div>
            <div class="item"><div class="label">Branch / Location</div><div class="value"><?= verify_h($branchName) ?></div></div>
            <div class="item"><div class="label">Payroll Period</div><div class="value"><?= verify_h($periodLabel) ?></div></div>
            <div class="item"><div class="label">Payslip ID</div><div class="value"><?= verify_h($payslipId) ?></div></div>
        </div>

        <div class="summary">
            <div class="summary-row"><span>Basic Salary</span><strong><?= verify_money((float)$record['basic_salary']) ?></strong></div>
            <div class="summary-row"><span>Gross Salary</span><strong><?= verify_money((float)$record['gross_salary']) ?></strong></div>
            <div class="summary-row"><span>Total Deductions</span><strong><?= verify_money((float)$record['total_deductions']) ?></strong></div>
            <div class="summary-row"><span class="net">Net Salary</span><strong class="net"><?= verify_money((float)$record['net_salary']) ?></strong></div>
        </div>

        <div class="notice">
            <strong>Official verification record.</strong><br>
            The payroll figures above were retrieved directly from <?= verify_h($companyName) ?>'s payroll database. Verification code: <strong><?= verify_h($token) ?></strong>.
        </div>
    </div>
    <div class="footer">Generated electronically by <?= verify_h($companyName) ?> Payroll. This verification page is the authoritative source for this payslip.</div>
<?php endif; ?>
</div>
</div>
</body>
</html>
