<?php
/**
 * EchoTech POS - Staff Management
 * Accounts + roles + page access + page functions + freeze controls.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();
require_pharmacy();

$pharmacyId = current_pharmacy();
$currentUserId = current_user_id();

if (!$pharmacyId || !$currentUserId) {
    http_response_code(403);
    exit('Invalid staff session.');
}

$roles = echotech_staff_roles();
$pages = echotech_pages();
$routes = echotech_page_routes();

$roleIcons = [
    'Human Resource'=>'fa-people-roof','Pharmacist'=>'fa-prescription-bottle-medical',
    'Manager'=>'fa-user-tie','Assistant Manager'=>'fa-user-gear','PharmTech'=>'fa-pills',
    'Clinical Officer'=>'fa-stethoscope','Registered Nurse'=>'fa-user-nurse',
    'Cashier'=>'fa-cash-register','General'=>'fa-user','Security'=>'fa-shield-halved'
];

$pageIcons = [
    'Sale now'=>'fa-cart-shopping','Today transaction'=>'fa-receipt',
    'Out of stock'=>'fa-box-open','Expired products'=>'fa-calendar-xmark',
    'Customer'=>'fa-users','Online manager'=>'fa-globe','Pharmacy stock'=>'fa-boxes-stacked',
    'Purchases orders'=>'fa-file-invoice','Supplier'=>'fa-truck','Add Product'=>'fa-circle-plus',
    'Stock exchange'=>'fa-right-left','Shift log'=>'fa-clock-rotate-left',
    'Purchases order list'=>'fa-list-check','Restock'=>'fa-arrows-rotate',
    'Online orders'=>'fa-bag-shopping','Sales report'=>'fa-chart-line',
    'Lay by sale'=>'fa-hand-holding-dollar','Expenses sales trend'=>'fa-chart-area',
    'Add patient'=>'fa-user-plus','Settings'=>'fa-gear'
];

/* ------------------------- Database setup ------------------------- */

