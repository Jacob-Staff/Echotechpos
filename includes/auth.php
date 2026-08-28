<?php
/**
 * ============================================================
 * ECHOTECH POS
 * AUTHENTICATION + AUTHORIZATION
 * ============================================================
 *
 * Features:
 * - Secure session startup
 * - Login protection
 * - Frozen-account protection
 * - Pharmacy / branch context
 * - Role helpers
 * - Legacy module permissions
 * - Page permissions
 * - Function permissions
 * - Access-denied screen
 *
 * IMPORTANT:
 * Admin has unrestricted page/function access.
 * Manager does NOT automatically receive Admin privileges.
 * ============================================================
 */

declare(strict_types=1);


/* ============================================================
   SECURE SESSION
   ============================================================ */

if (session_status() === PHP_SESSION_NONE) {

    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ||
        (
            isset($_SERVER['SERVER_PORT'])
            && (int) $_SERVER['SERVER_PORT'] === 443
        );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}


/* ============================================================
   STAFF ROLES
   ============================================================ */

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
        'Security'
    ];
}


/* ============================================================
   POS PAGES
   ============================================================ */

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
        'Settings'
    ];
}


/* ============================================================
   AUTHENTICATION HELPERS
   ============================================================ */

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}


function current_user_id(): ?int
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}


function current_user(): string
{
    return (string) (
        $_SESSION['full_name']
        ??
        $_SESSION['sessionUsername']
        ??
        $_SESSION['username']
        ??
        'Guest'
    );
}


function current_username(): ?string
{
    return $_SESSION['username']
        ??
        $_SESSION['sessionUsername']
        ??
        null;
}


function current_role(): ?string
{
    return isset($_SESSION['role'])
        ? (string) $_SESSION['role']
        : null;
}


function current_pharmacy(): ?int
{
    $id = (int) ($_SESSION['pharmacy_id'] ?? 0);

    return $id > 0 ? $id : null;
}


function current_branch(): ?int
{
    $id = (int) ($_SESSION['branch_id'] ?? 0);

    return $id > 0 ? $id : null;
}


function current_branch_name(): string
{
    return (string) (
        $_SESSION['branch_name']
        ??
        'Main Branch'
    );
}


/* ============================================================
   ROLE HELPERS
   ============================================================ */

function is_admin(): bool
{
    return current_role() === 'Admin';
}


function is_manager(): bool
{
    return current_role() === 'Manager';
}


function is_admin_or_manager(): bool
{
    return in_array(
        current_role(),
        ['Admin', 'Manager'],
        true
    );
}


/* ============================================================
   FROZEN ACCOUNT
   ============================================================ */

function is_current_user_frozen(): bool
{
    global $conn;

    $id = current_user_id();

    if (
        !$id
        ||
        !isset($conn)
        ||
        !($conn instanceof mysqli)
    ) {
        return false;
    }

    try {

        $stmt = $conn->prepare(
            "SELECT is_frozen
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int) ($row['is_frozen'] ?? 0) === 1;

    } catch (Throwable $e) {

        error_log(
            'EchoTech frozen-account check: '
            . $e->getMessage()
        );

        return false;
    }
}


/* ============================================================
   DESTROY AUTH SESSION
   ============================================================ */

function destroy_auth_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}


/* ============================================================
   REQUIRE LOGIN
   ============================================================ */

function require_login(): void
{
    if (!is_logged_in()) {

        header(
            'Location: /login_inc.php?error=session_expired'
        );

        exit;
    }

    /*
     * Check frozen status on every protected request.
     */
    if (is_current_user_frozen()) {

        destroy_auth_session();

        header(
            'Location: /login_inc.php?error=account_frozen'
        );

        exit;
    }
}


/* ============================================================
   REQUIRE PHARMACY
   ============================================================ */

function require_pharmacy(): void
{
    require_login();

    if (current_pharmacy() !== null) {
        return;
    }

    http_response_code(403);

    render_denied_message(
        'Your account is not assigned to a valid pharmacy.'
    );
}


/* ============================================================
   REQUIRE BRANCH
   ============================================================ */

function require_branch(): void
{
    require_login();

    if (current_branch() !== null) {
        return;
    }

    http_response_code(403);

    render_denied_message(
        'Your account is not assigned to a valid branch.'
    );
}


/* ============================================================
   REQUIRE ADMIN / MANAGEMENT
   ============================================================ */

function require_admin(): void
{
    require_login();

    $role = current_role();

    if (
        $role !== 'Admin'
        &&
        $role !== 'Manager'
    ) {

        http_response_code(403);

        render_denied_message(
            'This area is restricted to Administrative and Management staff only.'
        );
    }
}


/* ============================================================
   REQUIRE NORMAL USER
   ============================================================ */

function require_user(): void
{
    require_login();
}


/* ============================================================
   REQUIRE SPECIFIC ROLE
   ============================================================ */

function require_role(array $allowedRoles): void
{
    require_login();

    if (
        !in_array(
            current_role(),
            $allowedRoles,
            true
        )
    ) {

        http_response_code(403);

        render_denied_message(
            'You do not have permission to access this area.'
        );
    }
}


/* ============================================================
   LEGACY MODULE PERMISSION
   ============================================================
 *
 * Supported actions:
 * - can_view
 * - can_add
 * - can_edit
 * - can_delete
 */

