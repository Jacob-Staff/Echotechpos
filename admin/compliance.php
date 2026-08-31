<?php
/**
 * EchoTech POS - Admin Compliance browser entry
 *
 * URL:
 *   /admin/compliance.php
 *
 * Phase 1 only: ZRA / Smart Invoice compliance administration.
 * The actual module is /admin/actions/compliance.php.
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

require_once __DIR__ . '/actions/compliance.php';
