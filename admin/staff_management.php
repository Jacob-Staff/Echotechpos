<?php
session_start();

require_once '../includes/conn.php';
require_once '../includes/auth.php';

require_admin();

$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = intval($_SESSION['pharmacy_id'] ?? 0);
$current_user_id = intval($_SESSION['user_id'] ?? 0);

if ($pharmacy_id <= 0) {
    http_response_code(403);
    exit('Pharmacy session is not available.');
}

/* ---------------------------------------------------------
   CSRF
--------------------------------------------------------- */
if (empty($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['staff_csrf'];

function verify_staff_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['staff_csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security token. Please reload the page and try again.');
    }
}

function redirect_staff(string $query = ''): void {
    header('Location: staff_management.php' . ($query ? '?' . $query : ''));
    exit();
}

/* ---------------------------------------------------------
   ACTION: Toggle online visibility
--------------------------------------------------------- */
if (isset($_GET['toggle_online'])) {
    $target_id = intval($_GET['toggle_online']);
    $current_status = intval($_GET['current_status'] ?? 0);
    $new_status = ($current_status === 1) ? 0 : 1;

    $stmt = $conn->prepare(
        "UPDATE users
         SET is_online_visible = ?
         WHERE id = ? AND pharmacy_id = ?"
    );
    $stmt->bind_param("iii", $new_status, $target_id, $pharmacy_id);
    $stmt->execute();
    $stmt->close();

    redirect_staff('updated=1');
}

/* ---------------------------------------------------------
   ACTION: Add staff
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verify_staff_csrf();

    $username  = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = trim($_POST['role'] ?? 'User');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $salary    = floatval($_POST['salary'] ?? 0);
    $raw_pass  = $_POST['password'] ?? '';

    $allowed_roles = ['Pharmacist', 'Manager', 'Cashier', 'User'];
    if (!in_array($role, $allowed_roles, true)) {
        $role = 'User';
    }

    if ($username === '' || $full_name === '' || $email === '' || $raw_pass === '' || $branch_id <= 0) {
        redirect_staff('error=missing');
    }

    /* Make sure the branch belongs to this pharmacy. */
    $branch_check = $conn->prepare(
        "SELECT id FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1"
    );
    $branch_check->bind_param("ii", $branch_id, $pharmacy_id);
    $branch_check->execute();
    $branch_ok = $branch_check->get_result()->num_rows > 0;
    $branch_check->close();

    if (!$branch_ok) {
        redirect_staff('error=branch');
    }

    /* Prevent duplicate username inside the pharmacy. */
    $duplicate = $conn->prepare(
        "SELECT id FROM users WHERE username = ? AND pharmacy_id = ? LIMIT 1"
    );
    $duplicate->bind_param("si", $username, $pharmacy_id);
    $duplicate->execute();
    $duplicate_exists = $duplicate->get_result()->num_rows > 0;
    $duplicate->close();

    if ($duplicate_exists) {
        redirect_staff('error=username');
    }

    $password = password_hash($raw_pass, PASSWORD_DEFAULT);
    $profile_pic = 'default_avatar.png';

    /* Secure-ish image handling while retaining the existing upload location. */
    if (!empty($_FILES['profile_pic']['name']) && ($_FILES['profile_pic']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $target_dir = '../uploads/staff/';

        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $tmp = $_FILES['profile_pic']['tmp_name'];
        $image_info = @getimagesize($tmp);
        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];

        if ($image_info && isset($allowed_mimes[$image_info['mime']])) {
            $file_name = time() . '_' . bin2hex(random_bytes(5)) . '.' . $allowed_mimes[$image_info['mime']];
            $target_file = $target_dir . $file_name;

            if (move_uploaded_file($tmp, $target_file)) {
                $profile_pic = $file_name;
            }
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO users
        (pharmacy_id, username, full_name, email, password, role, branch_id, salary_amount, profile_pic, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
    );
    $stmt->bind_param(
        "isssssids",
        $pharmacy_id,
        $username,
        $full_name,
        $email,
        $password,
        $role,
        $branch_id,
        $salary,
        $profile_pic
    );

    if ($stmt->execute()) {
        $stmt->close();
        redirect_staff('success=1');
    }

    $stmt->close();
    redirect_staff('error=save');
}

/* ---------------------------------------------------------
   ACTION: Permission update
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permission'])) {
    verify_staff_csrf();

    $allowed_roles = ['Pharmacist', 'Manager', 'Cashier', 'User'];
    $allowed_modules = ['Inventory', 'Sales', 'Suppliers', 'Expenses', 'Reports'];

    $role = trim($_POST['target_role'] ?? '');
    $module = trim($_POST['module'] ?? '');
    $new_val = intval($_POST['new_val'] ?? 0);

    if (!in_array($role, $allowed_roles, true) || !in_array($module, $allowed_modules, true)) {
        redirect_staff('error=permission');
    }

    $new_val = $new_val ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO role_permissions (role, module_name, can_view)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE can_view = VALUES(can_view)"
    );
    $stmt->bind_param("ssi", $role, $module, $new_val);
    $stmt->execute();
    $stmt->close();

    redirect_staff('perm_updated=1');
}

/* ---------------------------------------------------------
   ACTION: Delete staff
--------------------------------------------------------- */
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);

    if ($delete_id > 0 && $delete_id !== $current_user_id) {
        $stmt = $conn->prepare(
            "DELETE FROM users WHERE id = ? AND pharmacy_id = ?"
        );
        $stmt->bind_param("ii", $delete_id, $pharmacy_id);
        $stmt->execute();
        $stmt->close();
    }

    redirect_staff('deleted=1');
}

/* ---------------------------------------------------------
   DATA: branches
--------------------------------------------------------- */
$branches = [];
$b_stmt = $conn->prepare(
    "SELECT id, branch_name
     FROM branches
     WHERE pharmacy_id = ?
     ORDER BY branch_name ASC"
);
$b_stmt->bind_param("i", $pharmacy_id);
$b_stmt->execute();
$b_result = $b_stmt->get_result();

