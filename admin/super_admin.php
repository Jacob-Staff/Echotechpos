<?php
declare(strict_types=1);

/**
 * EchoTech POS â€” PLATFORM SUPER ADMIN
 *
 * Global control center. This is deliberately separate from the normal
 * pharmacy Admin area: a Super Admin is not tied to one pharmacy_id.
 *
 * Features:
 *  - Global platform dashboard / KPIs
 *  - Create and edit pharmacies (tenants)
 *  - Create staff accounts and manage staff status/freeze state
 *  - Branch activation/deactivation
 *  - Password reset with one-time temporary password display
 *  - Tenant-scoped role/page permission management
 *  - Global compliance + ZRA audit viewing
 *  - Super-admin account management
 *  - CSV export
 *  - 24-hour rolling inactivity session security
 *  - CSRF protection + prepared SQL statements + audit logging
 *
 * Required database tables already exist in the supplied EchoTech schema:
 *  pharmacies, branches, users, role_page_permissions,
 *  compliance_audit_log, pos_zra_audit_log, sales.
 *
 * The page creates only its own two platform tables automatically:
 *  echotech_super_admins
 *  echotech_super_admin_settings
 */

/*
 * Super Admin deliberately uses its own session cookie/name.
 * This keeps a Super Admin session completely separate from the normal
 * pharmacy Admin/POS session in the same browser.
 *
 * IMPORTANT: there is intentionally NO link to this page from index.php.
 * Access is by the direct /admin/super_admin.php URL only.
 */
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    ini_set('session.gc_maxlifetime', '86400');
    session_name('ECHOTECH_SUPERADMIN_SESSID');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';

$db = $conn;
$db->set_charset('utf8mb4');

const SA_SESSION_TIMEOUT = 86400;
const SA_MAX_AUDIT_ROWS = 500;

/* ---------------------------------------------------------------
 * Database bootstrap for the Super Admin subsystem only.
 * ------------------------------------------------------------- */
$db->query("CREATE TABLE IF NOT EXISTS echotech_super_admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    last_login DATETIME DEFAULT NULL,
    last_activity DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_echotech_super_admin_username (username),
    KEY idx_echotech_super_admin_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->query("CREATE TABLE IF NOT EXISTS echotech_super_admin_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function sa_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sa_rows(mysqli $db, string $sql, string $types = '', array $values = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => &$value) $refs[$key] = &$value;
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function sa_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array {
    $rows = sa_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}

function sa_exec(mysqli $db, string $sql, string $types = '', array $values = []): void {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => &$value) $refs[$key] = &$value;
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }
    $stmt->close();
}

