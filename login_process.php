<?php
session_start();
// Adjusted paths for root directory
require_once "includes/conn.php"; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize input
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    // 1. Fetch user and join with branches to get the location name
    $sql = "SELECT u.*, b.branch_name 
            FROM users u 
            LEFT JOIN branches b ON u.branch_id = b.id 
            WHERE u.username = ? LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    // 2. Verify the account exists and the password is correct
    if ($user && password_verify($password, $user['password'])) {
        
        // 3. SECURE THE SESSION (The "Staff Isolation" Fix)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role']; // e.g., 'admin' or 'user'
        $_SESSION['branch_id'] = $user['branch_id']; 
        $_SESSION['branch_name'] = $user['branch_name'] ?? 'Main Branch';

        // 4. Record the login time for your audit trail
        $user_id = $user['id'];
        mysqli_query($conn, "UPDATE users SET last_login = NOW() WHERE id = $user_id");

        // 5. Redirect to the dashboard
        // If your dashboard is in a folder, use "dashboard/index.php"
        header("Location: dashboard/index.php"); 
        exit;

    } else {
        // Login failed - redirect back with an error message
        $_SESSION['status'] = 'danger';
        $_SESSION['message'] = 'Invalid Username or Password.';
        header("Location: login_inc.php");
        exit;
    }
} else {
    // If someone tries to access this file directly without POSTing
    header("Location: login_inc.php");
    exit;
}