$conn->query("CREATE TABLE IF NOT EXISTS role_page_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pharmacy_id INT NOT NULL,
    role VARCHAR(100) NOT NULL,
    page_name VARCHAR(150) NOT NULL,
    can_access TINYINT(1) NOT NULL DEFAULT 1,
    can_action TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY uq_role_page(pharmacy_id,role,page_name),
    KEY idx_pharmacy(pharmacy_id),
    KEY idx_role(role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query(
        "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}'"
    );
    if ($r && (int)$r->fetch_assoc()['n'] === 0) {
        $conn->query("ALTER TABLE `{$t}` ADD COLUMN `{$c}` {$definition}");
    }
}

ensure_column($conn, 'users', 'is_frozen', 'TINYINT(1) NOT NULL DEFAULT 0');
ensure_column($conn, 'users', 'is_online_visible', 'TINYINT(1) NOT NULL DEFAULT 1');

/* ------------------------- CSRF ------------------------- */

if (empty($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['staff_csrf'];

function staff_csrf_ok(): bool
{
    return !empty($_POST['csrf_token'])
        && hash_equals($_SESSION['staff_csrf'] ?? '', (string)$_POST['csrf_token']);
}

function staff_redirect(string $message = '', string $type = 'success'): never
{
    $q = [];
    if ($message !== '') {
        $q[$type] = $message;
    }
    header('Location: staff_management.php' . ($q ? '?'.http_build_query($q) : ''));
    exit;
}

function staff_fail(string $message): never
{
    staff_redirect($message, 'error');
}

/* ------------------------- Permission defaults ------------------------- */
/*
 * Every role/page starts with Access ON.
 *
 * Missing records are inserted ON.
 * Existing legacy OFF records are migrated ON exactly once per pharmacy.
 * After that, administrator changes are preserved and are NOT overwritten
 * every time this page loads.
 */
$conn->query("CREATE TABLE IF NOT EXISTS role_page_permission_defaults (
    pharmacy_id INT NOT NULL,
    migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pharmacy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Create missing role/page combinations with Access ON. */
$values = [];

foreach ($roles as $r) {
    foreach ($pages as $p) {
        $values[] = "(
            ".(int)$pharmacyId.",
            '".$conn->real_escape_string($r)."',
            '".$conn->real_escape_string($p)."',
            1,1
        )";
    }
}

if ($values) {
    $conn->query(
        "INSERT IGNORE INTO role_page_permissions
         (pharmacy_id,role,page_name,can_access,can_action)
         VALUES ".implode(',', $values)
    );
}

/* One-time migration of the current legacy OFF records to ON. */
$checkMigration = $conn->prepare(
    "SELECT pharmacy_id
     FROM role_page_permission_defaults
     WHERE pharmacy_id=?
     LIMIT 1"
);
$checkMigration->bind_param('i', $pharmacyId);
$checkMigration->execute();
$migrated = $checkMigration->get_result()->num_rows > 0;
$checkMigration->close();

if (!$migrated) {
    $stmt = $conn->prepare(
        "UPDATE role_page_permissions
         SET can_access=1
         WHERE pharmacy_id=?"
    );

    if ($stmt) {
        $stmt->bind_param('i', $pharmacyId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO role_page_permission_defaults (pharmacy_id)
         VALUES (?)"
    );

    if ($stmt) {
        $stmt->bind_param('i', $pharmacyId);
        $stmt->execute();
        $stmt->close();
    }
}

/* ------------------------- Actions ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!staff_csrf_ok()) {
        http_response_code(419);
        exit('Security token expired. Please reload the page.');
    }

    $postAction = (string)($_POST['form_action'] ?? '');

    /* Permission: one cell */
    if ($postAction === 'permission') {
        $role = trim((string)($_POST['role'] ?? ''));
        $page = trim((string)($_POST['page'] ?? ''));
        $kind = trim((string)($_POST['kind'] ?? ''));

        if (!in_array($role, $roles, true) || !in_array($page, $pages, true)
            || !in_array($kind, ['access','function'], true)) {
            staff_fail('Invalid permission request.');
        }

        $field = $kind === 'access' ? 'can_access' : 'can_action';

        $stmt = $conn->prepare(
            "SELECT can_access,can_action
             FROM role_page_permissions
             WHERE pharmacy_id=? AND role=? AND page_name=? LIMIT 1"
        );
        $stmt->bind_param('iss', $pharmacyId, $role, $page);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            staff_fail('Permission record could not be found.');
        }

        if ($kind === 'function' && (int)$row['can_access'] !== 1) {
            staff_fail('Enable page Access before enabling Functions.');
        }

        /*
         * IMPORTANT:
         * Access and Function are deliberately independent switches.
         *
         * Access OFF  => page cannot be opened and Function is forced OFF.
         * Access ON   => page may be opened.
         * Function OFF => page may be viewed, but write/action operations
         *                 must be rejected by the target PHP/AJAX handler.
         * Function ON  => actions are allowed.
         */
        $currentValue = (int)$row[$field];
        $newValue = $currentValue === 1 ? 0 : 1;

        if ($kind === 'access') {
            /*
             * Turning Access OFF must immediately revoke Functions as well.
             * Turning Access ON never silently enables Functions.
             */
            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_access=?,
                     can_action=CASE WHEN ?=0 THEN 0 ELSE can_action END,
                     updated_at=CURRENT_TIMESTAMP
                 WHERE pharmacy_id=? AND role=? AND page_name=?"
            );

            if (!$stmt) {
                staff_fail('Permission update could not be prepared.');
            }

            $stmt->bind_param(
                'iiiss',
                $newValue,
                $newValue,
                $pharmacyId,
                $role,
                $page
            );
        } else {
            /*
             * Function can only be changed while Access is ON.
             * This is also checked above so a forged POST cannot enable it
             * while the page itself is disabled.
             */
            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_action=?, updated_at=CURRENT_TIMESTAMP
                 WHERE pharmacy_id=? AND role=? AND page_name=? AND can_access=1"
            );

            if (!$stmt) {
                staff_fail('Function permission update could not be prepared.');
            }

            $stmt->bind_param(
                'iiss',
                $newValue,
                $pharmacyId,
                $role,
                $page
            );
        }

        $ok = $stmt->execute();
        $stmt->close();

        $ok ? staff_redirect('Permission updated.') : staff_fail('Permission update failed.');
    }

    /* Quick matrix controls */
    if ($postAction === 'matrix_bulk') {
        $role = trim((string)($_POST['role'] ?? ''));
        $mode = trim((string)($_POST['mode'] ?? ''));

        if (!in_array($role, $roles, true)
            || !in_array($mode, ['all','none','access_only'], true)) {
            staff_fail('Invalid matrix action.');
        }

        if ($mode === 'all') {
            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_access=1,can_action=1
                 WHERE pharmacy_id=? AND role=?"
            );
            $stmt->bind_param('is', $pharmacyId, $role);
        } elseif ($mode === 'access_only') {
            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_access=1
                 WHERE pharmacy_id=? AND role=?"
            );
            $stmt->bind_param('is', $pharmacyId, $role);
        } else {
            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_access=0,can_action=0
                 WHERE pharmacy_id=? AND role=?"
            );
            $stmt->bind_param('is', $pharmacyId, $role);
        }

        $ok = $stmt->execute();
        $stmt->close();
        $ok ? staff_redirect('Role permissions updated.') : staff_fail('Matrix update failed.');
    }

    /* Add staff */
    if ($postAction === 'add_staff') {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'General'));
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $salary = (float)($_POST['salary'] ?? 0);
        $password = (string)($_POST['password'] ?? '');

        if (!in_array($role, $roles, true)) $role = 'General';

        if ($username === '' || $fullName === '' || $email === ''
            || $password === '' || $branchId <= 0) {
            staff_fail('Complete all required staff fields.');
        }

        $stmt = $conn->prepare(
            "SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1"
        );
        $stmt->bind_param('ii', $branchId, $pharmacyId);
        $stmt->execute();
        $branchOk = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$branchOk) staff_fail('The selected branch is invalid.');

        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE username=? AND pharmacy_id=? LIMIT 1"
        );
        $stmt->bind_param('si', $username, $pharmacyId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($duplicate) staff_fail('That username already exists.');

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $profilePic = 'default_avatar.png';

        if (!empty($_FILES['profile_pic']['name'])
            && (int)($_FILES['profile_pic']['error'] ?? 4) === UPLOAD_ERR_OK) {

            $tmp = $_FILES['profile_pic']['tmp_name'];
            $info = @getimagesize($tmp);
            $mimeMap = [
                'image/jpeg'=>'jpg',
                'image/png'=>'png',
                'image/webp'=>'webp',
                'image/gif'=>'gif'
            ];

            if ($info && isset($mimeMap[$info['mime']])) {
                $dir = __DIR__.'/../uploads/staff/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);

                $filename = date('YmdHis').'_'.bin2hex(random_bytes(5))
                    .'.'.$mimeMap[$info['mime']];

                if (@move_uploaded_file($tmp, $dir.$filename)) {
                    $profilePic = $filename;
                }
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO users
             (pharmacy_id,username,full_name,email,password,role,branch_id,
              salary_amount,profile_pic,status,is_frozen,is_online_visible)
             VALUES (?,?,?,?,?,?,?,?,?,'Active',0,1)"
        );
        $stmt->bind_param(
            'isssssids',
            $pharmacyId,$username,$fullName,$email,$passwordHash,$role,
            $branchId,$salary,$profilePic
        );

        $ok = $stmt->execute();
        $stmt->close();

        $ok ? staff_redirect('Staff account created successfully.')
            : staff_fail('The staff account could not be created.');
    }

    /* Staff security/visibility actions */
    if ($postAction === 'staff_action') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $action = trim((string)($_POST['staff_action'] ?? ''));

        if ($targetId <= 0 || $targetId === $currentUserId) {
            staff_fail('This action cannot be performed on that account.');
        }

        if (!in_array($action, ['freeze','unfreeze','online','offline','delete'], true)) {
            staff_fail('Invalid staff action.');
        }

        if ($action === 'freeze' || $action === 'unfreeze') {
            $value = $action === 'freeze' ? 1 : 0;
            $stmt = $conn->prepare(
                "UPDATE users SET is_frozen=?
                 WHERE id=? AND pharmacy_id=? AND id<>?"
            );
            $stmt->bind_param('iiii',$value,$targetId,$pharmacyId,$currentUserId);
            $ok = $stmt->execute() && $stmt->affected_rows >= 0;
            $stmt->close();
            $ok ? staff_redirect($value ? 'Account frozen.' : 'Account unfrozen.')
                : staff_fail('Freeze action failed.');
        }

        if ($action === 'online' || $action === 'offline') {
            $value = $action === 'online' ? 1 : 0;
            $stmt = $conn->prepare(
                "UPDATE users SET is_online_visible=?
                 WHERE id=? AND pharmacy_id=? AND id<>?"
            );
            $stmt->bind_param('iiii',$value,$targetId,$pharmacyId,$currentUserId);
            $ok = $stmt->execute();
            $stmt->close();
            $ok ? staff_redirect($value ? 'Staff marked online.' : 'Staff hidden online.')
                : staff_fail('Online visibility update failed.');
        }

        if ($action === 'delete') {
            $stmt = $conn->prepare(
                "DELETE FROM users
                 WHERE id=? AND pharmacy_id=? AND id<>?"
            );
            $stmt->bind_param('iii',$targetId,$pharmacyId,$currentUserId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            $changed > 0 ? staff_redirect('Staff account deleted.')
                : staff_fail('Staff account could not be deleted.');
        }
    }
}

