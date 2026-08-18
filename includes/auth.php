<?php
/**
 * Authentication Helper Functions
 * Optimized for PHARMA-JACOBS Multi-Branch Operations
 * Roles: Admin, Pharmacist, Manager, User, Cashier
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is currently authenticated
 */
function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/**
 * Get the current display name
 * FIXED: Matches 'sessionUsername' from login.php
 */
function current_user() {
    return $_SESSION['sessionUsername'] ?? 'Guest';
}

/**
 * Get the current user role
 */
function current_role() {
    return $_SESSION['role'] ?? null; 
}

/**
 * Get the current branch ID for data filtering
 */
function current_branch() {
    return isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : null;
}

/**
 * Redirect to login if the session is invalid
 */
function require_login() {
    if (!is_logged_in()) {
        header('Location: ../login.php?error=session_expired'); 
        exit;
    }
}

/**
 * Restrict access to Management (Admin & Manager)
 * Used for Staff Management, Payroll, and Global Reports
 */
function require_admin() {
    require_login();
    $current = current_role();
    
    // Only Admin and Manager can access these files
    if ($current !== 'Admin' && $current !== 'Manager') {
        http_response_code(403);
        render_denied_message("This area is restricted to Administrative and Management staff only.");
        exit;
    }
}

/**
 * Restrict access to standard staff modules
 */
function require_user() {
    require_login();
    // Logic ensures the user is at least logged in; roles are checked via has_permission
}

/**
 * Helper to display a styled Access Denied message
 * Enhanced with PHARMA-JACOBS dark theme styling
 */
function render_denied_message($message) {
    echo "
    <div style='background:#0f111a; color:white; height:100vh; display:flex; align-items:center; justify-content:center; font-family:sans-serif;'>
        <div style='text-align:center; padding: 40px; background:#161b22; border:1px solid #30363d; border-radius:16px; max-width:400px;'>
            <h2 style='color: #ff4d4d;'>⚠️ Access Denied</h2>
            <p style='color:#8b949e;'>$message</p>
            <hr style='border:0; border-top:1px solid #30363d; margin: 20px 0;'>
            <a href='../index.php' style='display:inline-block; padding:10px 20px; background:#00d2ff; color:#000; text-decoration:none; border-radius:30px; font-weight:bold;'>Back to Safety</a>
        </div>
    </div>";
    die();
}

/**
 * Granular Permission Check
 * Usage: if(has_permission('inventory', 'can_edit')) { ... }
 */
function has_permission($module, $action = 'can_view') {
    global $conn;
    $role = current_role();
    
    // Admins are "God Mode" - bypass all checks
    if ($role === 'Admin') return true;

    // Prepared statement for security
    $stmt = $conn->prepare("SELECT $action FROM role_permissions WHERE role = ? AND module_name = ?");
    $stmt->bind_param("ss", $role, $module);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return ($row[$action] == 1);
    }

    return false;
}