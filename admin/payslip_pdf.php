<?php
/**
 * EchoTech POS - Official Payslip PDF endpoint
 *
 * This is intentionally a thin endpoint. The actual payslip renderer, CSS,
 * payroll data preparation and official PDF archive all live in:
 *     /admin/actions/payroll.php
 *
 * Admin Download and Email therefore use the exact same stored PDF artifact.
 */

declare(strict_types=1);

$staffId = (int)($_GET['staff_id'] ?? 0);
$month   = (int)($_GET['month'] ?? date('n'));
$year    = (int)($_GET['year'] ?? date('Y'));

if ($staffId <= 0) {
    http_response_code(400);
    exit('A valid employee is required.');
}

$month = max(1, min(12, $month));
$year  = max(2020, min(2100, $year));

/* payroll.php uses these exact query variables to load the selected record. */
$_GET['payslip'] = (string)$staffId;
$_GET['month'] = (string)$month;
$_GET['year'] = (string)$year;
$_GET['official_pdf'] = '1';

require __DIR__ . '/actions/payroll.php';
