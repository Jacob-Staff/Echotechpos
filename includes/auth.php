<?php
/**
 * EchoTech POS - Authentication & Authorization Helpers
 * Roles + page access + function access + account freeze.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

function echotech_staff_roles(): array {
    return ['Human Resource','Pharmacist','Manager','Assistant Manager','PharmTech','Clinical Officer','Registered Nurse','Cashier','General','Security'];
}

function echotech_pages(): array {
    return ['Sale now','Today transaction','Out of stock','Expired products','Customer','Online manager','Pharmacy stock','Purchases orders','Supplier','Add Product','Stock exchange','Shift log','Purchases order list','Restock','Online orders','Sales report','Lay by sale','Expenses sales trend','Add patient','Settings'];
}

function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function current_user_id(): ?int { return empty($_SESSION['user_id']) ? null : (int)$_SESSION['user_id']; }
function current_user(): string { return (string)($_SESSION['full_name'] ?? $_SESSION['sessionUsername'] ?? $_SESSION['username'] ?? 'Guest'); }
function current_username(): ?string { return $_SESSION['username'] ?? $_SESSION['sessionUsername'] ?? null; }
function current_role(): ?string { return $_SESSION['role'] ?? null; }
function current_pharmacy(): ?int { $id=(int)($_SESSION['pharmacy_id']??0); return $id>0?$id:null; }
function current_branch(): ?int { $id=(int)($_SESSION['branch_id']??0); return $id>0?$id:null; }
function current_branch_name(): string { return (string)($_SESSION['branch_name'] ?? 'Main Branch'); }

function is_current_user_frozen(): bool {
    global $conn;
    $id=current_user_id();
    if (!$id || !isset($conn) || !($conn instanceof mysqli)) return false;
    try {
        $stmt=$conn->prepare("SELECT is_frozen FROM users WHERE id=? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i',$id); $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int)($row['is_frozen']??0)===1;
    } catch(Throwable $e) { error_log('Frozen account check: '.$e->getMessage()); return false; }
}

function destroy_auth_session(): void {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
    }
    session_destroy();
}

function require_login(): void {
    if (!is_logged_in()) { header('Location: /login_inc.php?error=session_expired'); exit; }
    if (is_current_user_frozen()) { destroy_auth_session(); header('Location: /login_inc.php?error=account_frozen'); exit; }
}
function require_pharmacy(): void { require_login(); if(current_pharmacy()!==null)return; http_response_code(403); render_denied_message('Your account is not assigned to a valid pharmacy.'); }
function require_branch(): void { require_login(); if(current_branch()!==null)return; http_response_code(403); render_denied_message('Your account is not assigned to a valid branch.'); }

function require_admin(): void {
    require_login();
    $role=current_role();
    if($role!=='Admin' && $role!=='Manager') { http_response_code(403); render_denied_message('This area is restricted to Administrative and Management staff only.'); }
}
function require_user(): void { require_login(); }
function require_role(array $allowedRoles): void { require_login(); if(!in_array(current_role(),$allowedRoles,true)){http_response_code(403);render_denied_message('You do not have permission to access this area.');} }

/* Existing legacy permission API is preserved for older EchoTech pages. */
function has_permission(string $module,string $action='can_view'): bool {
    global $conn; $role=current_role();
    if(!$role) return false;
    if($role==='Admin') return true;
    $allowed=['can_view','can_add','can_edit','can_delete'];
    if(!in_array($action,$allowed,true) || !isset($conn) || !($conn instanceof mysqli)) return false;
    try {
        $stmt=$conn->prepare("SELECT `$action` FROM role_permissions WHERE role=? AND module_name=? LIMIT 1");
        if(!$stmt)return false; $stmt->bind_param('ss',$role,$module); $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int)($row[$action]??0)===1;
    } catch(Throwable $e){error_log('Legacy permission check: '.$e->getMessage());return false;}
}

function has_page_access(string $page): bool {
    global $conn; require_login(); $role=current_role();
    if($role==='Admin') return true;
    if(!in_array($page,echotech_pages(),true)) return false;
    $pharmacy=current_pharmacy(); if(!$pharmacy || !isset($conn) || !($conn instanceof mysqli)) return false;
    try {
        $stmt=$conn->prepare("SELECT can_access FROM role_page_permissions WHERE pharmacy_id=? AND role=? AND page_name=? LIMIT 1");
        if(!$stmt)return false; $stmt->bind_param('iss',$pharmacy,$role,$page); $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (int)($row['can_access']??0)===1;
    } catch(Throwable $e){error_log('Page access check: '.$e->getMessage());return false;}
}
function has_page_function_access(string $page): bool {
    global $conn; require_login(); $role=current_role();
    if($role==='Admin') return true;
    if(!in_array($page,echotech_pages(),true)) return false;
    $pharmacy=current_pharmacy(); if(!$pharmacy || !isset($conn) || !($conn instanceof mysqli)) return false;
    try {
        $stmt=$conn->prepare("SELECT can_access,can_action FROM role_page_permissions WHERE pharmacy_id=? AND role=? AND page_name=? LIMIT 1");
        if(!$stmt)return false; $stmt->bind_param('iss',$pharmacy,$role,$page); $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int)($row['can_access']??0)===1 && (int)($row['can_action']??0)===1;
    } catch(Throwable $e){error_log('Page function check: '.$e->getMessage());return false;}
}
function require_page_access(string $page): void { require_login(); if(!has_page_access($page)){http_response_code(403);render_denied_message('Your role does not have access to this page.');} }
function require_page_function(string $page): void { require_login(); if(!has_page_function_access($page)){http_response_code(403);render_denied_message('Your role does not have permission to perform this function.');} }

function render_denied_message(string $message): void {
    $safe=htmlspecialchars($message,ENT_QUOTES,'UTF-8');
    echo "<div style='background:#f4f6f8;color:#202831;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Arial,sans-serif;padding:20px'><div style='text-align:center;padding:38px;background:#fff;border:1px solid #dfe4e9;border-radius:14px;max-width:430px;width:100%;box-shadow:0 12px 35px rgba(31,40,49,.10)'><div style='width:56px;height:56px;margin:0 auto 15px;border-radius:50%;background:#fff0f2;color:#d94d61;display:flex;align-items:center;justify-content:center;font-size:22px'>!</div><h2 style='margin:0 0 8px;color:#202831;font-size:20px'>Access Denied</h2><p style='color:#6d7782;font-size:13px;line-height:1.6;margin:0'>$safe</p><hr style='border:0;border-top:1px solid #e5e9ed;margin:22px 0'><a href='/dashboard/index.php' style='display:inline-block;padding:10px 20px;background:#246bfe;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:12px'>Back to Dashboard</a></div></div>";
    exit;
}
?>
