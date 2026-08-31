<?php
/**
 * EchoTech POS â€” Human Resource Payroll Entry
 *
 * /admin/hr_payroll.php
 * Uses the same payroll controller as Admin.
 *
 * HR can prepare, calculate and review payroll.
 * Final payroll approval remains Admin-only.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/actions/payroll.php';
exit;