/* ------------------------- Read data ------------------------- */

$branches = [];
$stmt = $conn->prepare(
    "SELECT id,branch_name FROM branches
     WHERE pharmacy_id=? ORDER BY branch_name"
);
$stmt->bind_param('i',$pharmacyId);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $branches[] = $row;
$stmt->close();

$staff = [];
$stmt = $conn->prepare(
    "SELECT u.id,u.username,u.full_name,u.email,u.role,u.branch_id,
            u.salary_amount,u.profile_pic,u.status,
            COALESCE(u.is_online_visible,1) AS is_online_visible,
            COALESCE(u.is_frozen,0) AS is_frozen,
            b.branch_name
     FROM users u
     LEFT JOIN branches b
       ON b.id=u.branch_id AND b.pharmacy_id=u.pharmacy_id
     WHERE u.pharmacy_id=?
     ORDER BY u.is_frozen DESC,u.full_name,u.username"
);
$stmt->bind_param('i',$pharmacyId);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $staff[] = $row;
$stmt->close();

$perms = [];
$stmt = $conn->prepare(
    "SELECT role,page_name,can_access,can_action
     FROM role_page_permissions WHERE pharmacy_id=?"
);
$stmt->bind_param('i',$pharmacyId);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) {
    $perms[$row['role']][$row['page_name']] = [
        'access'=>(int)$row['can_access'],
        'action'=>(int)$row['can_action']
    ];
}
$stmt->close();