while ($b = $b_result->fetch_assoc()) {
    $branches[] = $b;
}
$b_stmt->close();

/* ---------------------------------------------------------
   DATA: staff
--------------------------------------------------------- */
$staff = [];
$s_stmt = $conn->prepare(
    "SELECT
        u.id,
        u.username,
        u.full_name,
        u.email,
        u.role,
        u.branch_id,
        u.salary_amount,
        u.profile_pic,
        u.status,
        COALESCE(u.is_online_visible, 0) AS is_online_visible,
        b.branch_name
     FROM users u
     LEFT JOIN branches b ON u.branch_id = b.id
     WHERE u.pharmacy_id = ?
     ORDER BY
        CASE WHEN u.status = 'Active' THEN 0 ELSE 1 END,
        u.full_name ASC,
        u.username ASC"
);
$s_stmt->bind_param("i", $pharmacy_id);
$s_stmt->execute();
$s_result = $s_stmt->get_result();

while ($row = $s_result->fetch_assoc()) {
    $staff[] = $row;
}
$s_stmt->close();

/* ---------------------------------------------------------
   DATA: permission matrix
--------------------------------------------------------- */
$modules = ['Inventory', 'Sales', 'Suppliers', 'Expenses', 'Reports'];
$roles = ['Pharmacist', 'Manager', 'Cashier', 'User'];
$permissions = [];

$p_stmt = $conn->prepare(
    "SELECT role, module_name, can_view
     FROM role_permissions
     WHERE role IN ('Pharmacist','Manager','Cashier','User')
       AND module_name IN ('Inventory','Sales','Suppliers','Expenses','Reports')"
);
$p_stmt->execute();
$p_result = $p_stmt->get_result();

while ($p = $p_result->fetch_assoc()) {
    $permissions[$p['role']][$p['module_name']] = intval($p['can_view']);
}
$p_stmt->close();

/* ---------------------------------------------------------
   SUMMARY
--------------------------------------------------------- */
$total_staff = count($staff);
$active_staff = 0;
$online_staff = 0;
$branch_count = count($branches);
$role_counts = [];

foreach ($staff as $member) {
    if (strcasecmp((string)$member['status'], 'Active') === 0) {
        $active_staff++;
    }
    if ((int)$member['is_online_visible'] === 1) {
        $online_staff++;
    }

    $member_role = $member['role'] ?: 'User';
    $role_counts[$member_role] = ($role_counts[$member_role] ?? 0) + 1;
}

