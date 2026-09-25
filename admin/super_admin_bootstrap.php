<?php
declare(strict_types=1);

/**
 * EchoTech POS — ONE-TIME SUPER ADMIN BOOTSTRAP
 *
 * This installer creates the first Super Admin account.
 * The bootstrap code below is single-use and is recorded as consumed in
 * echotech_super_admin_settings. After successful setup, DELETE this file
 * from the deployed server.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Africa/Lusaka');
require_once __DIR__ . '/../includes/conn.php';
$db = $conn;
$db->set_charset('utf8mb4');

/* One-time code generated specifically for this bootstrap artifact. */
const ECHOTECH_ONE_TIME_CODE = '7H8P8e4GiUC_ZVQqDShJuSPZsloummMq';

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

$used = false;
$row = $db->query("SELECT setting_value FROM echotech_super_admin_settings WHERE setting_key='bootstrap_used' LIMIT 1");
if ($row instanceof mysqli_result && ($r=$row->fetch_assoc())) $used = ((string)$r['setting_value'] === '1');

$message='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $code=trim((string)($_POST['code']??''));
    $username=trim((string)($_POST['username']??''));
    $email=trim((string)($_POST['email']??''));
    $fullName=trim((string)($_POST['full_name']??''));
    $password=(string)($_POST['password']??'');
    if ($used) $error='This bootstrap code has already been consumed.';
    elseif (!hash_equals(ECHOTECH_ONE_TIME_CODE,$code)) $error='Invalid one-time bootstrap code.';
    elseif ($username==='' || strlen($password)<12) $error='Username is required and the Super Admin password must be at least 12 characters.';
    elseif ($db->query("SELECT id FROM echotech_super_admins LIMIT 1")?->num_rows>0) $error='A Super Admin account already exists. Bootstrap is no longer available.';
    else {
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $stmt=$db->prepare("INSERT INTO echotech_super_admins (username,email,password_hash,full_name,status) VALUES (?,?,?,?, 'Active')");
        if(!$stmt){$error=$db->error;}else{
            $stmt->bind_param('ssss',$username,$email,$hash,$fullName);
            if(!$stmt->execute()) $error=$stmt->error;
            else {
                $stmt->close();
                $stmt=$db->prepare("INSERT INTO echotech_super_admin_settings (setting_key,setting_value) VALUES ('bootstrap_used','1') ON DUPLICATE KEY UPDATE setting_value='1'");
                if($stmt) $stmt->execute();
                if($stmt) $stmt->close();
                $used=true;
                $message='Super Admin created successfully. Delete super_admin_bootstrap.php from the server now, then open super_admin.php.';
            }
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EchoTech One-Time Super Admin Setup</title><style>*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e8edf5;font-family:Inter,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.box{width:min(520px,100%);background:#121b2b;border:1px solid #29364b;border-radius:18px;padding:28px}.warn{padding:12px;border-radius:10px;background:#3a2711;color:#ffd28a;font-size:12px;margin-bottom:16px}.ok{padding:12px;border-radius:10px;background:#102b20;color:#b7f0d2;font-size:12px;margin-bottom:16px}.err{padding:12px;border-radius:10px;background:#3a1820;color:#ffb7c2;font-size:12px;margin-bottom:16px}.field{margin-bottom:13px}.field label{display:block;font-size:11px;color:#aeb9c9;margin-bottom:6px}.field input{width:100%;padding:11px;border:1px solid #34425a;border-radius:9px;background:#0d1523;color:#fff}.btn{width:100%;padding:12px;border:0;border-radius:9px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}code{color:#b7d1ff}</style></head><body><section class="box"><h2 style="margin-top:0">EchoTech One-Time Super Admin Setup</h2><p style="color:#94a3b8;font-size:13px">This creates the first global platform administrator.</p><div class="warn"><strong>Security:</strong> the one-time code is valid only once. After successful setup, delete <code>admin/super_admin_bootstrap.php</code> from the deployed server.</div><?php if($error):?><div class="err"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><?php if($message):?><div class="ok"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endif;?><?php if(!$used && !$message):?><form method="post"><div class="field"><label>ONE-TIME BOOTSTRAP CODE</label><input name="code" required autocomplete="off"></div><div class="field"><label>Super Admin username</label><input name="username" required></div><div class="field"><label>Email</label><input name="email" type="email"></div><div class="field"><label>Full name</label><input name="full_name"></div><div class="field"><label>Password — minimum 12 characters</label><input name="password" type="password" minlength="12" required></div><button class="btn">Create Super Admin</button></form><?php else:?><a href="super_admin.php" style="display:block;text-align:center;color:#8eb5ff;margin-top:14px">Open Super Admin</a><?php endif;?></section></body></html>
