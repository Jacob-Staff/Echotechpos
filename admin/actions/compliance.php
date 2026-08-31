<?php
/**
 * EchoTech POS - Admin Compliance browser entry
 *
 * URL:
 *     /admin/compliance.php
 *
 * The complete Phase 1 Compliance module lives at:
 *     /admin/actions/compliance.php
 *
 * Compliance is an ADMIN module. HR has its own separate
 * HR pages and HR aside under the new application structure.
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

require_once __DIR__ . '/actions/compliance.php';
