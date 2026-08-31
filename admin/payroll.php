<?php
/**
 * ============================================================
 * EchoTech POS - ADMIN PAYROLL ENTRY
 * ============================================================
 *
 * Browser URL:
 *     /admin/payroll.php
 *
 * Complete Payroll controller:
 *     /admin/actions/payroll.php
 *
 * Admin and Human Resource share the controller, but the
 * controller renders the correct interface and permissions
 * according to the authenticated role.
 *
 * Admin retains final payroll approval.
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
