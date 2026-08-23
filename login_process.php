<?php
/**
 * ============================================================
 * EchoTech POS
 * Secure Login Processor
 * ============================================================
 *
 * Establishes the complete authenticated tenant session:
 *
 *   user_id
 *   username
 *   sessionUsername
 *   full_name
 *   role
 *   pharmacy_id
 *   branch_id
 *   branch_name
 *
 * Database:
 *   includes/conn.php
 * ============================================================
 */

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Session security
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
| Database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/conn.php';


/*
|--------------------------------------------------------------------------
| Only POST requests are allowed
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: login_inc.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get submitted credentials
|--------------------------------------------------------------------------
*/

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';


/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if ($username === '' || $password === '') {

    $_SESSION['status'] = 'danger';
    $_SESSION['message'] = 'Please enter your username and password.';

    header('Location: login_inc.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
|
| We retrieve:
|
|   users
|   branch information
|
| The branch is also matched to the user's pharmacy to prevent
| accidental cross-tenant branch association.
|
*/

$sql = "
    SELECT
        u.id,
        u.username,
        u.password,
        u.full_name,
        u.role,
        u.pharmacy_id,
        u.branch_id,
        u.mobile_number,
        u.last_login,
        b.branch_name
    FROM users u
    LEFT JOIN branches b
        ON b.id = u.branch_id
        AND b.pharmacy_id = u.pharmacy_id
    WHERE u.username = ?
    LIMIT 1
";


try {

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        's',
        $username
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $user = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {

    error_log(
        'Login database error: '
        . $e->getMessage()
    );

    $_SESSION['status'] = 'danger';
    $_SESSION['message'] = 'Unable to process login right now.';

    header('Location: login_inc.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Verify username and password
|--------------------------------------------------------------------------
*/

if (!$user || !password_verify($password, $user['password'])) {

    /*
    |--------------------------------------------------------------------------
    | Generic error
    |--------------------------------------------------------------------------
    |
    | Do not reveal whether the username exists.
    |
    */

    $_SESSION['status'] = 'danger';
    $_SESSION['message'] = 'Invalid Username or Password.';

    header('Location: login_inc.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Validate tenant information
|--------------------------------------------------------------------------
*/

$pharmacyId = (int) ($user['pharmacy_id'] ?? 0);
$branchId   = (int) ($user['branch_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Pharmacy is mandatory for a tenant account
|--------------------------------------------------------------------------
*/

if ($pharmacyId <= 0) {

    error_log(
        'Login rejected: user ID '
        . (int) $user['id']
        . ' has no valid pharmacy_id.'
    );

    $_SESSION['status'] = 'danger';
    $_SESSION['message'] = 'Your account is not assigned to a pharmacy. Please contact an administrator.';

    header('Location: login_inc.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Branch handling
|--------------------------------------------------------------------------
|
| Some administrative accounts may not have a branch.
|
| We therefore preserve NULL/0 rather than assigning a fake
| branch automatically.
|
*/

$branchName = trim((string) ($user['branch_name'] ?? ''));

if ($branchName === '') {
    $branchName = 'Main Branch';
}


/*
|--------------------------------------------------------------------------
| Regenerate session ID
|--------------------------------------------------------------------------
|
| Prevents session fixation after successful authentication.
|
*/

session_regenerate_id(true);


/*
|--------------------------------------------------------------------------
| Establish authenticated session
|--------------------------------------------------------------------------
*/

$_SESSION['user_id'] = (int) $user['id'];

$_SESSION['username'] = $user['username'];

/*
 * Backward compatibility:
 * Existing pages currently use both username and
 * sessionUsername.
 */
$_SESSION['sessionUsername'] = $user['username'];

$_SESSION['full_name'] = $user['full_name'] ?? $user['username'];

$_SESSION['role'] = $user['role'];

$_SESSION['pharmacy_id'] = $pharmacyId;

$_SESSION['branch_id'] = $branchId > 0
    ? $branchId
    : null;

$_SESSION['branch_name'] = $branchName;


/*
|--------------------------------------------------------------------------
| Optional user information
|--------------------------------------------------------------------------
*/

$_SESSION['mobile_number'] = $user['mobile_number'] ?? null;


/*
|--------------------------------------------------------------------------
| Login timestamp
|--------------------------------------------------------------------------
*/

try {

    $update = $conn->prepare("
        UPDATE users
        SET last_login = NOW()
        WHERE id = ?
    ");

    $userId = (int) $user['id'];

    $update->bind_param(
        'i',
        $userId
    );

    $update->execute();

    $update->close();

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Login should not fail merely because audit timestamp failed.
    |--------------------------------------------------------------------------
    */

    error_log(
        'Unable to update last_login for user '
        . (int) $user['id']
        . ': '
        . $e->getMessage()
    );
}


/*
|--------------------------------------------------------------------------
| Clear old login messages
|--------------------------------------------------------------------------
*/

unset(
    $_SESSION['status'],
    $_SESSION['message']
);


/*
|--------------------------------------------------------------------------
| Go to dashboard
|--------------------------------------------------------------------------
*/

header('Location: dashboard/index.php');
exit;