$total = count($staff);
$active = $frozen = $online = 0;
foreach ($staff as $u) {
    if (strcasecmp((string)$u['status'],'Active') === 0) $active++;
    if ((int)$u['is_frozen'] === 1) $frozen++;
    if ((int)$u['is_online_visible'] === 1) $online++;
}

function eh(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function jsq(mixed $v): string
{
    return htmlspecialchars(
        json_encode((string)$v, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),
        ENT_QUOTES,
        'UTF-8'
    );
}

$notice = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Management | EchoTech POS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{
 --c:#202831;--c2:#29343f;--bg:#f4f6f8;--white:#fff;--text:#1e2933;
 --muted:#71808d;--border:#dfe5ea;--blue:#246bfe;--green:#159a68;
 --red:#d94d61;--yellow:#e3a329;--shadow:0 5px 20px rgba(31,40,49,.07);
 --side:250px
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 Inter,Arial,sans-serif}
.sidebar{position:fixed;inset:0 auto 0 0;width:var(--side);background:var(--c);color:#fff;
 padding:18px 14px;display:flex;flex-direction:column;z-index:1000}
.brand{display:flex;gap:10px;align-items:center;color:#fff;text-decoration:none;margin-bottom:20px}
.brandmark{width:38px;height:38px;border-radius:10px;background:#fff;color:var(--c);
 display:grid;place-items:center;font-size:17px}
.brand b{display:block;font-size:14px}.brand small{color:#9aa7b3;font-size:11px}
.side-user{display:flex;gap:10px;align-items:center;background:#151d25;border-radius:10px;padding:10px;margin-bottom:17px}
.avatar{width:34px;height:34px;border-radius:50%;background:#344250;display:grid;place-items:center;font-weight:800}
.side-user b{display:block;font-size:12px}.side-user span{display:block;color:#9aa7b3;font-size:10px}
.cap{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:#71808d;margin:8px 9px}
.nav a{display:flex;align-items:center;gap:10px;color:#aeb9c3;text-decoration:none;padding:9px 10px;border-radius:7px;font-size:12px}
.nav a:hover,.nav a.active{background:#303c48;color:#fff}
.nav i{width:18px;text-align:center}
.logout{margin-top:auto!important;color:#ffb2bd!important}
.main{margin-left:var(--side);min-height:100vh}
.top{height:64px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;
 justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:900}
.top-title b{display:block;font-size:14px}.top-title span{font-size:11px;color:var(--muted)}
.top-right{display:flex;align-items:center;gap:9px}
.search{width:190px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12px;outline:none}
.branch{font-size:11px;color:var(--muted);padding:7px 10px;border:1px solid var(--border);border-radius:8px}
.content{padding:24px;max-width:1600px;margin:auto}
.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:18px}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--blue);font-weight:800}
h1{font-size:23px;margin:3px 0 3px;font-weight:800}.head p{margin:0;color:var(--muted);font-size:12px}
.actions{display:flex;gap:8px}.btnx{border:1px solid var(--border);background:#fff;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:700}
.btnx.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.cardx{background:#fff;border:1px solid var(--border);border-radius:12px;padding:15px;box-shadow:var(--shadow)}
.label{font-size:10px;color:var(--muted);font-weight:700}.val{font-size:24px;font-weight:800;margin-top:5px}
.ct{display:flex;justify-content:space-between}.ci{width:30px;height:30px;border-radius:8px;background:#eef3f8;display:grid;place-items:center;color:#5c7184}
.panel{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px}
.panel-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.panel-head h2{font-size:14px;margin:0}.panel-head p{margin:2px 0 0;color:var(--muted);font-size:10px}
.filters{display:flex;gap:8px;padding:12px;border-bottom:1px solid var(--border)}
.filters input,.filters select{font-size:11px;border:1px solid var(--border);border-radius:7px;padding:8px 9px;outline:none}
.filters input{flex:1}.table-wrap{overflow:auto}.table{margin:0;font-size:11px;min-width:950px}
.table th{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#75818c;background:#fafbfc;white-space:nowrap}
.table td,.table th{padding:10px 9px;vertical-align:middle;border-color:#edf0f2}
.staff-name{font-weight:800}.muted{font-size:10px;color:var(--muted)}
.badge-role{display:inline-flex;padding:4px 7px;border-radius:999px;background:#edf2f7;color:#526171;font-size:9px;font-weight:800}
.badge-green{background:#e7f7ef;color:#137b55}.badge-red{background:#fff0f2;color:#b53b50}
.btn-smx{border:1px solid var(--border);background:#fff;border-radius:6px;padding:5px 7px;font-size:10px;font-weight:700}
.btn-smx:hover{background:#f4f6f8}.danger{color:var(--red)}.success{color:var(--green)}
.permission-panel{overflow:hidden}
.matrix-tools{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.matrix-scroll{overflow:auto;max-height:650px}
.matrix{border-collapse:separate;border-spacing:0;min-width:1180px;width:100%;font-size:10px}
.matrix th,.matrix td{border-right:1px solid #edf0f2;border-bottom:1px solid #edf0f2;padding:7px 6px;text-align:center;background:#fff}
.matrix thead th{position:sticky;top:0;z-index:4;background:#f8fafb;color:#64717d;font-size:9px}
.matrix .page-col{position:sticky;left:0;z-index:5;text-align:left;min-width:210px;background:#fff}
.matrix thead .page-col{z-index:7;background:#f8fafb}
.role-head{min-width:95px}.role-name{display:block;font-size:9px;font-weight:800}
.cell-btn{width:30px;height:26px;border:1px solid #dbe2e7;border-radius:6px;background:#fff;color:#9aa5ae;cursor:pointer}
.cell-btn.on{background:#e7f7ef;border-color:#b9e7d2;color:#12835a}.cell-btn:not(.on){color:#a5afb8}
.cell-btn.fn{background:#eaf1ff;border-color:#bfd2ff;color:#246bfe}
.cell-btn:disabled{opacity:.45;cursor:not-allowed}
.cell-label{display:block;font-size:7px;margin-top:2px;color:#7a8791}
.modal-content{border:0;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.15)}
.toastx{position:fixed;right:20px;bottom:20px;background:#202831;color:#fff;padding:12px 15px;border-radius:10px;
 box-shadow:0 12px 30px rgba(0,0,0,.18);z-index:3000;font-size:12px}
.toastx.err{background:#a83349}
@media(max-width:1000px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:none}.main{margin-left:0}.cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:650px){.content{padding:14px}.top{padding:0 14px}.search,.branch{display:none}.head{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:1fr 1fr}.actions{width:100%}.actions>*{flex:1}}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <a class="brand" href="admin_dashboard.php">
        <span class="brandmark"><i class="fa-solid fa-capsules"></i></span>
        <span><b>ECHOTECH POS</b><small>Administration</small></span>
    </a>
    <div class="side-user">
        <div class="avatar"><?=eh(strtoupper(substr(current_user(),0,1)))?></div>
        <div><b><?=eh(current_user())?></b><span><?=eh(current_role() ?? 'Staff')?></span></div>
    </div>
    <div class="cap">Workspace</div>
    <nav class="nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
        <a class="active" href="staff_management.php"><i class="fa-solid fa-user-shield"></i>Staff Management</a>
        <a href="customers.php"><i class="fa-solid fa-users"></i>Customers</a>
        <a href="sales_report.php"><i class="fa-solid fa-chart-line"></i>Sales Reports</a>
        <a href="pharmacy_stock.php"><i class="fa-solid fa-boxes-stacked"></i>Pharmacy Stock</a>
        <a href="online_orders.php"><i class="fa-solid fa-bag-shopping"></i>Online Orders</a>
    </nav>
    <div class="cap">Administration</div>
    <nav class="nav">
        <a href="suppliers.php"><i class="fa-solid fa-truck"></i>Suppliers</a>
        <a href="expenses.php"><i class="fa-solid fa-wallet"></i>Expenses</a>
    </nav>
    <nav class="nav"><a class="logout" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></nav>
</aside>

<main class="main">
<header class="top">
    <div class="top-title">
        <b>Staff & Access Control</b>
        <span>Accounts, roles, page access and functions</span>
    </div>
    <div class="top-right">
        <input id="globalSearch" class="search" placeholder="Search staff...">
        <div class="branch"><i class="fa-solid fa-building me-1"></i><?=count($branches)?> branch<?=count($branches)===1?'':'es'?></div>
        <button class="btn-smx" type="button" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
    </div>
</header>

<section class="content">
<div class="head">
    <div>
        <div class="eyebrow">Administration</div>
        <h1>Staff Management</h1>
        <p>Every POS page is available by default. You can restrict access or functions at any time.</p>
    </div>
    <div class="actions">
        <button class="btnx" type="button" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
        <button class="btnx primary" type="button" data-bs-toggle="modal" data-bs-target="#addStaff">
            <i class="fa-solid fa-user-plus me-1"></i>Register Staff
        </button>
    </div>
</div>

<div class="cards">
    <div class="cardx"><div class="ct"><span class="label">Total Staff</span><span class="ci"><i class="fa-solid fa-users"></i></span></div><div class="val"><?=$total?></div></div>
    <div class="cardx"><div class="ct"><span class="label">Active</span><span class="ci"><i class="fa-solid fa-user-check"></i></span></div><div class="val"><?=$active?></div></div>
    <div class="cardx"><div class="ct"><span class="label">Frozen</span><span class="ci"><i class="fa-solid fa-snowflake"></i></span></div><div class="val"><?=$frozen?></div></div>
    <div class="cardx"><div class="ct"><span class="label">Online Visibility</span><span class="ci"><i class="fa-solid fa-globe"></i></span></div><div class="val"><?=$online?></div></div>
</div>

<section class="panel">
<div class="panel-head">
    <div><h2>Staff Directory</h2><p>Manage accounts without leaving this page.</p></div>
    <span class="muted" id="staffCount"><?=count($staff)?> Records</span>
</div>
<div class="filters">
    <input id="staffSearch" placeholder="Search name, username, email or role...">
    <select id="roleFilter"><option value="">All roles</option><?php foreach($roles as $r):?><option><?=eh($r)?></option><?php endforeach;?></select>
    <select id="branchFilter"><option value="">All branches</option><?php foreach($branches as $b):?><option value="<?=eh($b['id'])?>"><?=eh($b['branch_name'])?></option><?php endforeach;?></select>
</div>
<div class="table-wrap">
<table class="table" id="staffTable">
<thead><tr><th>Staff</th><th>Role</th><th>Branch</th><th>Status</th><th>Online</th><th>Security</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php foreach($staff as $u): 
 $search=strtolower(($u['full_name']??'').' '.($u['username']??'').' '.($u['email']??'').' '.($u['role']??''));
?>
<tr class="staffrow" data-search="<?=eh($search)?>" data-role="<?=eh($u['role'])?>" data-branch="<?=eh($u['branch_id'])?>">
<td><div class="staff-name"><?=eh($u['full_name'] ?: $u['username'])?></div><div class="muted"><?=eh($u['username'])?> Â· <?=eh($u['email'])?></div></td>
<td><span class="badge-role"><?=eh($u['role'] ?: 'General')?></span></td>
<td><?=eh($u['branch_name'] ?: 'Unassigned')?></td>
<td><?php if(strcasecmp((string)$u['status'],'Active')===0):?><span class="badge-role badge-green">Active</span><?php else:?><span class="badge-role badge-red"><?=eh($u['status'])?></span><?php endif;?></td>
<td><?=((int)$u['is_online_visible']===1)?'<span class="success fw-bold">Visible</span>':'<span class="muted">Hidden</span>'?></td>
<td><?=((int)$u['is_frozen']===1)?'<span class="badge-role badge-red">Frozen</span>':'<span class="badge-role badge-green">Active</span>'?></td>
<td class="text-end">
<?php if((int)$u['id'] !== $currentUserId): ?>
<form method="post" class="d-inline staff-action-form" data-confirm="<?=((int)$u['is_frozen']===1?'Unfreeze':'Freeze').' '.eh($u['full_name'] ?: $u['username']).'?'?>">
<input type="hidden" name="csrf_token" value="<?=eh($csrf)?>">
<input type="hidden" name="form_action" value="staff_action">
<input type="hidden" name="staff_action" value="<?=((int)$u['is_frozen']===1?'unfreeze':'freeze')?>">
<input type="hidden" name="user_id" value="<?=((int)$u['id'])?>">
<button class="btn-smx" type="submit" title="<?=((int)$u['is_frozen']===1?'Unfreeze':'Freeze')?>"><i class="fa-solid <?=((int)$u['is_frozen']===1?'fa-unlock':'fa-snowflake')?>"></i></button>
</form>
<form method="post" class="d-inline staff-action-form" data-confirm="<?=((int)$u['is_online_visible']===1?'Hide':'Show').' online visibility for '.eh($u['full_name'] ?: $u['username']).'?'?>">
<input type="hidden" name="csrf_token" value="<?=eh($csrf)?>">
<input type="hidden" name="form_action" value="staff_action">
<input type="hidden" name="staff_action" value="<?=((int)$u['is_online_visible']===1?'offline':'online')?>">
<input type="hidden" name="user_id" value="<?=((int)$u['id'])?>">
<button class="btn-smx" type="submit"><i class="fa-solid fa-globe"></i></button>
</form>
<form method="post" class="d-inline staff-action-form" data-danger="1" data-confirm="Permanently delete <?=eh($u['full_name'] ?: $u['username'])?>? This cannot be undone.">
<input type="hidden" name="csrf_token" value="<?=eh($csrf)?>">
<input type="hidden" name="form_action" value="staff_action">
<input type="hidden" name="staff_action" value="delete">
<input type="hidden" name="user_id" value="<?=((int)$u['id'])?>">
<button class="btn-smx danger" type="submit"><i class="fa-solid fa-trash"></i></button>
</form>
<?php else: ?><span class="muted">Current account</span><?php endif;?>
</td></tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</section>

<section class="panel permission-panel">
<div class="panel-head">
    <div>
        <h2>Page Access</h2>
        <p>
            Control which POS pages each role is allowed to access.
        </p>
    </div>
    <div class="matrix-tools">
        <span class="muted">All roles start with page access ON. Admin can turn access OFF at any time.</span>
    </div>
</div>
<div class="filters">
    <input id="pageSearch" placeholder="Search a POS page...">
    <select id="matrixRole"><option value="">Choose role for bulk actions...</option><?php foreach($roles as $r):?><option><?=eh($r)?></option><?php endforeach;?></select>
</div>
<div class="matrix-scroll">
<table class="matrix" id="matrix">
<thead><tr><th class="page-col">POS Page</th><?php foreach($roles as $r):?><th class="role-head"><span class="role-name"><?=eh($r)?></span><small>Access</small></th><?php endforeach;?></tr></thead>
<tbody>
<?php foreach($pages as $p): $icon=$pageIcons[$p]??'fa-file';?>
<tr class="pagerow" data-page="<?=eh(strtolower($p))?>">
<td class="page-col"><i class="fa-solid <?=$icon?> me-2 text-secondary"></i><strong><?=eh($p)?></strong><div class="muted"><?=eh($routes[$p]??'')?></div></td>
<?php foreach($roles as $r):
    $v=$perms[$r][$p]??['access'=>1,'action'=>1];
    $access=(int)$v['access']; $action=(int)$v['action'];
?>
<td>
<form method="post" class="d-inline permission-form">
<input type="hidden" name="csrf_token" value="<?=eh($csrf)?>">
<input type="hidden" name="form_action" value="permission">
<input type="hidden" name="role" value="<?=eh($r)?>">
<input type="hidden" name="page" value="<?=eh($p)?>">
<input type="hidden" name="kind" value="access">
<button type="submit"
        class="cell-btn <?=$access?'on':''?>"
        title="<?=$access?'Disable page access':'Enable page access'?>"
        aria-label="<?=$access?'Disable page access':'Enable page access'?>">
    <i class="fa-solid <?=$access?'fa-circle-check':'fa-circle-xmark'?>"></i>
</button>
</form>

</td>
<?php endforeach;?>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</section>
</section>
</main>

<div class="modal fade" id="addStaff" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?=eh($csrf)?>">
<input type="hidden" name="form_action" value="add_staff">
<div class="modal-header"><h5 class="modal-title">Register Staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="row g-3">
<div class="col-md-6"><label class="form-label small fw-bold">Full Name</label><input name="full_name" class="form-control" required></div>
<div class="col-md-6"><label class="form-label small fw-bold">Username</label><input name="username" class="form-control" required></div>
<div class="col-md-6"><label class="form-label small fw-bold">Email</label><input name="email" type="email" class="form-control" required></div>
<div class="col-md-6"><label class="form-label small fw-bold">Password</label><input name="password" type="password" class="form-control" required minlength="6"></div>
<div class="col-md-6"><label class="form-label small fw-bold">Role</label><select name="role" class="form-select" required><?php foreach($roles as $r):?><option><?=eh($r)?></option><?php endforeach;?></select></div>
<div class="col-md-6"><label class="form-label small fw-bold">Branch</label><select name="branch_id" class="form-select" required><option value="">Select branch</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>"><?=eh($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="col-md-6"><label class="form-label small fw-bold">Monthly Salary</label><input name="salary" type="number" min="0" step="0.01" class="form-control" value="0"></div>
<div class="col-md-6"><label class="form-label small fw-bold">Profile Image</label><input name="profile_pic" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control"></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create Account</button></div>
</form></div></div></div>

<?php if($notice || $error): ?>
<div class="toastx <?=$error?'err':''?>" id="notice"><strong><?=$error?'Action failed':'Success'?></strong><div><?=eh($notice ?: $error)?></div></div>
<?php endif;?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';

window.toggleSidebar=function(){
 document.getElementById('sidebar')?.classList.toggle('open');
};

const search=document.getElementById('staffSearch');
const global=document.getElementById('globalSearch');
const role=document.getElementById('roleFilter');
const branch=document.getElementById('branchFilter');
const count=document.getElementById('staffCount');

function filterStaff(){
 const q=(search?.value||'').toLowerCase().trim();
 const r=role?.value||'';
 const b=branch?.value||'';
 let n=0;
 document.querySelectorAll('.staffrow').forEach(row=>{
   const ok=(!q||row.dataset.search.includes(q))&&(!r||row.dataset.role===r)&&(!b||row.dataset.branch===b);
   row.style.display=ok?'':'none'; if(ok)n++;
 });
 if(count) count.textContent=n+' Record'+(n===1?'':'s');
}
search?.addEventListener('input',filterStaff);
role?.addEventListener('change',filterStaff);
branch?.addEventListener('change',filterStaff);
global?.addEventListener('input',()=>{if(search){search.value=global.value;filterStaff();}});

document.getElementById('pageSearch')?.addEventListener('input',function(){
 const q=this.value.toLowerCase().trim();
 document.querySelectorAll('.pagerow').forEach(row=>{
   row.style.display=!q||row.dataset.page.includes(q)?'':'none';
 });
});

document.querySelectorAll('.staff-action-form').forEach(form=>{
 form.addEventListener('submit',function(e){
   const msg=this.dataset.confirm||'Confirm this action?';
   if(!window.confirm(msg)) e.preventDefault();
 });
});

document.querySelectorAll('.permission-form').forEach(form=>{
 form.addEventListener('submit',function(){
   const btn=this.querySelector('button[type="submit"]');
   if(btn){btn.disabled=true;btn.style.opacity='.6';}
 });
});

setTimeout(()=>document.getElementById('notice')?.remove(),4500);
})();
</script>
</body>
</html>
