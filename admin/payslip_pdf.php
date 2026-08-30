<?php
/**
 * EchoTech POS â€” Official Payslip PDF endpoint
 *
 * This endpoint deliberately contains NO payslip rendering code.
 * The complete payslip module lives in:
 *     /admin/actions/payslip.php
 *
 * Both Admin download and email use the official PDF produced by that module.
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

/*
 * Tell the dedicated payslip controller that this request is the official
 * PDF request. payslip.php will load the payroll record, render the
 * payslip, archive the exact PDF, and return those bytes.
 */
$_GET['official_pdf'] = '1';
$_GET['staff_id'] = (string)$staffId;
$_GET['month'] = (string)$month;
$_GET['year'] = (string)$year;

require __DIR__ . '/actions/payslip.php';
