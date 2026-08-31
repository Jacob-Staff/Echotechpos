<?php
/**
 * ============================================================
 * EchoTech POS
 * ADMIN COMPLIANCE / ZRA PHASE 1
 * Browser Entry Point
 * ============================================================
 *
 * URL:
 *     /admin/compliance.php
 *
 * The complete Compliance controller/view remains in:
 *
 *     /admin/actions/compliance.php
 *
 * IMPORTANT:
 *     This file intentionally contains no duplicate Compliance
 *     implementation. It only loads the existing controller.
 *
 * Phase 1:
 *     - ZRA compliance administration
 *     - Taxpayer profile
 *     - Tax configuration
 *     - Pharmacy/branch registration state
 *     - Smart Invoice preparation
 *     - Obligations
 *     - Tax payment tracking
 *     - Compliance audit information
 *
 * Live VSDC submission is NOT performed in Phase 1.
 * ============================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| Compliance controller
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/actions/compliance.php';