$inactive_staff = max(0, $total_staff - $active_staff);
$active_percent = $total_staff > 0 ? round(($active_staff / $total_staff) * 100) : 0;
$online_percent = $total_staff > 0 ? round(($online_staff / $total_staff) * 100) : 0;

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Management | EchoTech POS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
        :root{
            --charcoal:#202831;
            --charcoal-2:#2b3540;
            --charcoal-3:#374452;
            --white:#fff;
            --bg:#f4f6f8;
            --text:#1d252d;
            --muted:#6d7782;
            --border:#dfe4e9;
            --blue:#246bfe;
            --blue-soft:#eaf1ff;
            --green:#159a68;
            --green-soft:#e8f7f0;
            --yellow:#e7a72e;
            --yellow-soft:#fff6df;
            --red:#d94d61;
            --red-soft:#fff0f2;
            --purple:#7658e8;
            --sidebar:250px;
            --radius:12px;
            --shadow:0 4px 18px rgba(31,40,49,.06);
        }

        *{box-sizing:border-box}
        html,body{
            margin:0;
            min-height:100%;
            background:var(--bg);
            color:var(--text);
            font-family:Inter,Arial,sans-serif;
            font-size:14px;
        }
        body{overflow-x:hidden}
        a{text-decoration:none}
        button,input,select{font:inherit}

        .sidebar{
            position:fixed;
            inset:0 auto 0 0;
            width:var(--sidebar);
            background:var(--charcoal);
            border-right:1px solid #151b21;
            padding:17px 14px 104px;
            z-index:1000;
            overflow:auto;
        }
        .brand{
            display:flex;
            align-items:center;
            gap:11px;
            padding:4px 9px 18px;
            color:#fff;
        }
        .brand-mark{
            width:38px;height:38px;
            border-radius:10px;
            background:var(--blue);
            display:grid;place-items:center;
        }
        .brand-mark i{font-size:16px}
        .brand strong{display:block;font-size:15px;font-weight:800}
        .brand small{
            display:block;
            color:#aeb8c2;
            font-size:9px;
            letter-spacing:1px;
            text-transform:uppercase;
            margin-top:2px;
        }
        .side-caption{
            padding:12px 11px 7px;
            color:#8e9aa6;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:1px;
        }
        .side-user{
            display:flex;
            gap:9px;
            align-items:center;
            background:#18212a;
            border:1px solid #35414d;
            border-radius:9px;
            padding:10px;
            margin:0 7px 14px;
        }
        .avatar{
            width:33px;height:33px;
            border-radius:50%;
            display:grid;place-items:center;
            background:#3b4857;
            color:#fff;
            font-weight:800;
            font-size:11px;
            flex:none;
        }
        .side-user-copy{min-width:0}
        .side-user-copy b{
            display:block;color:#fff;font-size:11px;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .side-user-copy span{
            display:block;color:#9ba7b3;font-size:9px;margin-top:2px;
        }
        .nav-list{display:flex;flex-direction:column;gap:3px}
        .nav-item{
            min-height:42px;
            border-radius:8px;
            display:flex;
            align-items:center;
            gap:11px;
            padding:0 12px;
            color:#bdc6cf;
            font-size:13px;
            font-weight:600;
            transition:.18s ease;
        }
        .nav-item i{width:18px;text-align:center;color:#8996a3}
        .nav-item:hover{background:#2a3541;color:#fff}
        .nav-item.active{
            background:#344253;
            color:#fff;
            box-shadow:inset 3px 0 var(--blue);
        }
        .nav-item.active i{color:#70a0ff}
        .nav-badge{
            margin-left:auto;
            background:#465363;
            color:#e8edf2;
            padding:3px 7px;
            border-radius:12px;
            font-size:9px;
        }
        .nav-separator{height:1px;background:#3a444e;margin:13px 8px}
        .logout{
            position:absolute;
            left:14px;right:14px;bottom:13px;
            color:#f17a8b;
        }

        .main{
            margin-left:var(--sidebar);
            min-height:100vh;
        }
        .topbar{
            height:64px;
            position:sticky;
            top:0;
            z-index:900;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 28px;
            background:#fff;
            border-bottom:1px solid var(--border);
            box-shadow:0 1px 7px rgba(0,0,0,.03);
        }
        .top-left,.top-right{display:flex;align-items:center;gap:9px}
        .mobile-btn{
            display:none;
            width:36px;height:36px;
            border:1px solid var(--border);
            background:#fff;
            color:var(--charcoal);
            border-radius:8px;
        }
        .top-title b{display:block;font-size:13px}
        .top-title span{display:block;font-size:10px;color:var(--muted);margin-top:2px}
        .top-search{
            width:220px;height:37px;
            border:1px solid var(--border);
            border-radius:8px;
            padding:0 12px;
            outline:none;
            color:var(--text);
            background:#fff;
        }
        .top-search:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
        .top-icon{
            width:37px;height:37px;
            display:grid;place-items:center;
            border:1px solid var(--border);
            background:#fff;
            color:#65717d;
            border-radius:8px;
        }
        .top-icon:hover{color:var(--blue)}
        .top-branch{
            height:37px;
            padding:0 11px;
            border:1px solid var(--border);
            border-radius:8px;
            background:#fff;
            color:#5f6b77;
            font-size:11px;
            display:flex;
            align-items:center;
            gap:7px;
        }
        .top-branch i{color:var(--blue)}

        .content{
            max-width:1600px;
            margin:auto;
            padding:26px 28px 45px;
        }
        .page-head{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:15px;
            margin-bottom:17px;
        }
        .eyebrow{
            color:var(--blue);
            text-transform:uppercase;
            letter-spacing:1.1px;
            font-weight:900;
            font-size:10px;
            margin-bottom:5px;
        }
        .page-head h1{
            margin:0;
            font-size:27px;
            line-height:1.1;
            font-weight:800;
            color:var(--charcoal);
            letter-spacing:-.5px;
        }
        .page-head p{
            margin:6px 0 0;
            color:var(--muted);
            font-size:12px;
        }
        .page-actions{display:flex;gap:8px}
        .btn-main{
            height:39px;
            border-radius:8px;
            padding:0 14px;
            border:1px solid var(--border);
            background:#fff;
            color:#52606c;
            font-size:12px;
            font-weight:700;
        }
        .btn-main.primary{background:var(--blue);border-color:var(--blue);color:#fff}
        .btn-main:hover{box-shadow:0 4px 12px rgba(30,50,80,.1)}

        .summary{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:13px;
            margin-bottom:14px;
        }
        .summary-card{
            min-height:118px;
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:16px;
            position:relative;
            overflow:hidden;
        }
        .summary-card:after{
            content:"";
            position:absolute;
            width:90px;height:90px;
            right:-36px;bottom:-43px;
            border-radius:50%;
            background:rgba(36,107,254,.05);
        }
        .summary-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
        }
        .summary-label{
            color:#74808b;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.8px;
        }
        .summary-icon{
            width:34px;height:34px;
            display:grid;place-items:center;
            border-radius:8px;
            color:var(--blue);
            background:var(--blue-soft);
        }
        .summary-value{
            margin-top:10px;
            font-size:25px;
            line-height:1;
            font-weight:800;
            color:var(--charcoal);
        }
        .summary-sub{margin-top:6px;font-size:10px;color:var(--muted)}
        .summary-card.green .summary-icon{color:var(--green);background:var(--green-soft)}
        .summary-card.yellow .summary-icon{color:var(--yellow);background:var(--yellow-soft)}
        .summary-card.purple .summary-icon{color:var(--purple);background:#f0edff}

        .workspace{
            display:grid;
            grid-template-columns:minmax(0,1.65fr) 310px;
            gap:14px;
            align-items:start;
        }
        .panel{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
        }
        .panel-head{
            min-height:59px;
            padding:0 17px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            border-bottom:1px solid var(--border);
        }
        .panel-head-left{display:flex;align-items:center;gap:10px}
        .panel-head-icon{
            width:33px;height:33px;
            border-radius:8px;
            display:grid;place-items:center;
            background:var(--blue-soft);
            color:var(--blue);
        }
        .panel-head h2{
            margin:0;
            font-size:14px;
            font-weight:800;
            color:var(--charcoal);
        }
        .panel-head p{
            margin:3px 0 0;
            font-size:10px;
            color:var(--muted);
        }
        .record-count{
            background:#eef1f4;
            color:#66727d;
            border-radius:12px;
            padding:4px 8px;
            font-size:9px;
            font-weight:800;
        }

        .staff-tools{
            padding:14px 17px;
            background:#fbfcfd;
            border-bottom:1px solid var(--border);
            display:grid;
            grid-template-columns:minmax(180px,1.4fr) 180px 180px auto;
            gap:8px;
        }
        .field{
            height:39px;
            border:1px solid var(--border);
            border-radius:8px;
            background:#fff;
            color:#56626e;
            padding:0 11px;
            outline:none;
            font-size:12px;
        }
        .field:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
        .tool-btn{
            height:39px;
            border:1px solid var(--border);
            border-radius:8px;
            background:#fff;
            color:#56626e;
            font-size:12px;
            font-weight:700;
        }
        .tool-btn:hover{border-color:#b6c0ca}

        .staff-table-wrap{overflow:auto}
        .staff-table{
            width:100%;
            border-collapse:collapse;
            min-width:820px;
        }
        .staff-table th{
            background:#f7f9fb;
            color:#6b7681;
            text-transform:uppercase;
            letter-spacing:.55px;
            font-size:9px;
            font-weight:800;
            padding:12px 13px;
            border-bottom:1px solid var(--border);
            white-space:nowrap;
        }
        .staff-table td{
            padding:13px;
            border-bottom:1px solid #edf0f3;
            vertical-align:middle;
            font-size:12px;
            color:#4f5b66;
        }
        .staff-table tr:last-child td{border-bottom:0}
        .staff-table tbody tr:hover{background:#fbfcfe}
        .member{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:205px;
        }
        .staff-img{
            width:42px;height:42px;
            border-radius:10px;
            object-fit:cover;
            border:1px solid #dbe1e6;
            background:#f1f4f7;
        }
        .member-copy{min-width:0}
        .member-name{
            color:var(--charcoal);
            font-weight:800;
            font-size:12px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .member-email{
            color:#8a949e;
            font-size:9px;
            margin-top:3px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            max-width:210px;
        }
        .role-pill,.status-pill,.online-pill{
            display:inline-flex;
            align-items:center;
            gap:5px;
            border-radius:20px;
            padding:5px 8px;
            font-size:9px;
            font-weight:800;
            white-space:nowrap;
        }
        .role-pill{background:#eef2f7;color:#596674}
        .status-pill.active{background:var(--green-soft);color:var(--green)}
        .status-pill.inactive{background:#eef1f4;color:#77818b}
        .online-pill.live{background:var(--green-soft);color:var(--green)}
        .online-pill.off{background:#f1f3f5;color:#7d8790}
        .online-pill i{font-size:7px}
        .branch-name{font-size:11px;color:#5e6a75}
        .salary{font-weight:800;color:var(--charcoal);font-size:11px}
        .row-actions{display:flex;justify-content:center;gap:5px}
        .action-btn{
            width:34px;height:32px;
            display:grid;place-items:center;
            border-radius:7px;
            border:1px solid var(--border);
            background:#fff;
            color:#697580;
        }
        .action-btn:hover{color:var(--blue);border-color:#abc0eb}
        .action-btn.online{width:auto;padding:0 9px;font-size:9px;font-weight:800}
        .action-btn.online.live{color:var(--green);border-color:#bce7d3;background:#f2fbf6}
        .action-btn.delete:hover{color:var(--red);border-color:#efc0c8;background:var(--red-soft)}

        .side-stack{display:flex;flex-direction:column;gap:14px}
        .role-card{padding:15px 16px}
        .role-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px}
        .role-head b{font-size:13px;color:var(--charcoal)}
        .role-head span{font-size:10px;color:var(--muted)}
        .role-item{
            display:flex;align-items:center;gap:10px;
            padding:10px 0;
            border-bottom:1px solid #edf0f3;
        }
        .role-item:last-child{border-bottom:0}
        .role-icon{
            width:31px;height:31px;
            border-radius:8px;
            display:grid;place-items:center;
            background:#f1f4f7;color:#65727f;font-size:11px;
        }
        .role-copy{flex:1}
        .role-copy b{display:block;font-size:11px;color:var(--charcoal)}
        .role-copy span{display:block;font-size:9px;color:var(--muted);margin-top:2px}
        .role-number{font-size:12px;font-weight:800;color:var(--charcoal)}

        .health-card{padding:16px}
        .health-title{display:flex;justify-content:space-between;align-items:center}
        .health-title b{font-size:13px}
        .health-title span{font-size:9px;color:var(--muted)}
        .health-bar{
            height:7px;background:#edf0f3;border-radius:8px;
            overflow:hidden;margin:13px 0 7px;
        }
        .health-bar span{display:block;height:100%;background:var(--green);border-radius:8px}
        .health-meta{display:flex;justify-content:space-between;font-size:9px;color:var(--muted)}
        .health-meta b{color:var(--charcoal)}

        .permission-section{margin-top:14px}
        .permission-wrap{overflow:auto}
        .permission-table{
            width:100%;
            min-width:760px;
            border-collapse:collapse;
        }
        .permission-table th,.permission-table td{
            border-bottom:1px solid #edf0f3;
            padding:12px 13px;
            text-align:center;
            font-size:11px;
        }
        .permission-table th{
            background:#f7f9fb;
            color:#697581;
            font-size:9px;
            text-transform:uppercase;
            letter-spacing:.6px;
            font-weight:800;
        }
        .permission-table th:first-child,.permission-table td:first-child{text-align:left}
        .permission-table td:first-child{font-weight:800;color:var(--charcoal)}
        .permission-btn{
            min-width:82px;
            height:30px;
            border-radius:16px;
            font-size:8px;
            font-weight:900;
            letter-spacing:.3px;
            border:1px solid #dce2e7;
            background:#f3f5f7;
            color:#7a848e;
        }
        .permission-btn.allowed{
            border-color:#bde5d2;
            background:var(--green-soft);
            color:var(--green);
        }

        .empty{
            padding:50px 20px;
            text-align:center;
            color:var(--muted);
        }
        .empty i{font-size:30px;color:#b7c0c8;margin-bottom:10px}
        .empty b{display:block;color:#56626d;font-size:13px}
        .empty span{display:block;font-size:10px;margin-top:4px}

        /* Modal */
        .modal-backdrop.show{opacity:.55}
        .modal-content{
            border:0;
            border-radius:15px;
            overflow:hidden;
            box-shadow:0 24px 70px rgba(20,28,36,.22);
        }
        .modal-header{
            background:var(--charcoal);
            color:#fff;
            padding:17px 19px;
            border:0;
        }
        .modal-header .modal-title{font-size:15px;font-weight:800}
        .modal-header .modal-sub{font-size:9px;color:#aeb8c2;margin-top:3px}
        .modal-body{padding:20px;background:#fff}
        .modal-footer{padding:13px 19px;background:#fafbfc;border-top:1px solid var(--border)}
        .profile-upload{
            width:92px;height:92px;margin:0 auto 16px;position:relative;cursor:pointer;
        }
        .profile-upload img{
            width:92px;height:92px;border-radius:50%;
            object-fit:cover;border:3px solid #dbe5f8;
            background:#f2f5f8;
        }
        .profile-camera{
            position:absolute;right:0;bottom:0;
            width:30px;height:30px;border-radius:50%;
            display:grid;place-items:center;
            background:var(--blue);color:#fff;
            border:3px solid #fff;font-size:10px;
        }
        .form-label{
            font-size:10px;
            font-weight:800;
            color:#56626d;
            margin-bottom:5px;
        }
        .modal .form-control,.modal .form-select{
            height:40px;
            border:1px solid var(--border);
            border-radius:8px;
            font-size:12px;
        }
        .modal .form-control:focus,.modal .form-select:focus{
            border-color:#8bb0ff;
            box-shadow:0 0 0 3px var(--blue-soft);
        }

        .confirm-icon{
            width:54px;height:54px;border-radius:50%;
            display:grid;place-items:center;
            background:var(--yellow-soft);color:var(--yellow);
            font-size:21px;margin:0 auto 13px;
        }
        .confirm-modal .modal-body{text-align:center;padding:25px 25px 18px}
        .confirm-modal h5{font-size:17px;font-weight:800;color:var(--charcoal);margin-bottom:7px}
        .confirm-modal p{font-size:11px;line-height:1.6;color:var(--muted);margin:0 auto;max-width:390px}
        .confirm-modal .btn{height:38px;border-radius:8px;font-size:11px;font-weight:800}
        .confirm-danger .confirm-icon{background:var(--red-soft);color:var(--red)}
        .confirm-success .confirm-icon{background:var(--green-soft);color:var(--green)}

        .toast-stack{
            position:fixed;
            right:20px;
            top:82px;
            z-index:2000;
        }
        .toast-card{
            min-width:285px;
            max-width:390px;
            display:flex;
            align-items:flex-start;
            gap:10px;
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            box-shadow:0 12px 35px rgba(31,40,49,.14);
            padding:12px 13px;
            animation:slideIn .22s ease;
        }
        .toast-icon{
            width:31px;height:31px;border-radius:8px;
            display:grid;place-items:center;
            background:var(--green-soft);color:var(--green);
            flex:none;
        }
        .toast-card.error .toast-icon{background:var(--red-soft);color:var(--red)}
        .toast-copy b{display:block;font-size:11px;color:var(--charcoal)}
        .toast-copy span{display:block;font-size:9px;color:var(--muted);margin-top:2px;line-height:1.4}
        @keyframes slideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}

        @media(max-width:1200px){
            .workspace{grid-template-columns:1fr}
            .side-stack{display:grid;grid-template-columns:1fr 1fr}
        }
        @media(max-width:950px){
            :root{--sidebar:0px}
            .sidebar{
                width:250px;
                transform:translateX(-100%);
                transition:.22s ease;
                box-shadow:15px 0 35px rgba(0,0,0,.22);
            }
            .sidebar.open{transform:translateX(0)}
            .mobile-btn{display:grid;place-items:center}
            .top-search{display:none}
            .content{padding:20px 16px 35px}
            .topbar{padding:0 16px}
            .summary{grid-template-columns:repeat(2,1fr)}
            .staff-tools{grid-template-columns:1fr 1fr}
        }
        @media(max-width:600px){
            .summary,.side-stack{grid-template-columns:1fr}
            .page-head{align-items:flex-start;flex-direction:column}
            .page-actions{width:100%}
            .page-actions .btn-main{flex:1}
            .staff-tools{grid-template-columns:1fr}
            .page-head h1{font-size:23px}
            .top-branch{display:none}
            .top-title span{display:none}
        }
        @media print{
            .sidebar,.topbar,.page-actions,.staff-tools,.side-stack,.permission-section{display:none!important}
            .main{margin-left:0}
            .content{padding:0}
            body{background:#fff}
            .panel,.summary-card{box-shadow:none}
        }
    </style>
</head>

<body>
<div class="app">

    <aside class="sidebar" id="sidebar">
        <a href="admin_dashboard.php" class="brand">
            <span class="brand-mark"><i class="fa-solid fa-capsules"></i></span>
            <span>
                <strong>ECHOTECH POS</strong>
                <small>Administration</small>
            </span>
        </a>

        <div class="side-user">
            <div class="avatar">
                <?php echo h(strtoupper(substr($user_display_name ?: 'A', 0, 1))); ?>
            </div>
            <div class="side-user-copy">
                <b><?php echo h($user_display_name ?: 'Administrator'); ?></b>
                <span><?php echo h($user_role ?: 'Admin'); ?></span>
            </div>
        </div>

        <div class="side-caption">Workspace</div>
        <nav class="nav-list">
            <a class="nav-item" href="admin_dashboard.php">
                <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
            </a>
            <a class="nav-item active" href="staff_management.php">
                <i class="fa-solid fa-user-shield"></i><span>Staff Management</span>
                <span class="nav-badge"><?php echo $total_staff; ?></span>
            </a>
            <a class="nav-item" href="customers.php">
                <i class="fa-solid fa-users"></i><span>Customers</span>
            </a>
            <a class="nav-item" href="sales_report.php">
                <i class="fa-solid fa-chart-line"></i><span>Sales Reports</span>
            </a>
            <a class="nav-item" href="pharmacy_stock.php">
                <i class="fa-solid fa-boxes-stacked"></i><span>Inventory</span>
            </a>
            <a class="nav-item" href="online_orders.php">
                <i class="fa-solid fa-bag-shopping"></i><span>Online Orders</span>
            </a>
        </nav>

        <div class="side-caption">Administration</div>
        <nav class="nav-list">
            <a class="nav-item" href="suppliers.php">
                <i class="fa-solid fa-truck"></i><span>Suppliers</span>
            </a>
            <a class="nav-item" href="expenses.php">
                <i class="fa-solid fa-wallet"></i><span>Expenses</span>
            </a>
        </nav>

        <div class="nav-separator"></div>

        <a href="../logout.php" class="nav-item logout">
            <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
        </a>
    </aside>

    <main class="main">

        <header class="topbar">
            <div class="top-left">
                <button class="mobile-btn" type="button" onclick="toggleSidebar()" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="top-title">
                    <b>Staff & Access Control</b>
                    <span>Manage your pharmacy team and permissions</span>
                </div>
            </div>

            <div class="top-right">
                <input class="top-search" id="globalSearch" type="search" placeholder="Search staff...">
                <div class="top-branch">
                    <i class="fa-solid fa-building"></i>
                    <span><?php echo $branch_count; ?> branch<?php echo $branch_count == 1 ? '' : 'es'; ?></span>
                </div>
                <button class="top-icon" type="button" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                </button>
                <a class="top-icon" href="admin_dashboard.php" title="Dashboard">
                    <i class="fa-solid fa-house"></i>
                </a>
            </div>
        </header>

        <section class="content">

            <div class="page-head">
                <div>
                    <div class="eyebrow">Administration</div>
                    <h1>Staff Management</h1>
                    <p>Manage staff accounts, branch assignments, online visibility and role access.</p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn-main" onclick="window.print()">
                        <i class="fa-solid fa-print me-1"></i> Print
                    </button>
                    <button type="button" class="btn-main primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fa-solid fa-user-plus me-1"></i> Register Staff
                    </button>
                </div>
            </div>

            <div class="summary">
                <div class="summary-card">
                    <div class="summary-top">
                        <span class="summary-label">Total Staff</span>
                        <span class="summary-icon"><i class="fa-solid fa-users"></i></span>
                    </div>
                    <div class="summary-value"><?php echo $total_staff; ?></div>
                    <div class="summary-sub">All staff accounts in this pharmacy</div>
                </div>

                <div class="summary-card green">
                    <div class="summary-top">
                        <span class="summary-label">Active Staff</span>
                        <span class="summary-icon"><i class="fa-solid fa-user-check"></i></span>
                    </div>
                    <div class="summary-value"><?php echo $active_staff; ?></div>
                    <div class="summary-sub"><?php echo $active_percent; ?>% of registered staff</div>
                </div>

                <div class="summary-card yellow">
                    <div class="summary-top">
                        <span class="summary-label">Online Visibility</span>
                        <span class="summary-icon"><i class="fa-solid fa-eye"></i></span>
                    </div>
                    <div class="summary-value"><?php echo $online_staff; ?></div>
                    <div class="summary-sub"><?php echo $online_percent; ?>% pushed online</div>
                </div>

                <div class="summary-card purple">
                    <div class="summary-top">
                        <span class="summary-label">Branches</span>
                        <span class="summary-icon"><i class="fa-solid fa-building"></i></span>
                    </div>
                    <div class="summary-value"><?php echo $branch_count; ?></div>
                    <div class="summary-sub"><?php echo $inactive_staff; ?> inactive staff account<?php echo $inactive_staff == 1 ? '' : 's'; ?></div>
                </div>
            </div>

            <div class="workspace">

                <section class="panel">
                    <div class="panel-head">
                        <div class="panel-head-left">
                            <div class="panel-head-icon"><i class="fa-solid fa-id-card"></i></div>
                            <div>
                                <h2>Staff Directory</h2>
                                <p>Accounts belonging to the current pharmacy</p>
                            </div>
                        </div>
                        <span class="record-count" id="recordCount"><?php echo $total_staff; ?> Records</span>
                    </div>

                    <div class="staff-tools">
                        <input type="search" class="field" id="staffSearch" placeholder="Search name, username or email...">

                        <select class="field" id="roleFilter">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo h(strtolower($role)); ?>"><?php echo h($role); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select class="field" id="branchFilter">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo intval($branch['id']); ?>"><?php echo h($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button class="tool-btn" type="button" onclick="resetStaffFilters()">
                            <i class="fa-solid fa-rotate-left me-1"></i> Reset
                        </button>
                    </div>

                    <div class="staff-table-wrap">
                        <table class="staff-table">
                            <thead>
                                <tr>
                                    <th>Staff Member</th>
                                    <th>Role</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th>Salary</th>
                                    <th class="text-center">Online</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="staffBody">
                            <?php if (!$staff): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty">
                                            <i class="fa-regular fa-user"></i>
                                            <b>No staff accounts found</b>
                                            <span>Register your first staff member to begin.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staff as $row): ?>
                                    <?php
                                        $staff_id = intval($row['id']);
                                        $profile = $row['profile_pic'] ?: 'default_avatar.png';
                                        $img_path = '../uploads/staff/' . rawurlencode($profile);
                                        $display_name = trim($row['full_name'] ?: $row['username']);
                                        $role_lower = strtolower((string)$row['role']);
                                        $status_active = strcasecmp((string)$row['status'], 'Active') === 0;
                                        $online = intval($row['is_online_visible']) === 1;
                                    ?>
                                    <tr class="staff-row"
                                        data-search="<?php echo h(strtolower($display_name . ' ' . $row['username'] . ' ' . $row['email'])); ?>"
                                        data-role="<?php echo h($role_lower); ?>"
                                        data-branch="<?php echo intval($row['branch_id']); ?>">
                                        <td>
                                            <div class="member">
                                                <img src="<?php echo h($img_path); ?>" class="staff-img" alt="Staff profile">
                                                <div class="member-copy">
                                                    <div class="member-name"><?php echo h($display_name); ?></div>
                                                    <div class="member-email">
                                                        @<?php echo h($row['username']); ?>
                                                        <?php if (!empty($row['email'])): ?> Â· <?php echo h($row['email']); ?><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-pill">
                                                <i class="fa-solid fa-user-tag"></i>
                                                <?php echo h($row['role'] ?: 'User'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="branch-name">
                                                <?php echo h($row['branch_name'] ?: 'Unassigned'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-pill <?php echo $status_active ? 'active' : 'inactive'; ?>">
                                                <i class="fa-solid <?php echo $status_active ? 'fa-circle-check' : 'fa-circle-minus'; ?>"></i>
                                                <?php echo h($row['status'] ?: 'Unknown'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="salary">K<?php echo number_format((float)$row['salary_amount'], 2); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($online): ?>
                                                <span class="online-pill live"><i class="fa-solid fa-circle"></i> Live</span>
                                            <?php else: ?>
                                                <span class="online-pill off"><i class="fa-regular fa-eye-slash"></i> Off</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <button type="button"
                                                        class="action-btn online <?php echo $online ? 'live' : ''; ?>"
                                                        title="<?php echo $online ? 'Remove from online visibility' : 'Push online'; ?>"
                                                        onclick="openOnlineConfirm(<?php echo $staff_id; ?>, <?php echo $online ? 1 : 0; ?>, '<?php echo h(addslashes($display_name)); ?>')">
                                                    <i class="fa-solid <?php echo $online ? 'fa-eye' : 'fa-eye-slash'; ?> me-1"></i>
                                                    <?php echo $online ? 'Live' : 'Push'; ?>
                                                </button>

                                                <?php if ($staff_id !== $current_user_id): ?>
                                                    <button type="button"
                                                            class="action-btn delete"
                                                            title="Delete staff"
                                                            onclick="openDeleteConfirm(<?php echo $staff_id; ?>, '<?php echo h(addslashes($display_name)); ?>')">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="action-btn" title="Your own account cannot be deleted" style="opacity:.45;cursor:not-allowed;">
                                                        <i class="fa-solid fa-lock"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="side-stack">

                    <div class="panel role-card">
                        <div class="role-head">
                            <b>Team Composition</b>
                            <span><?php echo $total_staff; ?> staff</span>
                        </div>

                        <?php
                        $role_icons = [
                            'Pharmacist' => 'fa-prescription-bottle-medical',
                            'Manager' => 'fa-user-tie',
                            'Cashier' => 'fa-cash-register',
                            'User' => 'fa-user'
                        ];
                        foreach ($roles as $role):
                            $count = intval($role_counts[$role] ?? 0);
                        ?>
                            <div class="role-item">
                                <div class="role-icon">
                                    <i class="fa-solid <?php echo $role_icons[$role]; ?>"></i>
                                </div>
                                <div class="role-copy">
                                    <b><?php echo h($role); ?></b>
                                    <span>Staff account<?php echo $count == 1 ? '' : 's'; ?></span>
                                </div>
                                <div class="role-number"><?php echo $count; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="panel health-card">
                        <div class="health-title">
                            <b>Staff Availability</b>
                            <span>Current setup</span>
                        </div>
                        <div class="health-bar">
                            <span style="width:<?php echo $online_percent; ?>%"></span>
                        </div>
                        <div class="health-meta">
                            <span><b><?php echo $online_staff; ?></b> visible online</span>
                            <span><b><?php echo $active_staff; ?></b> active</span>
                        </div>
                    </div>

                    <div class="panel health-card">
                        <div class="health-title">
                            <b>Access Control</b>
                            <span>Role based</span>
                        </div>
                        <p style="font-size:10px;color:var(--muted);line-height:1.6;margin:11px 0 0;">
                            Use the access matrix below to control which modules each role can view.
                        </p>
                        <div style="margin-top:12px;font-size:9px;color:#78838e;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--blue);margin-right:5px;"></i>
                            Changes apply to the selected role.
                        </div>
                    </div>

                </aside>
            </div>

            <section class="permission-section panel">
                <div class="panel-head">
                    <div class="panel-head-left">
                        <div class="panel-head-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <h2>Role Access Matrix</h2>
                            <p>Click an access status to grant or remove module viewing access.</p>
                        </div>
                    </div>
                    <span class="record-count">Can View</span>
                </div>

                <div class="permission-wrap">
                    <table class="permission-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <?php foreach ($roles as $role): ?>
                                    <th><?php echo h($role); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($modules as $module): ?>
                            <tr>
                                <td>
                                    <i class="fa-solid fa-layer-group me-2" style="color:var(--blue);font-size:10px;"></i>
                                    <?php echo h($module); ?>
                                </td>

                                <?php foreach ($roles as $role): ?>
                                    <?php $allowed = intval($permissions[$role][$module] ?? 0) === 1; ?>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                            <input type="hidden" name="target_role" value="<?php echo h($role); ?>">
                                            <input type="hidden" name="module" value="<?php echo h($module); ?>">
                                            <input type="hidden" name="perm_action" value="can_view">
                                            <input type="hidden" name="new_val" value="<?php echo $allowed ? 0 : 1; ?>">

                                            <button type="submit"
                                                    name="update_permission"
                                                    class="permission-btn <?php echo $allowed ? 'allowed' : ''; ?>"
                                                    title="Click to <?php echo $allowed ? 'deny' : 'allow'; ?> <?php echo h($role); ?> access to <?php echo h($module); ?>">
                                                <i class="fa-solid <?php echo $allowed ? 'fa-check' : 'fa-lock'; ?> me-1"></i>
                                                <?php echo $allowed ? 'ALLOWED' : 'DENIED'; ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </section>
    </main>
</div>

<!-- Add Staff Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-header">
                    <div>
                        <div class="modal-title">Register Staff Member</div>
                        <div class="modal-sub">Create a new account and assign the staff member to a branch.</div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="profile-upload" onclick="document.getElementById('profile_pic_input').click()">
                        <img src="../uploads/staff/default_avatar.png" id="image_preview" alt="Profile preview">
                        <span class="profile-camera"><i class="fa-solid fa-camera"></i></span>
                    </div>
                    <input type="file"
                           name="profile_pic"
                           id="profile_pic_input"
                           class="d-none"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="previewImage(this)">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username / Login</label>
                            <input type="text" name="username" class="form-control" required autocomplete="username">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required autocomplete="email">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required autocomplete="new-password">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo h($role); ?>"><?php echo h($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Monthly Salary</label>
                            <input type="number" step="0.01" min="0" name="salary" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Branch Assignment</label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">Select branch...</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo intval($branch['id']); ?>">
                                        <?php echo h($branch['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-user-plus me-1"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Online confirmation -->
<div class="modal fade confirm-modal <?php echo ''; ?>" id="onlineConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <div class="confirm-icon" id="onlineConfirmIcon">
                    <i class="fa-solid fa-eye"></i>
                </div>
                <h5 id="onlineConfirmTitle">Push staff online?</h5>
                <p id="onlineConfirmText">This will change the staff member's online visibility.</p>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="onlineConfirmLink" class="btn btn-primary px-4">Continue</a>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirmation -->
<div class="modal fade confirm-modal confirm-danger" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <div class="confirm-icon"><i class="fa-solid fa-trash-can"></i></div>
                <h5>Delete staff account?</h5>
                <p id="deleteConfirmText">This action will permanently remove the selected staff account from this pharmacy.</p>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Keep Account</button>
                <a href="#" id="deleteConfirmLink" class="btn btn-danger px-4">Delete Account</a>
            </div>
        </div>
    </div>
</div>

<!-- Flash message -->
<?php
$flash = '';
$flash_type = 'success';

if (isset($_GET['success'])) {
    $flash = 'Staff account created successfully.';
} elseif (isset($_GET['updated'])) {
    $flash = 'Online visibility updated.';
} elseif (isset($_GET['deleted'])) {
    $flash = 'Staff account removed.';
} elseif (isset($_GET['perm_updated'])) {
    $flash = 'Role permission updated successfully.';
} elseif (isset($_GET['error'])) {
    $flash_type = 'error';
    $errors = [
        'missing' => 'Please complete all required staff fields.',
        'branch' => 'The selected branch does not belong to this pharmacy.',
        'username' => 'That username already exists in this pharmacy.',
        'save' => 'The staff account could not be created.',
        'permission' => 'The permission request was invalid.'
    ];
    $flash = $errors[$_GET['error']] ?? 'Something went wrong.';
}
?>

<?php if ($flash): ?>
<div class="toast-stack">
    <div class="toast-card <?php echo $flash_type === 'error' ? 'error' : ''; ?>" id="flashToast">
        <div class="toast-icon">
            <i class="fa-solid <?php echo $flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
        </div>
        <div class="toast-copy">
            <b><?php echo $flash_type === 'error' ? 'Action not completed' : 'Staff Management'; ?></b>
            <span><?php echo h($flash); ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
}

function previewImage(input){
    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const allowed = ['image/jpeg','image/png','image/webp','image/gif'];

    if (!allowed.includes(file.type)) {
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e){
        document.getElementById('image_preview').src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function filterStaff(){
    const search = document.getElementById('staffSearch').value.toLowerCase().trim();
    const role = document.getElementById('roleFilter').value.toLowerCase();
    const branch = document.getElementById('branchFilter').value;

    const rows = document.querySelectorAll('.staff-row');
    let visible = 0;

    rows.forEach(row => {
        const rowSearch = row.dataset.search || '';
        const rowRole = row.dataset.role || '';
        const rowBranch = row.dataset.branch || '';

        const matchesSearch = !search || rowSearch.includes(search);
        const matchesRole = !role || rowRole === role;
        const matchesBranch = !branch || rowBranch === branch;

        const show = matchesSearch && matchesRole && matchesBranch;
        row.style.display = show ? '' : 'none';

        if (show) visible++;
    });

    document.getElementById('recordCount').textContent = visible + ' Record' + (visible === 1 ? '' : 's');
}

function resetStaffFilters(){
    document.getElementById('staffSearch').value = '';
    document.getElementById('roleFilter').value = '';
    document.getElementById('branchFilter').value = '';
    filterStaff();
}

function openOnlineConfirm(id, currentStatus, name){
    const modalEl = document.getElementById('onlineConfirmModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const title = document.getElementById('onlineConfirmTitle');
    const text = document.getElementById('onlineConfirmText');
    const link = document.getElementById('onlineConfirmLink');
    const icon = document.getElementById('onlineConfirmIcon');

    if (currentStatus === 1) {
        title.textContent = 'Remove from online?';
        text.textContent = name + ' will no longer be shown as an online-visible staff member.';
        link.textContent = 'Remove Online';
        link.className = 'btn btn-warning px-4';
        icon.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
    } else {
        title.textContent = 'Push staff online?';
        text.textContent = name + ' will become visible online where the POS uses staff visibility.';
        link.textContent = 'Push Online';
        link.className = 'btn btn-success px-4';
        icon.innerHTML = '<i class="fa-solid fa-eye"></i>';
    }

    link.href = '?toggle_online=' + encodeURIComponent(id) + '&current_status=' + encodeURIComponent(currentStatus);
    modal.show();
}

function openDeleteConfirm(id, name){
    const modalEl = document.getElementById('deleteConfirmModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('deleteConfirmText').textContent =
        'Delete "' + name + '"? This permanently removes the staff account from this pharmacy.';

    document.getElementById('deleteConfirmLink').href =
        '?delete_id=' + encodeURIComponent(id);

    modal.show();
}

document.getElementById('staffSearch')?.addEventListener('input', filterStaff);
document.getElementById('roleFilter')?.addEventListener('change', filterStaff);
document.getElementById('branchFilter')?.addEventListener('change', filterStaff);
document.getElementById('globalSearch')?.addEventListener('input', function(){
    document.getElementById('staffSearch').value = this.value;
    filterStaff();
});

setTimeout(function(){
    const toast = document.getElementById('flashToast');
    if (toast) toast.parentElement.remove();
}, 4500);
</script>

</body>
</html>
