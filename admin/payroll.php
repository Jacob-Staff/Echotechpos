<?php
/**
 * ============================================================
 * EchoTech POS - ADMIN PAYROLL ENTRY
 * ============================================================
 *
 * Browser URL:
 *     /admin/payroll.php
 *
 * The complete payroll controller lives at:
 *     /admin/actions/payroll.php
 *
 * IMPORTANT:
 * This file contains NO old Phase-1 payroll implementation.
 * It only loads the single complete Payroll module.
 * All payroll POST forms submit directly to the controller.
 * ============================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/actions/payroll.php';
