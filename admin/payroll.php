<?php
/**
 * EchoTech POS - Payroll Entry Point
 *
 * Browser URL:
 *   /admin/payroll.php
 *
 * The complete Payroll module lives in:
 *   /admin/actions/payroll.php
 *
 * Keep this file deliberately small. This prevents the old Phase 1
 * payroll page from being loaded accidentally.
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
