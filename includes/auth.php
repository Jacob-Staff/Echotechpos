<?php
/**
 * EchoTech POS
 * Authentication + Authorization
 * Single source of truth for staff roles, POS pages, access and functions.
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

/* ------------------------- Master configuration ------------------------- */

function echotech_staff_roles(): array
{
    return [
        'Human Resource',
        'Pharmacist',
        'Manager',
        'Assistant Manager',
        'PharmTech',
        'Clinical Officer',
        'Registered Nurse',
        'Cashier',
        'General',
        'Security',
    ];
}

/*
 * These names deliberately match the POS page names agreed for Staff
 * Management. File names are mapped separately so access checks remain
 * readable and stable even if a PHP filename changes.
 */
function echotech_pages(): array
{
    return [
        'Sale now',
        'Today transaction',
        'Out of stock',
        'Expired products',
        'Customer',
        'Online manager',
        'Pharmacy stock',
        'Purchases orders',
        'Supplier',
        'Add Product',
        'Stock exchange',
        'Shift log',
        'Purchases order list',
        'Restock',
        'Online orders',
        'Sales report',
        'Lay by sale',
        'Expenses sales trend',
        'Add patient',
        'Settings',
    ];
}

function echotech_page_routes(): array
{
    return [
        'Sale now'              => 'sell_now.php',
        'Today transaction'     => 'today_transactions.php',
        'Out of stock'          => 'out_of_stock.php',
        'Expired products'      => 'expired_products.php',
        'Customer'              => 'customers.php',
        'Online manager'        => 'online_manager.php',
        'Pharmacy stock'        => 'pharmacy_stock.php',
        'Purchases orders'      => 'purchase_orders.php',
        'Supplier'             => 'suppliers.php',
        'Add Product'           => 'add_product.php',
        'Stock exchange'        => 'stock_transfer.php',
        'Shift log'             => 'shift_log.php',
        'Purchases order list'  => 'purchase_orders_list.php',
        'Restock'               => 'restock.php',
        'Online orders'         => 'online_orders.php',
        'Sales report'          => 'sales_report.php',
        'Lay by sale'           => 'lay_by_sell.php',
        'Expenses sales trend'  => 'sales_trend.php',
        'Add patient'           => 'add_patients.php',
        'Settings'              => 'settings.php',
    ];
}

/* ------------------------- Identity helpers ------------------------- */

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function current_user(): string
{
    return (string)(
        $_SESSION['full_name']
        ?? $_SESSION['sessionUsername']
        ?? $_SESSION['username']
        ?? 'Guest'
    );
}