function sa_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function sa_csrf(): string {
    if (empty($_SESSION['super_admin_csrf'])) {
        $_SESSION['super_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['super_admin_csrf'];
}

function sa_check_csrf(): void {
    $sessionToken = (string)($_SESSION['super_admin_csrf'] ?? '');
    $postedToken = (string)($_POST['csrf'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh the Super Admin page and try again.');
    }
}

function sa_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function sa_log(mysqli $db, string $action, string $entityType, ?int $entityId, string $description): void {
    if (!sa_table_exists($db, 'compliance_audit_log')) return;
    $pharmacyId = 0;
    $userId = null;
    $stmt = $db->prepare(
        'INSERT INTO compliance_audit_log
         (pharmacy_id,user_id,action,entity_type,entity_id,description,ip_address)
         VALUES (?,?,?,?,?,?,?)'
    );
    if (!$stmt) return;
    $ip = sa_ip();
    $stmt->bind_param('iississ', $pharmacyId, $userId, $action, $entityType, $entityId, $description, $ip);
    @$stmt->execute();
    $stmt->close();
}

function sa_redirect(string $tab = 'dashboard', string $notice = '', string $error = ''): never {
    $query = ['tab' => $tab];
    if ($notice !== '') $query['notice'] = $notice;
    if ($error !== '') $query['error'] = $error;
    header('Location: super_admin.php?' . http_build_query($query));
    exit;
}

function sa_random_password(int $length = 14): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

/* ---------------------------------------------------------------
 * Authentication â€” completely separate from pharmacy Admin.
 * ------------------------------------------------------------- */
$superAdminId = (int)($_SESSION['super_admin_id'] ?? 0);

if ($superAdminId > 0) {
    $super = sa_one(
        $db,
        'SELECT id,username,email,full_name,status,last_login,last_activity FROM echotech_super_admins WHERE id=? LIMIT 1',
        'i',
        [$superAdminId]
    );

    if (!$super || strcasecmp((string)$super['status'], 'Active') !== 0) {
        unset($_SESSION['super_admin_id'], $_SESSION['super_admin_csrf']);
        $superAdminId = 0;
        $super = null;
    } else {
        $lastActivity = strtotime((string)($super['last_activity'] ?? '')) ?: 0;
        if ($lastActivity > 0 && (time() - $lastActivity) >= SA_SESSION_TIMEOUT) {
            unset($_SESSION['super_admin_id'], $_SESSION['super_admin_csrf']);
            $superAdminId = 0;
            $super = null;
        } else {
            sa_exec($db, 'UPDATE echotech_super_admins SET last_activity=NOW() WHERE id=?', 'i', [$superAdminId]);
        }
    }
}

/* ---------------------------------------------------------------
 * POST actions
 * ------------------------------------------------------------- */
$notice = trim((string)($_GET['notice'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    /* Login is intentionally available before authentication. */
    if ($action === 'login') {
        sa_check_csrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $account = $username !== ''
            ? sa_one($db, 'SELECT id,username,password_hash,status FROM echotech_super_admins WHERE username=? LIMIT 1', 's', [$username])
            : null;

        if (!$account || strcasecmp((string)$account['status'], 'Active') !== 0 || !password_verify($password, (string)$account['password_hash'])) {
            sa_log($db, 'SUPER_ADMIN_LOGIN_FAILED', 'super_admin', null, 'Failed Super Admin login attempt for username: ' . $username);
            sa_redirect('login', '', 'Invalid Super Admin credentials.');
        }

        session_regenerate_id(true);
        $_SESSION['super_admin_id'] = (int)$account['id'];
        $_SESSION['super_admin_csrf'] = bin2hex(random_bytes(32));
        sa_exec($db, 'UPDATE echotech_super_admins SET last_login=NOW(), last_activity=NOW() WHERE id=?', 'i', [(int)$account['id']]);
        sa_log($db, 'SUPER_ADMIN_LOGIN', 'super_admin', (int)$account['id'], 'Super Admin signed in.');
        sa_redirect('dashboard', 'Welcome to the EchoTech Platform Control Center.');
    }

    if ($action === 'logout') {
        sa_check_csrf();
        if ($superAdminId > 0) sa_log($db, 'SUPER_ADMIN_LOGOUT', 'super_admin', $superAdminId, 'Super Admin signed out.');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }
        session_destroy();
        header('Location: super_admin.php?tab=login');
        exit;
    }

    if ($superAdminId <= 0) {
        sa_redirect('login', '', 'Please sign in as Super Admin.');
    }

    sa_check_csrf();

    try {
        switch ($action) {
            case 'create_pharmacy':
                /*
                 * This intentionally mirrors the former register_pharmacy.php
                 * workflow: create the pharmacy, create its first branch, then
                 * create the linked Master Admin account in one transaction.
                 */
                $name = trim((string)($_POST['name'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $branchName = trim((string)($_POST['first_branch_name'] ?? ''));
                $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $plainPassword = (string)($_POST['password'] ?? '');

                if ($name === '' || $location === '' || $branchName === '' || $branchCode === '' || $username === '' || $email === '' || $plainPassword === '') {
                    throw new RuntimeException('Pharmacy name, location, first branch, branch code, Admin username, email and password are required.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid Admin email address.');
                }
                if (strlen($plainPassword) < 8) {
                    throw new RuntimeException('The Master Admin password must be at least 8 characters.');
                }

                $existingUsername = sa_one($db, 'SELECT id FROM users WHERE username=? LIMIT 1', 's', [$username]);
                if ($existingUsername) throw new RuntimeException('That Admin username is already registered.');
                $existingEmail = sa_one($db, 'SELECT id FROM users WHERE email=? LIMIT 1', 's', [$email]);
                if ($existingEmail) throw new RuntimeException('That Admin email is already registered.');

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare('INSERT INTO pharmacies (name,address) VALUES (?,?)');
                    if (!$stmt) throw new RuntimeException($db->error);
                    $stmt->bind_param('ss', $name, $location);
                    if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); throw new RuntimeException($e); }
                    $pharmacyId = (int)$stmt->insert_id;
                    $stmt->close();

                    $stmt = $db->prepare('INSERT INTO branches (pharmacy_id,branch_name,branch_code,location,is_active) VALUES (?,?,?,?,1)');
                    if (!$stmt) throw new RuntimeException($db->error);
                    $stmt->bind_param('isss', $pharmacyId, $branchName, $branchCode, $location);
                    if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); throw new RuntimeException($e); }
                    $branchId = (int)$stmt->insert_id;
                    $stmt->close();

                    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                    $role = 'Admin';
                    $status = 'Active';
                    $stmt = $db->prepare('INSERT INTO users (pharmacy_id,branch_id,username,password,email,role,status) VALUES (?,?,?,?,?,?,?)');
                    if (!$stmt) throw new RuntimeException($db->error);
                    $stmt->bind_param('iisssss', $pharmacyId, $branchId, $username, $hash, $email, $role, $status);
                    if (!$stmt->execute()) { $e = $stmt->error; $stmt->close(); throw new RuntimeException($e); }
                    $adminUserId = (int)$stmt->insert_id;
                    $stmt->close();

                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollback();
                    throw $e;
                }

                sa_log($db, 'SUPER_ADMIN_CREATE_PHARMACY', 'pharmacy', $pharmacyId, 'Created pharmacy ' . $name . ', first branch ' . $branchName . ', and Master Admin ' . $username . ' (user #' . $adminUserId . ').');
                sa_redirect('pharmacies', 'Pharmacy, first branch and Master Admin account created successfully.');

            case 'delete_pharmacy':
                $pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);
                $confirmName = trim((string)($_POST['confirm_name'] ?? ''));
                if ($pharmacyId <= 0) throw new RuntimeException('Invalid pharmacy selected.');

                $pharmacy = sa_one($db, 'SELECT id,name FROM pharmacies WHERE id=? LIMIT 1', 'i', [$pharmacyId]);
                if (!$pharmacy) throw new RuntimeException('Pharmacy not found.');
                if ($confirmName === '' || !hash_equals((string)$pharmacy['name'], $confirmName)) {
                    throw new RuntimeException('Deletion was not confirmed. Type the pharmacy name exactly to continue.');
                }

                /*
                 * A pharmacy deletion is intentionally destructive. All tenant
                 * records carrying pharmacy_id are removed, then branches and
                 * the pharmacy itself are removed. Foreign-key checks are
                 * disabled only for this connection while the tenant is being
                 * purged so legacy/non-cascading constraints cannot leave a
                 * half-deleted tenant.
                 */
                $db->begin_transaction();
                $fkDisabled = false;
                try {
                    $branchIds = [];
                    foreach (sa_rows($db, 'SELECT id FROM branches WHERE pharmacy_id=?', 'i', [$pharmacyId]) as $row) {
                        $branchIds[] = (int)$row['id'];
                    }

                    $tables = [];
                    $schemaResult = $db->query("SELECT DISTINCT c.TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS c INNER JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.COLUMN_NAME IN ('pharmacy_id','branch_id') AND c.TABLE_NAME NOT IN ('pharmacies','echotech_super_admins','echotech_super_admin_settings') ORDER BY c.TABLE_NAME");
                    if ($schemaResult) {
                        while ($row = $schemaResult->fetch_assoc()) $tables[] = (string)$row['TABLE_NAME'];
                        $schemaResult->free();
                    }

                    if (!$db->query('SET FOREIGN_KEY_CHECKS=0')) throw new RuntimeException($db->error);
                    $fkDisabled = true;

                    foreach ($tables as $table) {
                        $safeTable = '`' . str_replace('`', '``', $table) . '`';
                        $hasPharmacy = sa_one($db, "SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='pharmacy_id' LIMIT 1", 's', [$table]);
                        if ($hasPharmacy) {
                            if (!$db->query("DELETE FROM {$safeTable} WHERE pharmacy_id=" . $pharmacyId)) {
                                throw new RuntimeException('Failed deleting tenant data from ' . $table . ': ' . $db->error);
                            }
                        } elseif ($branchIds) {
                            $ids = implode(',', array_map('intval', $branchIds));
                            if (!$db->query("DELETE FROM {$safeTable} WHERE branch_id IN ({$ids})")) {
                                throw new RuntimeException('Failed deleting branch data from ' . $table . ': ' . $db->error);
                            }
                        }
                    }

                    if (!$db->query('DELETE FROM branches WHERE pharmacy_id=' . $pharmacyId)) {
                        throw new RuntimeException('Failed deleting pharmacy branches: ' . $db->error);
                    }
                    if (!$db->query('DELETE FROM pharmacies WHERE id=' . $pharmacyId)) {
                        throw new RuntimeException('Failed deleting pharmacy: ' . $db->error);
                    }

                    if (!$db->query('SET FOREIGN_KEY_CHECKS=1')) throw new RuntimeException($db->error);
                    $fkDisabled = false;
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollback();
                    if ($fkDisabled) @$db->query('SET FOREIGN_KEY_CHECKS=1');
                    throw $e;
                }

                sa_log($db, 'SUPER_ADMIN_DELETE_PHARMACY', 'pharmacy', $pharmacyId, 'Permanently deleted pharmacy ' . $pharmacy['name'] . ' and all tenant records, including its branches and staff accounts.');
                sa_redirect('pharmacies', 'Pharmacy and all of its branches and tenant data were permanently deleted.');

            case 'update_pharmacy':
                $pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                if ($pharmacyId <= 0 || $name === '') throw new RuntimeException('Valid pharmacy and name are required.');
                sa_exec($db, 'UPDATE pharmacies SET name=?,address=?,phone=? WHERE id=?', 'sssi', [$name,$address,$phone,$pharmacyId]);
                sa_log($db, 'SUPER_ADMIN_UPDATE_PHARMACY', 'pharmacy', $pharmacyId, 'Updated pharmacy profile.');
                sa_redirect('pharmacies', 'Pharmacy updated successfully.');

            case 'toggle_branch':
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $branch = sa_one($db, 'SELECT id,pharmacy_id,branch_name,is_active FROM branches WHERE id=? LIMIT 1', 'i', [$branchId]);
                if (!$branch) throw new RuntimeException('Branch not found.');
                $newStatus = ((int)$branch['is_active'] === 1) ? 0 : 1;
                if ($newStatus === 0) {
                    $active = (int)(sa_one($db, 'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1', 'i', [(int)$branch['pharmacy_id']])['c'] ?? 0);
                    if ($active <= 1) throw new RuntimeException('The only active branch cannot be deactivated. Activate another branch first.');
                }
                sa_exec($db, 'UPDATE branches SET is_active=? WHERE id=?', 'ii', [$newStatus,$branchId]);
                sa_log($db, 'SUPER_ADMIN_TOGGLE_BRANCH', 'branch', $branchId, ($newStatus ? 'Activated' : 'Deactivated') . ' branch ' . $branch['branch_name'] . '.');
                sa_redirect('branches', 'Branch status updated.');

            case 'toggle_user_freeze':
                $userId = (int)($_POST['user_id'] ?? 0);
                $user = sa_one($db, 'SELECT id,pharmacy_id,username,is_frozen FROM users WHERE id=? LIMIT 1', 'i', [$userId]);
                if (!$user) throw new RuntimeException('User not found.');
                $newFreeze = ((int)$user['is_frozen'] === 1) ? 0 : 1;
                sa_exec($db, 'UPDATE users SET is_frozen=? WHERE id=?', 'ii', [$newFreeze,$userId]);
                sa_log($db, 'SUPER_ADMIN_TOGGLE_USER_FREEZE', 'user', $userId, ($newFreeze ? 'Froze' : 'Unfroze') . ' user ' . $user['username'] . '.');
                sa_redirect('users', 'User security state updated.');

            case 'toggle_user_status':
                $userId = (int)($_POST['user_id'] ?? 0);
                $user = sa_one($db, 'SELECT id,username,status FROM users WHERE id=? LIMIT 1', 'i', [$userId]);
                if (!$user) throw new RuntimeException('User not found.');
                $newStatus = strcasecmp((string)$user['status'], 'Active') === 0 ? 'Inactive' : 'Active';
                sa_exec($db, 'UPDATE users SET status=? WHERE id=?', 'si', [$newStatus,$userId]);
                sa_log($db, 'SUPER_ADMIN_TOGGLE_USER_STATUS', 'user', $userId, 'Changed user ' . $user['username'] . ' status to ' . $newStatus . '.');
                sa_redirect('users', 'User status updated.');

            case 'reset_user_password':
                $userId = (int)($_POST['user_id'] ?? 0);
                $user = sa_one($db, 'SELECT id,username FROM users WHERE id=? LIMIT 1', 'i', [$userId]);
                if (!$user) throw new RuntimeException('User not found.');
                $temporaryPassword = sa_random_password(14);
                $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                sa_exec($db, 'UPDATE users SET password=?,reset_token=NULL,reset_expires=NULL WHERE id=?', 'si', [$hash,$userId]);
                sa_log($db, 'SUPER_ADMIN_RESET_USER_PASSWORD', 'user', $userId, 'Reset password for user ' . $user['username'] . '. Temporary password issued through Super Admin interface.');
                $_SESSION['super_admin_temp_password'] = $temporaryPassword;
                sa_redirect('users', 'Temporary password generated. Copy it now; it will not be shown again.');

            case 'create_user':
                $pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $mobile = trim((string)($_POST['mobile_number'] ?? ''));
                $role = trim((string)($_POST['role'] ?? 'Cashier'));
                $password = (string)($_POST['password'] ?? '');
                $allowedRoles = ['Admin','Pharmacist','Manager','User','Cashier','Human Resource'];
                if ($pharmacyId <= 0 || $branchId <= 0 || $username === '' || !in_array($role,$allowedRoles,true) || strlen($password) < 8) {
                    throw new RuntimeException('Pharmacy, branch, username, valid role and a password of at least 8 characters are required.');
                }
                $branch = sa_one($db, 'SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1', 'ii', [$branchId,$pharmacyId]);
                if (!$branch) throw new RuntimeException('Selected branch does not belong to the selected pharmacy.');
                $exists = sa_one($db, 'SELECT id FROM users WHERE username=? LIMIT 1', 's', [$username]);
                if ($exists) throw new RuntimeException('That username already exists.');
                $hash = password_hash($password,PASSWORD_DEFAULT);
                sa_exec($db, 'INSERT INTO users (pharmacy_id,username,email,password,full_name,mobile_number,role,branch_id,status,is_frozen) VALUES (?,?,?,?,?,?,?,?,\'Active\',0)', 'iisssssi', [$pharmacyId,$username,$email,$hash,$fullName,$mobile,$role,$branchId]);
                $newUser = (int)$db->insert_id;
                sa_log($db, 'SUPER_ADMIN_CREATE_USER', 'user', $newUser, 'Created staff user ' . $username . ' for pharmacy ID ' . $pharmacyId . '.');
                sa_redirect('users', 'Staff account created successfully.');

            case 'save_permissions':
                $pharmacyId = (int)($_POST['permission_pharmacy_id'] ?? 0);
                $roles = sa_rows($db, 'SELECT DISTINCT role FROM role_page_permissions WHERE pharmacy_id=? ORDER BY role', 'i', [$pharmacyId]);
                $pages = sa_rows($db, 'SELECT DISTINCT page_name FROM role_page_permissions WHERE pharmacy_id=? ORDER BY page_name', 'i', [$pharmacyId]);
                if (!$roles || !$pages) throw new RuntimeException('No permission matrix exists for this pharmacy yet.');
                $db->begin_transaction();
                foreach ($roles as $r) {
                    $roleName = (string)$r['role'];
                    foreach ($pages as $p) {
                        $pageName = (string)$p['page_name'];
                        $rk = preg_replace('/[^a-zA-Z0-9_-]/','_',$roleName);
                        $pk = preg_replace('/[^a-zA-Z0-9_-]/','_',$pageName);
                        $access = isset($_POST['access'][$rk][$pk]) ? 1 : 0;
                        $canAction = isset($_POST['action_perm'][$rk][$pk]) ? 1 : 0;
                        sa_exec($db,
                            'INSERT INTO role_page_permissions (pharmacy_id,role,page_name,can_access,can_action) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE can_access=VALUES(can_access),can_action=VALUES(can_action)',
                            'issii', [$pharmacyId,$roleName,$pageName,$access,$canAction]
                        );
                    }
                }
                $db->commit();
                sa_log($db, 'SUPER_ADMIN_UPDATE_PERMISSIONS', 'role_page_permissions', null, 'Updated permission matrix for pharmacy ID ' . $pharmacyId . '.');
                sa_redirect('permissions', 'Permission matrix saved successfully.');

            case 'create_super_admin':
                $username = trim((string)($_POST['sa_username'] ?? ''));
                $email = trim((string)($_POST['sa_email'] ?? ''));
                $fullName = trim((string)($_POST['sa_full_name'] ?? ''));
                $password = (string)($_POST['sa_password'] ?? '');
                if ($username === '' || strlen($password) < 12) throw new RuntimeException('Username is required and the Super Admin password must be at least 12 characters.');
                if (sa_one($db, 'SELECT id FROM echotech_super_admins WHERE username=? LIMIT 1', 's', [$username])) throw new RuntimeException('That Super Admin username already exists.');
                $hash = password_hash($password,PASSWORD_DEFAULT);
                sa_exec($db, 'INSERT INTO echotech_super_admins (username,email,password_hash,full_name,status) VALUES (?,?,?,?,\'Active\')', 'ssss', [$username,$email,$hash,$fullName]);
                $newId = (int)$db->insert_id;
                sa_log($db, 'SUPER_ADMIN_CREATE_ACCOUNT', 'super_admin', $newId, 'Created Super Admin account ' . $username . '.');
                sa_redirect('security', 'Super Admin account created.');

            case 'toggle_super_admin':
                $id = (int)($_POST['sa_id'] ?? 0);
                if ($id === $superAdminId) throw new RuntimeException('You cannot disable the Super Admin account you are currently using.');
                $row = sa_one($db, 'SELECT id,username,status FROM echotech_super_admins WHERE id=? LIMIT 1', 'i', [$id]);
                if (!$row) throw new RuntimeException('Super Admin account not found.');
                $newStatus = strcasecmp((string)$row['status'],'Active') === 0 ? 'Inactive' : 'Active';
                sa_exec($db,'UPDATE echotech_super_admins SET status=? WHERE id=?','si',[$newStatus,$id]);
                sa_log($db,'SUPER_ADMIN_TOGGLE_ACCOUNT','super_admin',$id,'Changed Super Admin '.$row['username'].' to '.$newStatus.'.');
                sa_redirect('security','Super Admin account status updated.');

            default:
                throw new RuntimeException('Unknown Super Admin action.');
        }
    } catch (Throwable $e) {
        if ($db->errno || $db->connect_errno) {
            @$db->rollback();
        }
        sa_redirect((string)($_POST['return_tab'] ?? 'dashboard'), '', $e->getMessage());
    }
}

/* ---------------------------------------------------------------
 * Login screen if no authenticated Super Admin.
 * ------------------------------------------------------------- */
if ($superAdminId <= 0) {
    $csrf = sa_csrf();
    $hasAdmin = (int)(sa_one($db, 'SELECT COUNT(*) AS c FROM echotech_super_admins WHERE status=\'Active\'')['c'] ?? 0) > 0;
    $setupUrl = 'super_admin_bootstrap.php';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EchoTech Super Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#0b1220;color:#e8edf5;min-height:100vh;display:grid;place-items:center;padding:24px}.login{width:min(430px,100%);background:#121b2b;border:1px solid #26334a;border-radius:18px;padding:30px;box-shadow:0 24px 70px rgba(0,0,0,.35)}.brand{font-weight:800;font-size:23px;margin-bottom:5px}.sub{color:#91a0b5;font-size:13px;margin-bottom:25px}.field{margin-bottom:15px}.field label{display:block;font-size:12px;color:#aeb9c9;margin-bottom:7px}.field input{width:100%;padding:12px 13px;border:1px solid #34425a;border-radius:10px;background:#0d1523;color:#fff;outline:none}.field input:focus{border-color:#3478ff}.btn{width:100%;border:0;border-radius:10px;padding:12px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}.notice{padding:11px 12px;border-radius:10px;background:#10233e;color:#9fc2ff;font-size:13px;margin-bottom:15px}.error{padding:11px 12px;border-radius:10px;background:#3a1820;color:#ffb7c2;font-size:13px;margin-bottom:15px}.setup{margin-top:18px;text-align:center;font-size:12px;color:#8d9aaf}.setup a{color:#75a7ff;text-decoration:none}
</style></head><body><section class="login"><div class="brand">EchoTech Platform Control Center</div><div class="sub">Super Admin authentication Â· 24-hour rolling inactivity session</div>
<?php if ($error !== ''): ?><div class="error"><?=sa_h($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?=sa_h($notice)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><div class="field"><label>Username</label><input name="username" autocomplete="username" required></div><div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn">Sign in as Super Admin</button></form>
<?php if (!$hasAdmin): ?><div class="setup">No Super Admin account exists yet. Use the <a href="<?=sa_h($setupUrl)?>">one-time bootstrap setup</a>.</div><?php endif; ?></section></body></html>
<?php
    exit;
}

/* ---------------------------------------------------------------
 * Data for the authenticated control center.
 * ------------------------------------------------------------- */
$tab = (string)($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard','pharmacies','branches','users','permissions','audit','security','health'];
if (!in_array($tab,$allowedTabs,true)) $tab = 'dashboard';

$csrf = sa_csrf();
$tempPassword = (string)($_SESSION['super_admin_temp_password'] ?? '');
unset($_SESSION['super_admin_temp_password']);

$stats = sa_one($db, 'SELECT
    (SELECT COUNT(*) FROM pharmacies) AS pharmacies,
    (SELECT COUNT(*) FROM branches) AS branches,
    (SELECT COUNT(*) FROM branches WHERE is_active=1) AS active_branches,
    (SELECT COUNT(*) FROM users) AS users,
    (SELECT COUNT(*) FROM users WHERE status=\'Active\') AS active_users,
    (SELECT COUNT(*) FROM users WHERE is_frozen=1) AS frozen_users,
    (SELECT COUNT(*) FROM sales) AS sales_count,
    (SELECT COALESCE(SUM(COALESCE(NULLIF(total_amount,0),total)),0) FROM sales) AS revenue');

$pharmacies = sa_rows($db, 'SELECT p.id,p.name,p.address,p.phone,p.created_at,
    (SELECT COUNT(*) FROM branches b WHERE b.pharmacy_id=p.id) AS branch_count,
    (SELECT COUNT(*) FROM branches b WHERE b.pharmacy_id=p.id AND b.is_active=1) AS active_branch_count,
    (SELECT COUNT(*) FROM users u WHERE u.pharmacy_id=p.id) AS user_count,
    (SELECT COUNT(*) FROM users u WHERE u.pharmacy_id=p.id AND u.is_frozen=1) AS frozen_count,
    (SELECT COUNT(*) FROM sales s WHERE s.pharmacy_id=p.id) AS sales_count,
    (SELECT COALESCE(SUM(COALESCE(NULLIF(s.total_amount,0),s.total)),0) FROM sales s WHERE s.pharmacy_id=p.id) AS revenue
    FROM pharmacies p ORDER BY p.id DESC');

$branches = sa_rows($db, 'SELECT b.id,b.pharmacy_id,b.branch_code,b.branch_name,b.location,b.phone,b.is_active,p.name AS pharmacy_name,
    (SELECT COUNT(*) FROM users u WHERE u.branch_id=b.id) AS user_count,
    (SELECT COUNT(*) FROM store_items si WHERE si.branch_id=b.id) AS product_count,
    (SELECT COUNT(*) FROM sales s WHERE s.branch_id=b.id) AS sales_count
    FROM branches b INNER JOIN pharmacies p ON p.id=b.pharmacy_id ORDER BY p.name ASC,b.branch_name ASC');

$userSearch = trim((string)($_GET['user_q'] ?? ''));
$userPharmacy = (int)($_GET['user_pharmacy'] ?? 0);
$userWhere = [];$userTypes='';$userValues=[];
if ($userSearch !== '') { $userWhere[]='(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.mobile_number LIKE ?)'; $like='%'.$userSearch.'%'; $userTypes.='ssss'; array_push($userValues,$like,$like,$like,$like); }
if ($userPharmacy > 0) { $userWhere[]='u.pharmacy_id=?'; $userTypes.='i'; $userValues[]=$userPharmacy; }
$userSql = 'SELECT u.id,u.pharmacy_id,u.username,u.email,u.full_name,u.mobile_number,u.role,u.branch_id,u.status,u.is_frozen,b.branch_name,p.name AS pharmacy_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id AND b.pharmacy_id=u.pharmacy_id LEFT JOIN pharmacies p ON p.id=u.pharmacy_id';
if ($userWhere) $userSql .= ' WHERE '.implode(' AND ',$userWhere);
$userSql .= ' ORDER BY u.id DESC LIMIT 500';
$users = sa_rows($db,$userSql,$userTypes,$userValues);

$selectedPermissionPharmacy = (int)($_GET['permission_pharmacy_id'] ?? ($pharmacies[0]['id'] ?? 0));
$permissionRoles = $selectedPermissionPharmacy > 0 ? sa_rows($db,'SELECT DISTINCT role FROM role_page_permissions WHERE pharmacy_id=? ORDER BY role','i',[$selectedPermissionPharmacy]) : [];
$permissionPages = $selectedPermissionPharmacy > 0 ? sa_rows($db,'SELECT DISTINCT page_name FROM role_page_permissions WHERE pharmacy_id=? ORDER BY page_name','i',[$selectedPermissionPharmacy]) : [];
$permissionMap=[];
if ($selectedPermissionPharmacy > 0) {
    foreach (sa_rows($db,'SELECT role,page_name,can_access,can_action FROM role_page_permissions WHERE pharmacy_id=?','i',[$selectedPermissionPharmacy]) as $r) {
        $permissionMap[(string)$r['role']][(string)$r['page_name']] = [(int)$r['can_access'],(int)$r['can_action']];
    }
}

$auditSearch = trim((string)($_GET['audit_q'] ?? ''));
$auditPharmacy = (int)($_GET['audit_pharmacy'] ?? 0);
$auditWhere = [];$auditTypes='';$auditValues=[];
if ($auditPharmacy > 0) { $auditWhere[]='l.pharmacy_id=?';$auditTypes.='i';$auditValues[]=$auditPharmacy; }
if ($auditSearch !== '') { $auditWhere[]='(l.action LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? OR l.ip_address LIKE ?)';$like='%'.$auditSearch.'%';$auditTypes.='ssss';array_push($auditValues,$like,$like,$like,$like); }
$auditSql='SELECT l.id,l.pharmacy_id,l.user_id,l.action,l.entity_type,l.entity_id,l.description,l.ip_address,l.created_at,p.name AS pharmacy_name,u.username FROM compliance_audit_log l LEFT JOIN pharmacies p ON p.id=l.pharmacy_id LEFT JOIN users u ON u.id=l.user_id';
if ($auditWhere) $auditSql.=' WHERE '.implode(' AND ',$auditWhere);
$auditSql.=' ORDER BY l.created_at DESC,l.id DESC LIMIT '.SA_MAX_AUDIT_ROWS;
$audits = sa_table_exists($db,'compliance_audit_log') ? sa_rows($db,$auditSql,$auditTypes,$auditValues) : [];

$superAdmins = sa_rows($db,'SELECT id,username,email,full_name,status,last_login,last_activity,created_at FROM echotech_super_admins ORDER BY id ASC');

$healthTables = ['pharmacies','branches','users','sales','sales_items','store_items','clients_orders','customers','expenses','purchase_orders','compliance_audit_log','pos_zra_audit_log','role_page_permissions'];
$health=[];
foreach ($healthTables as $table) {
    $exists=sa_table_exists($db,$table);$count=null;
    if ($exists) { $row=sa_one($db,'SELECT COUNT(*) AS c FROM `'.$table.'`');$count=(int)($row['c']??0); }
    $health[]=['table'=>$table,'exists'=>$exists,'count'=>$count];
}

function sa_nav_active(string $name,string $tab): string { return $name===$tab?'active':''; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EchoTech Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--navy:#111827;--navy2:#172033;--blue:#2563eb;--blue2:#1d4ed8;--bg:#f4f6f9;--card:#fff;--text:#17202a;--muted:#6b7280;--line:#e5e7eb;--green:#138a5b;--red:#c43d51;--amber:#a86e00;--shadow:0 8px 24px rgba(15,23,42,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.layout{min-height:100vh}.sidebar{position:fixed;left:0;top:0;bottom:0;width:250px;background:var(--navy);color:#d8e0eb;padding:18px 13px;z-index:20;overflow:auto}.brand{padding:8px 11px 22px}.brand strong{display:block;font-size:18px;color:#fff}.brand span{font-size:11px;color:#8fa0b6}.nav-title{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#6f8097;padding:13px 11px 7px}.nav a{display:flex;gap:10px;align-items:center;color:#aebbd0;text-decoration:none;padding:10px 11px;border-radius:9px;font-size:13px;margin:2px 0}.nav a:hover,.nav a.active{background:#24334b;color:#fff}.nav i{width:18px;text-align:center;font-style:normal}.side-bottom{border-top:1px solid #29364b;margin-top:18px;padding-top:14px}.main{margin-left:250px;min-height:100vh}.top{height:64px;background:#000;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:15;border-bottom:1px solid #171717}.top .crumb{font-size:13px;color:#aab3be}.top .crumb b{color:#fff}.top .right{display:flex;align-items:center;gap:10px;font-size:12px}.pill{padding:6px 9px;border-radius:999px;background:#111;border:1px solid #363636;color:#cbd5e1}.content{padding:24px}.titlebar{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:20px}.titlebar h1{font-size:25px;margin:0 0 5px}.titlebar p{margin:0;color:var(--muted);font-size:13px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stat{background:var(--card);border:1px solid var(--line);border-radius:13px;padding:18px;box-shadow:var(--shadow)}.stat .label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted)}.stat .value{font-size:26px;font-weight:800;margin-top:7px}.stat .meta{font-size:11px;color:var(--muted);margin-top:5px}.section{background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:var(--shadow);margin-top:16px;overflow:hidden}.section-head{padding:15px 17px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;align-items:center}.section-head h2{font-size:15px;margin:0}.section-body{padding:17px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:11px 10px;border-bottom:1px solid #eef0f3;font-size:12px;vertical-align:middle}th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#697586;background:#fafbfc}tr:last-child td{border-bottom:0}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:700}.green{background:#e8f7f0;color:var(--green)}.red{background:#fff0f2;color:var(--red)}.amber{background:#fff6df;color:var(--amber)}.blue{background:#eaf1ff;color:var(--blue2)}.muted{color:var(--muted)}.actions{display:flex;gap:6px;flex-wrap:wrap}.btn{border:1px solid var(--line);background:#fff;color:#263244;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn:hover{border-color:#c7d2e1;background:#f8fafc}.btn-primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn-primary:hover{background:var(--blue2);border-color:var(--blue2)}.btn-danger{background:#fff4f5;border-color:#f2c8cf;color:var(--red)}.btn-dark{background:#111827;border-color:#111827;color:#fff}.btn-green{background:#edf9f3;border-color:#c6ead8;color:#10734c}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.field label{display:block;font-size:11px;font-weight:700;color:#5f6b7a;margin-bottom:6px}.field input,.field select,.field textarea{width:100%;border:1px solid #d8dee7;border-radius:9px;padding:10px 11px;background:#fff;color:#17202a;font:inherit;font-size:12px;outline:none}.field input:focus,.field select:focus,.field textarea:focus{border-color:#70a0ff;box-shadow:0 0 0 3px rgba(37,99,235,.08)}.field textarea{min-height:80px;resize:vertical}.full{grid-column:1/-1}.notice{padding:11px 13px;background:#eaf1ff;color:#1d4ed8;border:1px solid #cddcff;border-radius:9px;font-size:12px;margin-bottom:15px}.error{padding:11px 13px;background:#fff0f2;color:#b4233c;border:1px solid #f2c8cf;border-radius:9px;font-size:12px;margin-bottom:15px}.tabs{display:flex;gap:5px;overflow:auto;border-bottom:1px solid var(--line);margin-bottom:16px}.tabs a{white-space:nowrap;text-decoration:none;color:#687386;padding:10px 12px;font-size:12px;font-weight:700;border-bottom:2px solid transparent}.tabs a.active{color:var(--blue);border-color:var(--blue)}.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.filters input,.filters select{padding:9px 10px;border:1px solid #d8dee7;border-radius:8px;font-size:12px;background:#fff}.kpi-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.mini{padding:13px;border:1px solid var(--line);border-radius:10px;background:#fafbfc}.mini strong{display:block;font-size:18px}.mini span{font-size:11px;color:var(--muted)}.temp{background:#102b20;border:1px solid #216746;color:#b7f0d2;border-radius:10px;padding:13px;margin-bottom:15px;font-size:12px}.temp code{font-size:14px;font-weight:800}.permission-table th,.permission-table td{text-align:center}.permission-table th:first-child,.permission-table td:first-child{text-align:left;min-width:220px}.check{width:16px;height:16px}.health-ok{color:var(--green);font-weight:800}.health-bad{color:var(--red);font-weight:800}.danger-note{font-size:11px;color:#8b3b49;margin-top:8px}.mobile-menu{display:none}
@media(max-width:1000px){.grid4{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid.three{grid-template-columns:1fr}.sidebar{width:220px}.main{margin-left:220px}}
@media(max-width:760px){.sidebar{display:none}.main{margin-left:0}.mobile-menu{display:block}.content{padding:15px}.top{padding:0 15px}.grid4{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}.titlebar{flex-direction:column}.kpi-row{grid-template-columns:1fr}.top .crumb{display:none}}
@media(max-width:500px){.grid4{grid-template-columns:1fr}.section-body{padding:13px}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand"><strong>EchoTech</strong><span>Platform Super Admin</span></div>
    <nav class="nav">
        <div class="nav-title">Control Center</div>
        <a class="<?=sa_nav_active('dashboard',$tab)?>" href="?tab=dashboard"><i>âŒ‚</i>Dashboard</a>
        <a class="<?=sa_nav_active('pharmacies',$tab)?>" href="?tab=pharmacies"><i>â–£</i>Pharmacies</a>
        <a class="<?=sa_nav_active('branches',$tab)?>" href="?tab=branches"><i>âŒ˜</i>Branches</a>
        <a class="<?=sa_nav_active('users',$tab)?>" href="?tab=users"><i>â™™</i>Staff & Users</a>
        <a class="<?=sa_nav_active('permissions',$tab)?>" href="?tab=permissions"><i>â˜·</i>Permissions</a>
        <a class="<?=sa_nav_active('audit',$tab)?>" href="?tab=audit"><i>â—Œ</i>Audit & Security</a>
        <a class="<?=sa_nav_active('security',$tab)?>" href="?tab=security"><i>âš¿</i>Super Admins</a>
        <a class="<?=sa_nav_active('health',$tab)?>" href="?tab=health"><i>â™¥</i>System Health</a>
    </nav>
    <div class="side-bottom"><div class="nav-title">Session</div>
        <div style="padding:10px 11px;font-size:12px;color:#dbe5f2"><?=sa_h($super['full_name'] ?: $super['username'])?></div>
        <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn" style="width:100%;background:#1e293b;border-color:#34425a;color:#fff">Logout</button></form>
    </div>
</aside>
<main class="main">
<header class="top"><div class="crumb"><b>Super Admin</b> <span> / Platform Control Center</span></div><div class="right"><span class="pill">24h rolling session</span><span class="pill"><?=sa_h($super['username'])?></span></div></header>
<div class="content">
<?php if ($notice !== ''): ?><div class="notice"><?=sa_h($notice)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?=sa_h($error)?></div><?php endif; ?>

<?php if ($tab === 'dashboard'): ?>
<div class="titlebar"><div><h1>Platform Dashboard</h1><p>Global visibility across every pharmacy, branch, staff account and transaction.</p></div></div>
<div class="grid4">
<div class="stat"><div class="label">Pharmacies</div><div class="value"><?=number_format((int)$stats['pharmacies'])?></div><div class="meta">Registered tenants</div></div>
<div class="stat"><div class="label">Branches</div><div class="value"><?=number_format((int)$stats['active_branches'])?> / <?=number_format((int)$stats['branches'])?></div><div class="meta">Active / total branches</div></div>
<div class="stat"><div class="label">Staff Users</div><div class="value"><?=number_format((int)$stats['users'])?></div><div class="meta"><?=number_format((int)$stats['active_users'])?> active Â· <?=number_format((int)$stats['frozen_users'])?> frozen</div></div>
<div class="stat"><div class="label">Recorded Revenue</div><div class="value">K<?=number_format((float)$stats['revenue'],2)?></div><div class="meta"><?=number_format((int)$stats['sales_count'])?> sales</div></div>
</div>
<div class="section"><div class="section-head"><h2>Tenant overview</h2><a class="btn btn-primary" href="?tab=pharmacies">Manage pharmacies</a></div><div class="section-body"><div class="table-wrap"><table><thead><tr><th>Pharmacy</th><th>Branches</th><th>Users</th><th>Sales</th><th>Revenue</th><th>Created</th></tr></thead><tbody><?php foreach (array_slice($pharmacies,0,10) as $p): ?><tr><td><strong><?=sa_h($p['name'])?></strong><br><span class="muted"><?=sa_h($p['address'] ?: 'No address')?></span></td><td><?=sa_h($p['active_branch_count'])?> / <?=sa_h($p['branch_count'])?></td><td><?=sa_h($p['user_count'])?></td><td><?=sa_h($p['sales_count'])?></td><td>K<?=number_format((float)$p['revenue'],2)?></td><td><?=sa_h($p['created_at'])?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php endif; ?>

<?php if ($tab === 'pharmacies'): ?>
<div class="titlebar"><div><h1>Pharmacy / Tenant Management</h1><p>Create tenants, update tenant profiles and inspect their platform footprint.</p></div></div>
<div class="section"><div class="section-head"><h2>Create new pharmacy + Master Admin</h2><span class="badge blue">Same registration flow</span></div><div class="section-body"><form method="post"><input type="hidden" name="action" value="create_pharmacy"><input type="hidden" name="return_tab" value="pharmacies"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><div class="section-title" style="margin-bottom:10px">1. Brand Identity</div><div class="form-grid three"><div class="field"><label>Corporate / Pharmacy Name *</label><input name="name" required></div><div class="field"><label>Headquarters Location *</label><input name="location" required></div></div><div class="section-title" style="margin:18px 0 10px">2. Initial Branch Configuration</div><div class="form-grid three"><div class="field"><label>Branch Display Name *</label><input name="first_branch_name" required></div><div class="field"><label>Branch Code *</label><input name="branch_code" required></div></div><div class="section-title" style="margin:18px 0 10px">3. Master Admin Account</div><div class="form-grid three"><div class="field"><label>Admin Username *</label><input name="username" required></div><div class="field"><label>Business Email *</label><input name="email" type="email" required></div><div class="field"><label>Secure Password *</label><input name="password" type="password" minlength="8" required></div></div><div style="margin-top:14px"><button class="btn btn-primary">Create Pharmacy + First Branch + Master Admin</button></div></form></div></div>
<div class="section"><div class="section-head"><h2>All pharmacies</h2><span class="badge blue"><?=count($pharmacies)?> tenants</span></div><div class="section-body"><div class="table-wrap"><table><thead><tr><th>ID</th><th>Pharmacy</th><th>Branches</th><th>Users</th><th>Sales</th><th>Revenue</th><th>Action</th></tr></thead><tbody><?php foreach($pharmacies as $p): ?><tr><td>#<?=sa_h($p['id'])?></td><td><strong><?=sa_h($p['name'])?></strong><br><span class="muted"><?=sa_h($p['address'] ?: 'â€”')?> Â· <?=sa_h($p['phone'] ?: 'â€”')?></span></td><td><?=sa_h($p['active_branch_count'])?> active / <?=sa_h($p['branch_count'])?></td><td><?=sa_h($p['user_count'])?></td><td><?=sa_h($p['sales_count'])?></td><td>K<?=number_format((float)$p['revenue'],2)?></td><td><div class="actions"><button class="btn" type="button" onclick="document.getElementById('edit<?=sa_h($p['id'])?>').showModal()">Edit</button><button class="btn btn-danger" type="button" onclick="document.getElementById('delete<?=sa_h($p['id'])?>').showModal()">Delete</button></div></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php foreach($pharmacies as $p): ?><dialog id="edit<?=sa_h($p['id'])?>" style="border:0;border-radius:14px;padding:0;max-width:560px;width:calc(100% - 30px)"><form method="post" style="padding:20px"><input type="hidden" name="action" value="update_pharmacy"><input type="hidden" name="return_tab" value="pharmacies"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><input type="hidden" name="pharmacy_id" value="<?=sa_h($p['id'])?>"><h3>Edit <?=sa_h($p['name'])?></h3><div class="form-grid"><div class="field full"><label>Name</label><input name="name" value="<?=sa_h($p['name'])?>" required></div><div class="field"><label>Phone</label><input name="phone" value="<?=sa_h($p['phone'])?>"></div><div class="field"><label>Address</label><input name="address" value="<?=sa_h($p['address'])?>"></div></div><div class="actions" style="margin-top:14px"><button class="btn btn-primary">Save</button><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button></div></form></dialog><dialog id="delete<?=sa_h($p['id'])?>" style="border:0;border-radius:14px;padding:0;max-width:560px;width:calc(100% - 30px)"><form method="post" style="padding:20px"><input type="hidden" name="action" value="delete_pharmacy"><input type="hidden" name="return_tab" value="pharmacies"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><input type="hidden" name="pharmacy_id" value="<?=sa_h($p['id'])?>"><h3 style="color:#c62828">Permanently delete <?=sa_h($p['name'])?>?</h3><p class="muted">This permanently removes the pharmacy, all branches, staff accounts, products, sales, orders, payroll, compliance records and other tenant data. This cannot be undone.</p><div class="field"><label>Type the pharmacy name to confirm</label><input name="confirm_name" autocomplete="off" required></div><div class="actions" style="margin-top:14px"><button class="btn btn-danger">Permanently Delete Pharmacy</button><button class="btn" type="button" onclick="this.closest('dialog').close()">Cancel</button></div></form></dialog><?php endforeach; ?>
<?php endif; ?>

<?php if ($tab === 'branches'): ?>
<div class="titlebar"><div><h1>Global Branch Control</h1><p>Every branch across every tenant, with active/inactive controls.</p></div></div>
<div class="section"><div class="section-body"><div class="filters"><input id="branchFilter" placeholder="Search branch or pharmacy..."></div><div class="table-wrap"><table id="branchesTable"><thead><tr><th>Pharmacy</th><th>Branch</th><th>Code</th><th>Location</th><th>Users</th><th>Products</th><th>Sales</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($branches as $b): ?><tr><td><?=sa_h($b['pharmacy_name'])?></td><td><strong><?=sa_h($b['branch_name'])?></strong></td><td><?=sa_h($b['branch_code'] ?: 'â€”')?></td><td><?=sa_h($b['location'] ?: 'â€”')?></td><td><?=sa_h($b['user_count'])?></td><td><?=sa_h($b['product_count'])?></td><td><?=sa_h($b['sales_count'])?></td><td><span class="badge <?=$b['is_active']?'green':'red'?>"><?=$b['is_active']?'Active':'Inactive'?></span></td><td><form method="post" onsubmit="return confirm('Change this branch status?')"><input type="hidden" name="action" value="toggle_branch"><input type="hidden" name="return_tab" value="branches"><input type="hidden" name="branch_id" value="<?=sa_h($b['id'])?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn <?=$b['is_active']?'btn-danger':'btn-green'?>"><?=$b['is_active']?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<script>document.getElementById('branchFilter')?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('#branchesTable tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q)?'':'none')});</script>
<?php endif; ?>

<?php if ($tab === 'users'): ?>
<div class="titlebar"><div><h1>Global Staff & User Management</h1><p>Manage accounts across all pharmacies without changing the existing pharmacy role model.</p></div></div>
<?php if($tempPassword!==''): ?><div class="temp"><strong>Temporary password â€” copy it now:</strong> <code><?=sa_h($tempPassword)?></code><div style="margin-top:5px">This value is displayed once by the Super Admin interface.</div></div><?php endif; ?>
<div class="section"><div class="section-head"><h2>Create staff account</h2></div><div class="section-body"><form method="post"><input type="hidden" name="action" value="create_user"><input type="hidden" name="return_tab" value="users"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><div class="form-grid three"><div class="field"><label>Pharmacy *</label><select name="pharmacy_id" id="newUserPharmacy" required><option value="">Select pharmacy</option><?php foreach($pharmacies as $p): ?><option value="<?=sa_h($p['id'])?>"><?=sa_h($p['name'])?></option><?php endforeach; ?></select></div><div class="field"><label>Branch *</label><select name="branch_id" id="newUserBranch" required><option value="">Select pharmacy first</option></select></div><div class="field"><label>Role *</label><select name="role"><option>Admin</option><option>Pharmacist</option><option>Manager</option><option>User</option><option selected>Cashier</option><option>Human Resource</option></select></div><div class="field"><label>Username *</label><input name="username" required></div><div class="field"><label>Email</label><input type="email" name="email"></div><div class="field"><label>Full name</label><input name="full_name"></div><div class="field"><label>Mobile</label><input name="mobile_number"></div><div class="field"><label>Password *</label><input type="password" name="password" minlength="8" required></div></div><div style="margin-top:12px"><button class="btn btn-primary">Create staff account</button></div></form></div></div>
<div class="section"><div class="section-head"><h2>All staff accounts</h2></div><div class="section-body"><form class="filters" method="get"><input type="hidden" name="tab" value="users"><input name="user_q" placeholder="Search username, name, email, phone..." value="<?=sa_h($userSearch)?>"><select name="user_pharmacy"><option value="0">All pharmacies</option><?php foreach($pharmacies as $p): ?><option value="<?=sa_h($p['id'])?>" <?=$userPharmacy==(int)$p['id']?'selected':''?>><?=sa_h($p['name'])?></option><?php endforeach; ?></select><button class="btn btn-primary">Filter</button><a class="btn" href="?tab=users">Reset</a></form><div class="table-wrap"><table><thead><tr><th>User</th><th>Pharmacy</th><th>Branch</th><th>Role</th><th>Status</th><th>Security</th><th>Actions</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?=sa_h($u['username'])?></strong><br><span class="muted"><?=sa_h($u['full_name'] ?: $u['email'] ?: 'â€”')?></span></td><td><?=sa_h($u['pharmacy_name'] ?: 'â€”')?></td><td><?=sa_h($u['branch_name'] ?: 'â€”')?></td><td><span class="badge blue"><?=sa_h($u['role'])?></span></td><td><span class="badge <?=$u['status']==='Active'?'green':'red'?>"><?=sa_h($u['status'])?></span></td><td><?=$u['is_frozen']?'<span class="badge red">Frozen</span>':'<span class="badge green">Normal</span>'?></td><td><div class="actions"><form method="post"><input type="hidden" name="action" value="toggle_user_freeze"><input type="hidden" name="return_tab" value="users"><input type="hidden" name="user_id" value="<?=sa_h($u['id'])?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn" onclick="return confirm('Change freeze state for this account?')"><?=$u['is_frozen']?'Unfreeze':'Freeze'?></button></form><form method="post"><input type="hidden" name="action" value="toggle_user_status"><input type="hidden" name="return_tab" value="users"><input type="hidden" name="user_id" value="<?=sa_h($u['id'])?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn"><?=$u['status']==='Active'?'Deactivate':'Activate'?></button></form><form method="post" onsubmit="return confirm('Generate a new temporary password for this user?')"><input type="hidden" name="action" value="reset_user_password"><input type="hidden" name="return_tab" value="users"><input type="hidden" name="user_id" value="<?=sa_h($u['id'])?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn btn-primary">Reset password</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<script>
const allBranches=<?=json_encode(array_map(static fn($b)=>['id'=>(int)$b['id'],'pharmacy_id'=>(int)$b['pharmacy_id'],'name'=>(string)$b['branch_name']],$branches),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const np=document.getElementById('newUserPharmacy'),nb=document.getElementById('newUserBranch');
function fillBranches(){if(!np||!nb)return;const id=Number(np.value);nb.innerHTML='<option value="">Select branch</option>';allBranches.filter(b=>b.pharmacy_id===id).forEach(b=>{const o=document.createElement('option');o.value=b.id;o.textContent=b.name;nb.appendChild(o)})}np?.addEventListener('change',fillBranches);
</script>
<?php endif; ?>

<?php if ($tab === 'permissions'): ?>
<div class="titlebar"><div><h1>Tenant Permission Control</h1><p>Edit the existing role/page access matrix for any pharmacy.</p></div></div>
<div class="section"><div class="section-body"><form method="get" class="filters"><input type="hidden" name="tab" value="permissions"><select name="permission_pharmacy_id"><?php foreach($pharmacies as $p): ?><option value="<?=sa_h($p['id'])?>" <?=$selectedPermissionPharmacy==(int)$p['id']?'selected':''?>><?=sa_h($p['name'])?></option><?php endforeach; ?></select><button class="btn btn-primary">Load matrix</button></form>
<?php if(!$permissionRoles||!$permissionPages): ?><div class="notice">This pharmacy has no existing role/page permission records. The Super Admin page does not invent page names; create/populate the matrix through the existing Admin Settings workflow first.</div><?php else: ?><form method="post"><input type="hidden" name="action" value="save_permissions"><input type="hidden" name="return_tab" value="permissions"><input type="hidden" name="permission_pharmacy_id" value="<?=sa_h($selectedPermissionPharmacy)?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><div class="table-wrap"><table class="permission-table"><thead><tr><th>Page</th><?php foreach($permissionRoles as $r): ?><th><?=sa_h($r['role'])?><br><small>Access / Action</small></th><?php endforeach; ?></tr></thead><tbody><?php foreach($permissionPages as $p): $page=(string)$p['page_name'];$pk=preg_replace('/[^a-zA-Z0-9_-]/','_',$page); ?><tr><td><strong><?=sa_h($page)?></strong></td><?php foreach($permissionRoles as $r): $roleName=(string)$r['role'];$rk=preg_replace('/[^a-zA-Z0-9_-]/','_',$roleName);$vals=$permissionMap[$roleName][$page]??[0,0]; ?><td><input class="check" type="checkbox" name="access[<?=sa_h($rk)?>][<?=sa_h($pk)?>]" <?=$vals[0]?'checked':''?> title="Can access"><br><input class="check" type="checkbox" name="action_perm[<?=sa_h($rk)?>][<?=sa_h($pk)?>]" <?=$vals[1]?'checked':''?> title="Can action"></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><div style="margin-top:14px"><button class="btn btn-primary">Save permission matrix</button></div></form><?php endif; ?></div></div>
<?php endif; ?>

<?php if ($tab === 'audit'): ?>
<div class="titlebar"><div><h1>Global Audit & Security</h1><p>Read-only platform audit history from the existing compliance audit table.</p></div></div>
<div class="section"><div class="section-body"><form method="get" class="filters"><input type="hidden" name="tab" value="audit"><input name="audit_q" placeholder="Action, entity, description, IP..." value="<?=sa_h($auditSearch)?>"><select name="audit_pharmacy"><option value="0">All pharmacies</option><?php foreach($pharmacies as $p): ?><option value="<?=sa_h($p['id'])?>" <?=$auditPharmacy==(int)$p['id']?'selected':''?>><?=sa_h($p['name'])?></option><?php endforeach; ?></select><button class="btn btn-primary">Filter</button><a class="btn" href="?tab=audit">Reset</a></form><div class="table-wrap"><table><thead><tr><th>Time</th><th>Pharmacy</th><th>User</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead><tbody><?php foreach($audits as $a): ?><tr><td><?=sa_h($a['created_at'])?></td><td><?=sa_h($a['pharmacy_name'] ?: 'Platform')?></td><td><?=sa_h($a['username'] ?: 'System')?></td><td><span class="badge blue"><?=sa_h($a['action'])?></span></td><td><?=sa_h($a['entity_type'] ?: 'â€”')?> #<?=sa_h($a['entity_id'] ?: 'â€”')?></td><td><?=sa_h($a['description'] ?: 'â€”')?></td><td><?=sa_h($a['ip_address'] ?: 'â€”')?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php endif; ?>

<?php if ($tab === 'security'): ?>
<div class="titlebar"><div><h1>Super Admin Security</h1><p>Manage the people who have global platform control.</p></div></div>
<div class="section"><div class="section-head"><h2>Create Super Admin</h2></div><div class="section-body"><form method="post"><input type="hidden" name="action" value="create_super_admin"><input type="hidden" name="return_tab" value="security"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><div class="form-grid"><div class="field"><label>Username *</label><input name="sa_username" required></div><div class="field"><label>Email</label><input name="sa_email" type="email"></div><div class="field"><label>Full name</label><input name="sa_full_name"></div><div class="field"><label>Password * (12+ characters)</label><input name="sa_password" type="password" minlength="12" required></div></div><div style="margin-top:12px"><button class="btn btn-primary">Create Super Admin</button></div></form></div></div>
<div class="section"><div class="section-head"><h2>Super Admin accounts</h2><span class="badge red">Global access</span></div><div class="section-body"><div class="table-wrap"><table><thead><tr><th>User</th><th>Status</th><th>Last login</th><th>Last activity</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($superAdmins as $a): ?><tr><td><strong><?=sa_h($a['username'])?></strong><br><span class="muted"><?=sa_h($a['full_name'] ?: $a['email'] ?: 'â€”')?></span></td><td><span class="badge <?=$a['status']==='Active'?'green':'red'?>"><?=sa_h($a['status'])?></span></td><td><?=sa_h($a['last_login'] ?: 'Never')?></td><td><?=sa_h($a['last_activity'] ?: 'â€”')?></td><td><?=sa_h($a['created_at'])?></td><td><form method="post"><input type="hidden" name="action" value="toggle_super_admin"><input type="hidden" name="return_tab" value="security"><input type="hidden" name="sa_id" value="<?=sa_h($a['id'])?>"><input type="hidden" name="csrf" value="<?=sa_h($csrf)?>"><button class="btn <?=$a['status']==='Active'?'btn-danger':'btn-green'?>" <?=$a['id']==$superAdminId?'disabled':''?>><?=$a['id']==$superAdminId?'Current account':($a['status']==='Active'?'Disable':'Enable')?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<div class="section"><div class="section-body"><strong>Session policy</strong><p class="muted" style="font-size:12px">Super Admin sessions use a 24-hour rolling inactivity window. Every authenticated page request refreshes the inactivity timestamp. The browser session cookie is also configured for 24 hours.</p></div></div>
<?php endif; ?>

<?php if ($tab === 'health'): ?>
<div class="titlebar"><div><h1>System Health</h1><p>Basic live checks against the EchoTech database and runtime.</p></div></div>
<div class="kpi-row"><div class="mini"><strong><?=sa_h(PHP_VERSION)?></strong><span>PHP runtime</span></div><div class="mini"><strong><?=sa_h($db->server_info ?: 'Connected')?></strong><span>Database server</span></div><div class="mini"><strong>Africa/Lusaka</strong><span>Application timezone</span></div></div>
<div class="section"><div class="section-head"><h2>Schema checks</h2></div><div class="section-body"><div class="table-wrap"><table><thead><tr><th>Table</th><th>Status</th><th>Rows</th></tr></thead><tbody><?php foreach($health as $h): ?><tr><td><?=sa_h($h['table'])?></td><td class="<?=$h['exists']?'health-ok':'health-bad'?>"><?=$h['exists']?'AVAILABLE':'MISSING'?></td><td><?=$h['exists']?number_format((int)$h['count']):'â€”'?></td></tr><?php endforeach; ?></tbody></table></div><div class="danger-note">This health page reports database/runtime state only. It does not silently repair schema or delete records.</div></div></div>
<?php endif; ?>

</div></main></div>
</body></html>
