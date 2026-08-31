<?php
/**
 * EchoTech POS â€” Admin Payroll Entry
 *
 * /admin/payroll.php
 * The single payroll controller is /admin/actions/payroll.php.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/actions/payroll.php';
exit;
