<?php
/**
 * ============================================================
 * EchoTech POS
 * Authentication & Authorization Helpers
 * ============================================================
 *
 * Supported roles:
 *
 *   Admin
 *   Pharmacist
 *   Manager
 *   User
 *   Cashier
 * ============================================================
 */

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Start secure session
|--------------------------------------------------------------------------
*/

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
        'samesite' => 'Lax',
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

/**
 * Determine whether a user is authenticated.
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}


/**
 * Get authenticated user ID.
 */
function current_user_id(): ?int
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}


/**
 * Get current username/display name.
 */
function current_user(): string
{
    return
        $_SESSION['full_name']
        ?? $_SESSION['sessionUsername']
        ?? $_SESSION['username']
        ?? 'Guest';
}


/**
 * Get current username/login name.
 */
function current_username(): ?string
{
    return $_SESSION['username']
        ?? $_SESSION['sessionUsername']
        ?? null;
}


/**
 * Get current role.
 */
function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}


/*
|--------------------------------------------------------------------------
| Tenant helpers
|--------------------------------------------------------------------------
*/

/**
 * Get current pharmacy ID.
 */
function current_pharmacy(): ?int
{
    if (!isset($_SESSION['pharmacy_id'])) {
        return null;
    }

    $id = (int) $_SESSION['pharmacy_id'];

    return $id > 0 ? $id : null;
}


/**
 * Get current branch ID.
 */
function current_branch(): ?int
{
    if (!isset($_SESSION['branch_id'])) {
        return null;
    }

    $id = (int) $_SESSION['branch_id'];

    return $id > 0 ? $id : null;
}


/**
 * Get current branch name.
 */
function current_branch_name(): string
{
    return $_SESSION['branch_name'] ?? 'Main Branch';
}


/*
|--------------------------------------------------------------------------
| Authentication requirements
|--------------------------------------------------------------------------
*/

/**
 * Redirect unauthenticated users to login.
 */
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Always use an absolute application path.
    |--------------------------------------------------------------------------
    */

    $loginUrl = '/login_inc.php?error=session_expired';

    header('Location: ' . $loginUrl);
    exit;
}


/**
 * Require a valid pharmacy assignment.
 */
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

    exit;
}


/**
 * Require a valid branch assignment.
 */
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

    exit;
}


/*
|--------------------------------------------------------------------------
| Role restrictions
|--------------------------------------------------------------------------
*/

/**
 * Require Admin or Manager.
 */
function require_admin(): void
{
    require_login();

    $role = current_role();

    if ($role !== 'Admin' && $role !== 'Manager') {

        http_response_code(403);

        render_denied_message(
            'This area is restricted to Administrative and Management staff only.'
        );

        exit;
    }
}


/**
 * Require any authenticated staff user.
 */
function require_user(): void
{
    require_login();
}


/**
 * Require one of the supplied roles.
 *
 * Example:
 *
 * require_role(['Admin', 'Manager']);
 */
function require_role(array $allowedRoles): void
{
    require_login();

    $role = current_role();

    if (!in_array($role, $allowedRoles, true)) {

        http_response_code(403);

        render_denied_message(
            'You do not have permission to access this area.'
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Permission system
|--------------------------------------------------------------------------
*/

/**
 * Check a granular role permission.
 *
 * Example:
 *
 * has_permission('inventory', 'can_edit')
 */
function has_permission(
    string $module,
    string $action = 'can_view'
): bool {

    global $conn;

    $role = current_role();

    /*
    |--------------------------------------------------------------------------
    | Must be authenticated
    |--------------------------------------------------------------------------
    */

    if (!$role) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Admin bypass
    |--------------------------------------------------------------------------
    */

    if ($role === 'Admin') {
        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Whitelist permission columns
    |--------------------------------------------------------------------------
    |
    | Prevents an arbitrary SQL identifier being injected into
    | the SELECT statement.
    |
    */

    $allowedActions = [
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
    ];

    if (!in_array($action, $allowedActions, true)) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Database connection check
    |--------------------------------------------------------------------------
    */

    if (!isset($conn) || !($conn instanceof mysqli)) {
        error_log(
            'has_permission(): database connection unavailable.'
        );

        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Permission query
    |--------------------------------------------------------------------------
    */

    try {

        $sql = "
            SELECT `$action`
            FROM role_permissions
            WHERE role = ?
              AND module_name = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            'ss',
            $role,
            $module
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $allowed = false;

        if ($row = $result->fetch_assoc()) {
            $allowed = ((int) ($row[$action] ?? 0) === 1);
        }

        $stmt->close();

        return $allowed;

    } catch (Throwable $e) {

        error_log(
            'Permission check failed: '
            . $e->getMessage()
        );

        return false;
    }
}


/*
|--------------------------------------------------------------------------
| Access denied UI
|--------------------------------------------------------------------------
*/

/**
 * Display access denied message.
 */
function render_denied_message(string $message): void
{
    $safeMessage = htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    );

    echo "
    <div style='
        background:#0f111a;
        color:white;
        min-height:100vh;
        display:flex;
        align-items:center;
        justify-content:center;
        font-family:sans-serif;
        padding:20px;
    '>

        <div style='
            text-align:center;
            padding:40px;
            background:#161b22;
            border:1px solid #30363d;
            border-radius:16px;
            max-width:400px;
            width:100%;
        '>

            <h2 style='color:#ff4d4d;'>
                ⚠️ Access Denied
            </h2>

            <p style='color:#8b949e;'>
                {$safeMessage}
            </p>

            <hr style='
                border:0;
                border-top:1px solid #30363d;
                margin:20px 0;
            '>

            <a href='/dashboard/index.php'
               style='
                   display:inline-block;
                   padding:10px 20px;
                   background:#00d2ff;
                   color:#000;
                   text-decoration:none;
                   border-radius:30px;
                   font-weight:bold;
               '>
                Back to Dashboard
            </a>

        </div>

    </div>";

    exit;
}
