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

// Dynamic redirect path that works seamlessly on both local XAMPP and live Render
header("Location: login_client.php");
exit();
?>
