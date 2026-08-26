<?php
/**
 * BIGE50 CLIENT LOGOUT
 *
 * Logout must destroy authentication/session state, but it must NEVER
 * delete the customer's persistent online cart.
 *
 * Before destroying the session we copy any remaining session-cart rows
 * into online_cart_items. This also protects carts created by an older
 * session-only cart endpoint.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

$conn_file = realpath(__DIR__ . '/../includes/conn.php');
if (!$conn_file) {
    $conn_file = realpath(__DIR__ . '/includes/conn.php');
}

if ($conn_file && is_file($conn_file)) {
    require_once $conn_file;
}

$branchId = (int)($_SESSION['current_branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$clientId = (int)($_SESSION['client_id'] ?? 0);

/* ---------------------------------------------------------
   Persist any session cart before destroying the session.
--------------------------------------------------------- */
if (
    $clientId > 0 &&
    isset($conn) &&
    $conn instanceof mysqli &&
    isset($_SESSION['carts']) &&
    is_array($_SESSION['carts'])
) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS online_cart_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT NOT NULL,
            pharmacy_id INT NOT NULL,
            branch_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_online_cart_client_branch_product (client_id, branch_id, product_id),
            KEY idx_online_cart_client (client_id),
            KEY idx_online_cart_branch (branch_id),
            KEY idx_online_cart_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $persist = $conn->prepare("INSERT INTO online_cart_items
            (client_id, pharmacy_id, branch_id, product_id, quantity)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quantity = VALUES(quantity),
                pharmacy_id = VALUES(pharmacy_id),
                updated_at = CURRENT_TIMESTAMP");

        if ($persist) {
            foreach ($_SESSION['carts'] as $sessionBranchId => $sessionCart) {
                $sessionBranchId = (int)$sessionBranchId;
                if ($sessionBranchId <= 0 || !is_array($sessionCart)) {
                    continue;
                }

                $pharmacyId = 0;
                $branchStmt = $conn->prepare("SELECT pharmacy_id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
                if ($branchStmt) {
                    $branchStmt->bind_param('i', $sessionBranchId);
                    $branchStmt->execute();
                    $branchRow = $branchStmt->get_result()->fetch_assoc();
                    $branchStmt->close();
                    $pharmacyId = (int)($branchRow['pharmacy_id'] ?? 0);
                }

                if ($pharmacyId <= 0) {
                    continue;
                }

                foreach ($sessionCart as $productId => $item) {
                    $productId = (int)$productId;
                    $qty = max(0, (int)($item['qty'] ?? 0));
                    if ($productId <= 0 || $qty <= 0) {
                        continue;
                    }

                    $persist->bind_param(
                        'iiiii',
                        $clientId,
                        $pharmacyId,
                        $sessionBranchId,
                        $productId,
                        $qty
                    );
                    $persist->execute();
                }
            }
            $persist->close();
        }
    } catch (Throwable $e) {
        error_log('BIGE50 CLIENT LOGOUT CART PERSISTENCE: ' . $e->getMessage());
        /* Logout must still complete even if the cart backup fails. */
    }
}

/* Preserve the branch for the login/store redirect. */
$redirectBranch = $branchId > 0 ? $branchId : 0;

/* ---------------------------------------------------------
   Destroy authentication/session state.
--------------------------------------------------------- */
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}

session_destroy();

/* Start a fresh anonymous session so branch context can survive logout. */
session_start();

if ($redirectBranch > 0) {
    $_SESSION['current_branch_id'] = $redirectBranch;
}

$query = $redirectBranch > 0
    ? '?bid=' . rawurlencode((string)$redirectBranch)
    : '';

header('Location: login_client.php' . $query);
exit;
