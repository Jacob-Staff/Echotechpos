<?php
session_start();

// 1. Clear session data
$_SESSION = array();

// 2. Destroy the session file
session_destroy();

// 3. Clear the cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Redirect to index.php
header("Location: index.php?status=logged_out");
exit();
?>