function has_permission(
    string $module,
    string $action = 'can_view'
): bool {

    global $conn;

    $role = current_role();

    if (!$role) {
        return false;
    }

    /*
     * Admin = unrestricted.
     */
    if ($role === 'Admin') {
        return true;
    }

    $allowedActions = [
        'can_view',
        'can_add',
        'can_edit',
        'can_delete'
    ];

    if (
        !in_array(
            $action,
            $allowedActions,
            true
        )
    ) {
        return false;
    }

    if (
        !isset($conn)
        ||
        !($conn instanceof mysqli)
    ) {
        return false;
    }

    try {

        /*
         * Action is whitelisted above, so it is safe
         * to use as the selected column.
         */
        $stmt = $conn->prepare(
            "SELECT `$action`
             FROM role_permissions
             WHERE role = ?
             AND module_name = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $role,
            $module
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int) ($row[$action] ?? 0) === 1;

    } catch (Throwable $e) {

        error_log(
            'EchoTech legacy permission check: '
            . $e->getMessage()
        );

        return false;
    }
}


/* ============================================================
   PAGE ACCESS
   ============================================================ */

function has_page_access(string $page): bool
{
    global $conn;

    require_login();

    $role = current_role();

    /*
     * Admin has unrestricted access.
     */
    if ($role === 'Admin') {
        return true;
    }

    /*
     * Reject unknown page names.
     */
    if (!in_array(
        $page,
        echotech_pages(),
        true
    )) {
        return false;
    }

    $pharmacy = current_pharmacy();

    if (
        !$pharmacy
        ||
        !isset($conn)
        ||
        !($conn instanceof mysqli)
    ) {
        return false;
    }

    try {

        $stmt = $conn->prepare(
            "SELECT can_access
             FROM role_page_permissions
             WHERE pharmacy_id = ?
             AND role = ?
             AND page_name = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'iss',
            $pharmacy,
            $role,
            $page
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int) ($row['can_access'] ?? 0) === 1;

    } catch (Throwable $e) {

        error_log(
            'EchoTech page-access check: '
            . $e->getMessage()
        );

        return false;
    }
}


/* ============================================================
   PAGE FUNCTION ACCESS
   ============================================================ */

function has_page_function_access(string $page): bool
{
    global $conn;

    require_login();

    $role = current_role();

    /*
     * Admin = unrestricted.
     */
    if ($role === 'Admin') {
        return true;
    }

    if (!in_array(
        $page,
        echotech_pages(),
        true
    )) {
        return false;
    }

    $pharmacy = current_pharmacy();

    if (
        !$pharmacy
        ||
        !isset($conn)
        ||
        !($conn instanceof mysqli)
    ) {
        return false;
    }

    try {

        $stmt = $conn->prepare(
            "SELECT can_access, can_action
             FROM role_page_permissions
             WHERE pharmacy_id = ?
             AND role = ?
             AND page_name = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'iss',
            $pharmacy,
            $role,
            $page
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        /*
         * Functions require BOTH:
         * - page access
         * - function permission
         */
        return (
            (int) ($row['can_access'] ?? 0) === 1
            &&
            (int) ($row['can_action'] ?? 0) === 1
        );

    } catch (Throwable $e) {

        error_log(
            'EchoTech function-access check: '
            . $e->getMessage()
        );

        return false;
    }
}


/* ============================================================
   REQUIRE PAGE ACCESS
   ============================================================ */

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


/* ============================================================
   REQUIRE PAGE FUNCTION
   ============================================================ */

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


/* ============================================================
   HTML ESCAPE HELPER
   ============================================================ */

if (!function_exists('echotech_h')) {

    function echotech_h($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ============================================================
   ACCESS DENIED SCREEN
   ============================================================ */

function render_denied_message(string $message): void
{
    $safe = echotech_h($message);

    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <title>Access Denied | EchoTech POS</title>

        <style>

            * {
                box-sizing: border-box;
            }

            html,
            body {
                margin: 0;
                min-height: 100%;
            }

            body {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;

                background: #f4f6f8;
                color: #202831;

                font-family:
                    Inter,
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    Arial,
                    sans-serif;
            }

            .denied-card {
                width: 100%;
                max-width: 440px;

                background: #ffffff;

                border: 1px solid #dfe4e9;
                border-radius: 16px;

                padding: 38px 32px;

                text-align: center;

                box-shadow:
                    0 20px 55px
                    rgba(31, 40, 49, .10);
            }

            .denied-icon {
                width: 62px;
                height: 62px;

                margin: 0 auto 17px;

                border-radius: 50%;

                display: flex;
                align-items: center;
                justify-content: center;

                background: #fff0f2;
                color: #d94d61;

                font-size: 25px;
                font-weight: 900;
            }

            h1 {
                margin: 0 0 9px;

                font-size: 22px;
                font-weight: 800;

                color: #202831;
            }

            p {
                margin: 0;

                color: #6d7782;

                font-size: 13px;
                line-height: 1.65;
            }

            .line {
                height: 1px;

                background: #e5e9ed;

                margin: 24px 0;
            }

            .back {
                display: inline-flex;
                align-items: center;
                justify-content: center;

                min-height: 42px;

                padding: 0 20px;

                border-radius: 9px;

                background: #246bfe;
                color: #ffffff;

                text-decoration: none;

                font-size: 12px;
                font-weight: 800;
            }

            .back:hover {
                background: #1559dc;
            }

        </style>
    </head>

    <body>

        <div class="denied-card">

            <div class="denied-icon">!</div>

            <h1>Access Denied</h1>

            <p>
                ' . $safe . '
            </p>

            <div class="line"></div>

            <a
                class="back"
                href="/dashboard/index.php"
            >
                Back to Dashboard
            </a>

        </div>

    </body>
    </html>';

    exit;
}

?>
