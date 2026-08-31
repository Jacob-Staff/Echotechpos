<?php
/**
 * ============================================================
 * EchoTech POS - HUMAN RESOURCE PAYROLL ENTRY
 * ============================================================
 *
 * Browser URL:
 *     /admin/hr_payroll.php
 *
 * Complete Payroll controller:
 *     /admin/actions/payroll.php
 *
 * IMPORTANT:
 * - No duplicate payroll calculations here.
 * - No require_admin() here.
 * - The controller authenticates the user and permits
 *   Admin or Human Resource payroll access.
 * - HR prepares/calculates/reviews payroll.
 * - Admin retains final payroll approval.
 * - The controller renders the HR sidebar/header for HR.
 * ============================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/actions/payroll.php';
exit;
