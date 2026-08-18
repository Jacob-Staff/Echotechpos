<?php
session_start();

// Clear all session data
$_SESSION = array();

// Destroy the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect using a Root-Relative path
// This ensures it works from /online_store.php AND /api/view_cart.php
header("Location: /pharmacy_v1-master/login_client.php");
exit();
?>