<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Preserve active branch ID if needed for post-logout redirect
$current_branch = isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 0;

// Clear all session variables
$_SESSION = array();

// Destroy session cookies safely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect contextually to login_client.php
$redirect_url = "login_client.php";
if ($current_branch > 0) {
    $redirect_url .= "?bid=" . $current_branch;
}

header("Location: " . $redirect_url);
exit();
?>