function current_username(): ?string
{
    return $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? null;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_pharmacy(): ?int
{
    $id = (int)($_SESSION['pharmacy_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function current_branch(): ?int
{
    $id = (int)($_SESSION['branch_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function current_branch_name(): string
{
    return (string)($_SESSION['branch_name'] ?? 'Main Branch');
}

/* ------------------------- Frozen accounts ------------------------- */

function is_current_user_frozen(): bool
{
    global $conn;

    $id = current_user_id();
    if (!$id || !isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COALESCE(is_frozen,0) AS is_frozen
             FROM users WHERE id=? LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['is_frozen'] ?? 0) === 1;
    } catch (Throwable $e) {
        error_log('EchoTech frozen-account check: '.$e->getMessage());
        return false;
    }
}

function destroy_auth_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'] ?? '/',
            $p['domain'] ?? '',
            (bool)($p['secure'] ?? false),
            (bool)($p['httponly'] ?? true)
        );
    }

    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login_inc.php?error=session_expired');
        exit;
    }

    if (is_current_user_frozen()) {
        destroy_auth_session();
        header('Location: /login_inc.php?error=account_frozen');
        exit;
    }

    /*
     * Protect ONLY registered Dashboard/POS page routes.
     * This never applies to logout.php, login_inc.php, action files,
     * APIs, includes, AJAX endpoints, or other PHP files.
     */
    $currentFile = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $matchedPage = null;

    foreach (echotech_page_routes() as $pageName => $routeFile) {
        if ($routeFile === $currentFile) {
            $matchedPage = $pageName;
            break;
        }
    }

    if ($matchedPage !== null && current_role() !== 'Admin') {
        if (!has_page_access($matchedPage)) {
            http_response_code(403);
            render_denied_message('Your role does not have access to this page.');
        }
    }
}

function require_pharmacy(): void
{
    require_login();

    if (current_pharmacy() !== null) return;

    http_response_code(403);
    render_denied_message('Your account is not assigned to a valid pharmacy.');
}

function require_branch(): void
{
    require_login();

    if (current_branch() !== null) return;

    http_response_code(403);
    render_denied_message('Your account is not assigned to a valid branch.');
}

function require_admin(): void
{
    require_login();

    $role = current_role();

    if ($role !== 'Admin' && $role !== 'Manager') {
        http_response_code(403);
        render_denied_message(
            'This area is restricted to Administrative and Management staff only.'
        );
    }
}

/**
 * Human Resource portal access.
 *
 * HR is a separate portal. Human Resource users can access HR pages,
 * while Admin users retain oversight/access to the HR portal.
 * This does NOT grant HR access to Admin-only pages.
 */
function require_hr_portal(): void
{
    require_pharmacy();

    $role = trim((string)(current_role() ?? ''));

    if (!in_array($role, ['Admin', 'Human Resource'], true)) {
        http_response_code(403);
        render_denied_message(
            'This area is restricted to Human Resource and Administrative staff.'
        );
    }
}

/**
 * Staff Management is an Admin-only control.
 *
 * Do not use require_admin() here because that helper intentionally allows
 * Manager access to other administrative/management areas.
 */
function require_staff_management(): void
{
    require_pharmacy();

    if (current_role() !== 'Admin') {
        http_response_code(403);
        render_denied_message(
            'Staff Management is restricted to Admin accounts only.'
        );
    }
}

function require_user(): void
{
    require_login();
}

function require_role(array $allowedRoles): void
{
    require_login();

    if (!in_array(current_role(), $allowedRoles, true)) {
        http_response_code(403);
        render_denied_message('You do not have permission to access this area.');
    }
}

/* ------------------------- Legacy permissions ------------------------- */

function has_permission(string $module, string $action = 'can_view'): bool
{
    global $conn;

    $role = current_role();
    if (!$role) return false;
    if ($role === 'Admin') return true;

    $allowed = ['can_view','can_add','can_edit','can_delete'];
    if (!in_array($action, $allowed, true)) return false;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;

    try {
        $stmt = $conn->prepare(
            "SELECT `$action`
             FROM role_permissions
             WHERE role=? AND module_name=?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('ss', $role, $module);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row[$action] ?? 0) === 1;
    } catch (Throwable $e) {
        error_log('EchoTech legacy permission check: '.$e->getMessage());
        return false;
    }
}

/* ------------------------- Page/function permissions ------------------------- */

function echotech_permission_table_ready(): bool
{
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;

    static $ready = null;
    if ($ready !== null) return $ready;

    $sql = "CREATE TABLE IF NOT EXISTS role_page_permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        pharmacy_id INT NOT NULL,
        role VARCHAR(100) NOT NULL,
        page_name VARCHAR(150) NOT NULL,
        can_access TINYINT(1) NOT NULL DEFAULT 1,
        can_action TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_role_page (pharmacy_id, role, page_name),
        KEY idx_pharmacy (pharmacy_id),
        KEY idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $ready = (bool)$conn->query($sql);

    return $ready;
}

function has_page_access(string $page): bool
{
    global $conn;

    if (!is_logged_in()) return false;

    if (current_role() === 'Admin') return true;
    if (!in_array($page, echotech_pages(), true)) return false;

    $pharmacy = current_pharmacy();
    $role = current_role();

    if (!$pharmacy || !$role || !isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }

    if (!echotech_permission_table_ready()) return false;

    try {
        $stmt = $conn->prepare(
            "SELECT can_access
             FROM role_page_permissions
             WHERE pharmacy_id=? AND role=? AND page_name=?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('iss', $pharmacy, $role, $page);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /*
         * Missing permission records are allowed by default. This makes
         * newly added POS pages usable immediately; the staff screen will
         * create the explicit row when it is opened.
         */
        return $row === null ? true : (int)$row['can_access'] === 1;
    } catch (Throwable $e) {
        error_log('EchoTech page access check: '.$e->getMessage());
        return false;
    }
}

function has_page_function_access(string $page): bool
{
    global $conn;

    if (!is_logged_in()) return false;

    if (current_role() === 'Admin') return true;
    if (!in_array($page, echotech_pages(), true)) return false;

    $pharmacy = current_pharmacy();
    $role = current_role();

    if (!$pharmacy || !$role || !isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }

    if (!echotech_permission_table_ready()) return false;

    try {
        $stmt = $conn->prepare(
            "SELECT can_access, can_action
             FROM role_page_permissions
             WHERE pharmacy_id=? AND role=? AND page_name=?
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('iss', $pharmacy, $role, $page);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null
            ? true
            : ((int)$row['can_access'] === 1 && (int)$row['can_action'] === 1);
    } catch (Throwable $e) {
        error_log('EchoTech function access check: '.$e->getMessage());
        return false;
    }
}

function require_page_access(string $page): void
{
    require_login();

    if (!has_page_access($page)) {
        http_response_code(403);
        render_denied_message(
            'Your role does not have access to this page.'
        );
    }
}

function require_page_function(string $page): void
{
    require_login();

    if (!has_page_function_access($page)) {
        http_response_code(403);
        render_denied_message(
            'Your role does not have permission to perform this function.'
        );
    }
}

function render_denied_message(string $message): void
{
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    echo '<!doctype html><html><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Access Denied | EchoTech POS</title>
    <style>
    body{margin:0;background:#f4f6f8;color:#202831;font-family:Arial,sans-serif}
    .denied{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .box{width:min(430px,100%);background:#fff;border:1px solid #dfe4e9;
         border-radius:16px;padding:36px;text-align:center;
         box-shadow:0 12px 35px rgba(31,40,49,.10)}
    .icon{width:56px;height:56px;margin:0 auto 15px;border-radius:50%;
          background:#fff0f2;color:#d94d61;display:flex;align-items:center;
          justify-content:center;font-weight:800;font-size:22px}
    h2{font-size:20px;margin:0 0 8px}
    p{font-size:13px;line-height:1.6;color:#6d7782}
    a{display:inline-block;padding:10px 20px;background:#246bfe;color:#fff;
      text-decoration:none;border-radius:8px;font-weight:700;font-size:12px}
    </style></head><body><div class="denied"><div class="box">
    <div class="icon">!</div><h2>Access Denied</h2>
    <p>'.$safe.'</p>
    <a href="/dashboard/dashboard.php">Back to Dashboard</a>
    </div></div></body></html>';
    exit;
}
?>
