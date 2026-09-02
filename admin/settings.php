<?php
declare(strict_types=1);

/**
 * EchoTech POS — Admin Configuration
 *
 * Uses the live schema supplied for EchoTech:
 *   pharmacies
 *   branches
 *   pos_settings
 *   role_page_permissions
 *
 * No new table is required.
 * pos_settings is keyed with "pharmacy_{id}:" so the existing global
 * setting_key table remains tenant-safe without changing its schema.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$adminDb = $conn;
$adminDb->set_charset('utf8mb4');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

$user_role = function_exists('current_role') ? current_role() : (string)($_SESSION['role'] ?? 'Admin');
$user_display_name = function_exists('current_user') ? current_user() : 'Administrator';

function cfg_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function cfg_rows(mysqli $db, string $sql, string $types = '', array $values = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => &$value) $refs[$key] = &$value;
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function cfg_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array {
    $rows = cfg_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}
function cfg_csrf(): string {
    if (empty($_SESSION['config_csrf'])) $_SESSION['config_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['config_csrf'];
}
function cfg_check_csrf(): void {
    if (!hash_equals((string)($_SESSION['config_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Invalid security token. Please refresh and try again.');
    }
}
function cfg_log(mysqli $db, int $pharmacyId, int $userId, string $action, string $entityType, ?int $entityId, string $description): void {
    $stmt = $db->prepare(
        'INSERT INTO compliance_audit_log
         (pharmacy_id,user_id,action,entity_type,entity_id,description,ip_address)
         VALUES (?,?,?,?,?,?,?)'
    );
    if (!$stmt) return;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $stmt->bind_param('iississ', $pharmacyId, $userId, $action, $entityType, $entityId, $description, $ip);
    @$stmt->execute();
    $stmt->close();
}
function cfg_setting_key(int $pharmacyId, string $key): string {
    return 'pharmacy_' . $pharmacyId . ':' . $key;
}
function cfg_get_settings(mysqli $db, int $pharmacyId): array {
    $rows = cfg_rows(
        $db,
        'SELECT setting_key, setting_value
         FROM pos_settings
         WHERE setting_key LIKE ?
         ORDER BY setting_key ASC',
        's',
        ['pharmacy_' . $pharmacyId . ':%']
    );
    $out = [];
    $prefix = 'pharmacy_' . $pharmacyId . ':';
    foreach ($rows as $row) {
        $key = (string)$row['setting_key'];
        if (str_starts_with($key, $prefix)) $out[substr($key, strlen($prefix))] = (string)($row['setting_value'] ?? '');
    }
    return $out;
}
function cfg_set_setting(mysqli $db, int $pharmacyId, string $key, string $value): void {
    $fullKey = cfg_setting_key($pharmacyId, $key);
    $stmt = $db->prepare(
        'INSERT INTO pos_settings (setting_key,setting_value)
         VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('ss', $fullKey, $value);
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }
    $stmt->close();
}

$csrf = cfg_csrf();
$notice = '';
$error = '';
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'general');
$allowedTabs = ['general','pos','access'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'general';

$pharmacy = cfg_one(
    $adminDb,
    'SELECT id,name,logo,address,phone,created_at FROM pharmacies WHERE id=? LIMIT 1',
    'i',
    [$pharmacy_id]
);
if (!$pharmacy) {
    http_response_code(403);
    exit('Pharmacy not found.');
}

/* ---------- POST ---------- */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        cfg_check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));

            if ($name === '') throw new RuntimeException('Pharmacy name is required.');
            if (mb_strlen($name) > 255) throw new RuntimeException('Pharmacy name cannot exceed 255 characters.');
            if (mb_strlen($address) > 255) throw new RuntimeException('Address cannot exceed 255 characters.');
            if (mb_strlen($phone) > 20) throw new RuntimeException('Phone cannot exceed 20 characters.');

            $logo = (string)($pharmacy['logo'] ?? 'default_logo.png');
            if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int)$_FILES['logo']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The logo upload failed.');
                if ((int)$_FILES['logo']['size'] > 2 * 1024 * 1024) throw new RuntimeException('Logo must be 2 MB or smaller.');

                $tmp = (string)$_FILES['logo']['tmp_name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($tmp);
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!isset($allowed[$mime])) throw new RuntimeException('Logo must be JPG, PNG or WebP.');
                $uploadDir = __DIR__ . '/../uploads/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Unable to create the uploads directory.');
                $newFile = 'pharmacy_' . $pharmacy_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($tmp, $uploadDir . $newFile)) throw new RuntimeException('Unable to save the uploaded logo.');
                $logo = $newFile;
            }

            $stmt = $adminDb->prepare('UPDATE pharmacies SET name=?, address=?, phone=?, logo=? WHERE id=?');
            if (!$stmt) throw new RuntimeException($adminDb->error);
            $stmt->bind_param('ssssi', $name, $address, $phone, $logo, $pharmacy_id);
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new RuntimeException($message);
            }
            $stmt->close();

            cfg_log($adminDb, $pharmacy_id, $current_user_id, 'UPDATE_PHARMACY_PROFILE', 'pharmacy', $pharmacy_id, 'Updated pharmacy identity, contact details or logo.');
            $notice = 'Pharmacy profile saved successfully.';
        } elseif ($action === 'save_pos') {
            $defaultPayment = trim((string)($_POST['default_payment_method'] ?? 'Cash'));
            $allowedPayments = ['Cash','Cash on Delivery','Bank Transfer','Mobile Money'];
            if (!in_array($defaultPayment, $allowedPayments, true)) $defaultPayment = 'Cash';

            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'ZMW')));
            if (!in_array($currency, ['ZMW','USD'], true)) $currency = 'ZMW';

            $timezone = trim((string)($_POST['timezone'] ?? 'Africa/Lusaka'));
            if (!in_array($timezone, ['Africa/Lusaka','UTC'], true)) $timezone = 'Africa/Lusaka';

            $lowStock = max(0, min(100000, (int)($_POST['low_stock_threshold'] ?? 10)));
            $autoRefresh = max(0, min(3600, (int)($_POST['auto_refresh_seconds'] ?? 60)));
            $receiptFooter = trim((string)($_POST['receipt_footer'] ?? ''));
            if (mb_strlen($receiptFooter) > 500) throw new RuntimeException('Receipt footer cannot exceed 500 characters.');

            $settingsToSave = [
                'default_payment_method' => $defaultPayment,
                'currency' => $currency,
                'timezone' => $timezone,
                'low_stock_threshold' => (string)$lowStock,
                'auto_refresh_seconds' => (string)$autoRefresh,
                'receipt_footer' => $receiptFooter,
                'show_branch_on_receipt' => isset($_POST['show_branch_on_receipt']) ? '1' : '0',
                'allow_online_orders' => isset($_POST['allow_online_orders']) ? '1' : '0',
                'require_admin_price_override' => isset($_POST['require_admin_price_override']) ? '1' : '0',
                'notify_low_stock' => isset($_POST['notify_low_stock']) ? '1' : '0',
                'notify_expiry' => isset($_POST['notify_expiry']) ? '1' : '0',
                'notify_online_orders' => isset($_POST['notify_online_orders']) ? '1' : '0',
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
            ];
            foreach ($settingsToSave as $key => $value) cfg_set_setting($adminDb, $pharmacy_id, $key, $value);

            cfg_log($adminDb, $pharmacy_id, $current_user_id, 'UPDATE_POS_CONFIGURATION', 'settings', null, 'Updated POS, receipt, notification and operational preference settings.');
            $notice = 'POS configuration saved successfully.';
            $tab = 'pos';
        } elseif ($action === 'save_permissions') {
            $roleRows = cfg_rows(
                $adminDb,
                'SELECT DISTINCT role FROM role_page_permissions WHERE pharmacy_id=? ORDER BY role ASC',
                'i',
                [$pharmacy_id]
            );
            $roles = array_map(static fn($r) => (string)$r['role'], $roleRows);

            $pageRows = cfg_rows(
                $adminDb,
                'SELECT DISTINCT page_name FROM role_page_permissions WHERE pharmacy_id=? ORDER BY page_name ASC',
                'i',
                [$pharmacy_id]
            );
            $pages = array_map(static fn($r) => (string)$r['page_name'], $pageRows);

            $adminDb->begin_transaction();
            try {
                foreach ($roles as $role) {
                    foreach ($pages as $page) {
                        $safeRoleKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $role);
                        $safePageKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $page);
                        $canAccess = isset($_POST['access'][$safeRoleKey][$safePageKey]) ? 1 : 0;
                        $canAction = isset($_POST['action_perm'][$safeRoleKey][$safePageKey]) ? 1 : 0;

                        $stmt = $adminDb->prepare(
                            'INSERT INTO role_page_permissions
                             (pharmacy_id,role,page_name,can_access,can_action)
                             VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE can_access=VALUES(can_access), can_action=VALUES(can_action)'
                        );
                        if (!$stmt) throw new RuntimeException($adminDb->error);
                        $stmt->bind_param('issii', $pharmacy_id, $role, $page, $canAccess, $canAction);
                        if (!$stmt->execute()) {
                            $message = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException($message);
                        }
                        $stmt->close();
                    }
                }
                $adminDb->commit();
            } catch (Throwable $e) {
                $adminDb->rollback();
                throw $e;
            }

            cfg_log($adminDb, $pharmacy_id, $current_user_id, 'UPDATE_ROLE_PERMISSIONS', 'role_page_permissions', null, 'Updated page access and action permissions for staff roles.');
            $notice = 'Access-control permissions saved successfully.';
            $tab = 'access';
        } else {
            throw new RuntimeException('Unknown configuration action.');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ---------- Reload after POST ---------- */
$pharmacy = cfg_one(
    $adminDb,
    'SELECT id,name,logo,address,phone,created_at FROM pharmacies WHERE id=? LIMIT 1',
    'i',
    [$pharmacy_id]
) ?? $pharmacy;

$settings = cfg_get_settings($adminDb, $pharmacy_id);

$defaults = [
    'default_payment_method'=>'Cash',
    'currency'=>'ZMW',
    'timezone'=>'Africa/Lusaka',
    'low_stock_threshold'=>'10',
    'auto_refresh_seconds'=>'60',
    'receipt_footer'=>'Thank you for choosing us.',
    'show_branch_on_receipt'=>'1',
    'allow_online_orders'=>'1',
    'require_admin_price_override'=>'0',
    'notify_low_stock'=>'1',
    'notify_expiry'=>'1',
    'notify_online_orders'=>'1',
    'maintenance_mode'=>'0',
];
$settings = array_merge($defaults, $settings);

$roles = [];
$roleRows = cfg_rows(
    $adminDb,
    'SELECT DISTINCT role FROM role_page_permissions WHERE pharmacy_id=? ORDER BY role ASC',
    'i',
    [$pharmacy_id]
);
foreach ($roleRows as $r) $roles[] = (string)$r['role'];

$pages = [];
$pageRows = cfg_rows(
    $adminDb,
    'SELECT DISTINCT page_name FROM role_page_permissions WHERE pharmacy_id=? ORDER BY page_name ASC',
    'i',
    [$pharmacy_id]
);
foreach ($pageRows as $r) $pages[] = (string)$r['page_name'];

$permissionMap = [];
if ($roles && $pages) {
    $permRows = cfg_rows(
        $adminDb,
        'SELECT role,page_name,can_access,can_action
         FROM role_page_permissions
         WHERE pharmacy_id=?',
        'i',
        [$pharmacy_id]
    );
    foreach ($permRows as $row) {
        $permissionMap[(string)$row['role']][(string)$row['page_name']] = [
            'access' => (int)$row['can_access'],
            'action' => (int)$row['can_action'],
        ];
    }
}

$branches = cfg_rows(
    $adminDb,
    'SELECT id,branch_name,branch_code,is_active,location
     FROM branches
     WHERE pharmacy_id=?
     ORDER BY is_active DESC, branch_name ASC',
    'i',
    [$pharmacy_id]
);

$branch_count = (int)(cfg_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$total_orders = (int)(cfg_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$current_admin_page = 'settings.php';
$admin_page_title = 'Configuration';
$pharmacy_name = (string)($pharmacy['name'] ?? 'PHARMACY POS');
?>
<?php require __DIR__ . '/actions/admin_aside.php'; ?>
<div class="config-admin-main">
    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="config-content">
        <div class="page-head">
            <div>
                <div class="eyebrow"><i class="fas fa-gear"></i> Network / Administration</div>
                <h1>Configuration</h1>
                <p>Manage the pharmacy identity, POS preferences and role-based access controls for <strong><?= cfg_h($pharmacy_name) ?></strong>.</p>
            </div>
            <div class="head-actions">
                <a class="btn light" href="settings.php?tab=<?= cfg_h($tab) ?>"><i class="fas fa-rotate"></i> Refresh</a>
                <a class="btn light" href="branches.php"><i class="fas fa-store"></i> Branches</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="alert success"><i class="fas fa-circle-check"></i><span><?= cfg_h($notice) ?></span><button type="button" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><i class="fas fa-circle-exclamation"></i><span><?= cfg_h($error) ?></span><button type="button" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>

        <nav class="config-tabs">
            <a class="<?= $tab==='general'?'active':'' ?>" href="settings.php?tab=general"><i class="fas fa-building"></i> Pharmacy Profile</a>
            <a class="<?= $tab==='pos'?'active':'' ?>" href="settings.php?tab=pos"><i class="fas fa-sliders"></i> POS &amp; Operations</a>
            <a class="<?= $tab==='access'?'active':'' ?>" href="settings.php?tab=access"><i class="fas fa-user-shield"></i> Access Control</a>
        </nav>

        <?php if ($tab === 'general'): ?>
            <section class="two-col">
                <div class="card">
                    <div class="card-head"><div><h2><i class="fas fa-id-card"></i> Pharmacy identity</h2><span>These fields are stored in the live <code>pharmacies</code> table.</span></div></div>
                    <form method="post" enctype="multipart/form-data" class="form-body">
                        <input type="hidden" name="csrf" value="<?= cfg_h($csrf) ?>">
                        <input type="hidden" name="action" value="save_profile">
                        <input type="hidden" name="tab" value="general">
                        <div class="form-grid">
                            <label class="field full"><span>Pharmacy / Business Name *</span><input required maxlength="255" name="name" value="<?= cfg_h($pharmacy['name']) ?>"></label>
                            <label class="field"><span>Phone</span><input maxlength="20" name="phone" value="<?= cfg_h($pharmacy['phone'] ?? '') ?>"></label>
                            <label class="field"><span>Address</span><input maxlength="255" name="address" value="<?= cfg_h($pharmacy['address'] ?? '') ?>"></label>
                            <label class="field full"><span>Logo</span><input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP · maximum 2 MB.</small></label>
                        </div>
                        <div class="form-actions"><button class="btn primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Pharmacy Profile</button></div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-head"><div><h2><i class="fas fa-image"></i> Current branding</h2><span>Preview of the logo used by the pharmacy record.</span></div></div>
                    <div class="brand-preview">
                        <div class="logo-box">
                            <?php
                            $logoFile = trim((string)($pharmacy['logo'] ?? ''));
                            $logoUrl = '../uploads/' . rawurlencode($logoFile);
                            ?>
                            <?php if ($logoFile !== '' && $logoFile !== 'default_logo.png'): ?>
                                <img src="<?= cfg_h($logoUrl) ?>" alt="Pharmacy logo">
                            <?php else: ?>
                                <span><i class="fas fa-capsules"></i></span>
                            <?php endif; ?>
                        </div>
                        <strong><?= cfg_h($pharmacy['name']) ?></strong>
                        <small><?= cfg_h($pharmacy['address'] ?: 'Address not configured') ?></small>
                        <small><?= cfg_h($pharmacy['phone'] ?: 'Phone not configured') ?></small>
                        <div class="profile-meta"><span>Pharmacy ID</span><b>#<?= (int)$pharmacy_id ?></b></div>
                        <div class="profile-meta"><span>Created</span><b><?= cfg_h(date('d M Y', strtotime((string)$pharmacy['created_at']))) ?></b></div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-head"><div><h2><i class="fas fa-store"></i> Branch network</h2><span><?= number_format(count($branches)) ?> total branch record(s) · <?= number_format($branch_count) ?> active</span></div><a class="btn primary small" href="branches.php"><i class="fas fa-arrow-right"></i> Manage Branches</a></div>
                <div class="branch-mini-grid">
                    <?php foreach ($branches as $branch): ?>
                        <a class="branch-mini" href="view_branch.php?id=<?= (int)$branch['id'] ?>">
                            <span class="branch-mini-icon"><i class="fas fa-store"></i></span>
                            <div><strong><?= cfg_h($branch['branch_name']) ?></strong><small><?= cfg_h($branch['branch_code'] ?: 'No code') ?> · <?= cfg_h($branch['location'] ?: 'Location not set') ?></small></div>
                            <span class="mini-status <?= (int)$branch['is_active']===1?'active':'inactive' ?>"><?= (int)$branch['is_active']===1?'Active':'Inactive' ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($tab === 'pos'): ?>
            <section class="card">
                <div class="card-head"><div><h2><i class="fas fa-sliders"></i> POS &amp; Operations</h2><span>Persisted per-pharmacy preferences stored in the existing <code>pos_settings</code> table.</span></div></div>
                <form method="post" class="form-body">
                    <input type="hidden" name="csrf" value="<?= cfg_h($csrf) ?>">
                    <input type="hidden" name="action" value="save_pos">
                    <input type="hidden" name="tab" value="pos">
                    <div class="section-label">Core POS preferences</div>
                    <div class="form-grid">
                        <label class="field"><span>Default Payment Method</span><select name="default_payment_method">
                            <?php foreach (['Cash','Cash on Delivery','Bank Transfer','Mobile Money'] as $payment): ?><option value="<?= cfg_h($payment) ?>" <?= $settings['default_payment_method']===$payment?'selected':'' ?>><?= cfg_h($payment) ?></option><?php endforeach; ?>
                        </select></label>
                        <label class="field"><span>Currency</span><select name="currency"><option value="ZMW" <?= $settings['currency']==='ZMW'?'selected':'' ?>>ZMW — Zambian Kwacha</option><option value="USD" <?= $settings['currency']==='USD'?'selected':'' ?>>USD — US Dollar</option></select></label>
                        <label class="field"><span>System Timezone</span><select name="timezone"><option value="Africa/Lusaka" <?= $settings['timezone']==='Africa/Lusaka'?'selected':'' ?>>Africa/Lusaka</option><option value="UTC" <?= $settings['timezone']==='UTC'?'selected':'' ?>>UTC</option></select></label>
                        <label class="field"><span>Low-stock Threshold</span><input type="number" min="0" max="100000" name="low_stock_threshold" value="<?= cfg_h($settings['low_stock_threshold']) ?>"><small>Operational preference for stock warnings.</small></label>
                        <label class="field"><span>Auto-refresh Interval (seconds)</span><input type="number" min="0" max="3600" name="auto_refresh_seconds" value="<?= cfg_h($settings['auto_refresh_seconds']) ?>"><small>0 disables the configured refresh interval.</small></label>
                        <label class="field full"><span>Receipt Footer</span><textarea maxlength="500" name="receipt_footer" rows="3"><?= cfg_h($settings['receipt_footer']) ?></textarea></label>
                    </div>

                    <div class="section-label">Operational switches</div>
                    <div class="switch-grid">
                        <?php
                        $switches = [
                            ['show_branch_on_receipt','Show branch on receipts','Include the selected branch in receipt configuration.'],
                            ['allow_online_orders','Allow online-order workflow','Stores whether the pharmacy permits the online-order workflow.'],
                            ['require_admin_price_override','Require Admin for price overrides','Stores the governance preference for price overrides.'],
                            ['maintenance_mode','Maintenance mode preference','Stores a maintenance state for modules that consume this setting.'],
                        ];
                        foreach ($switches as $sw):
                        ?>
                            <label class="switch-card"><span><strong><?= cfg_h($sw[1]) ?></strong><small><?= cfg_h($sw[2]) ?></small></span><input type="checkbox" name="<?= cfg_h($sw[0]) ?>" value="1" <?= $settings[$sw[0]]==='1'?'checked':'' ?>><i></i></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-label">Notification preferences</div>
                    <div class="switch-grid three">
                        <?php
                        $notices = [
                            ['notify_low_stock','Low-stock alerts'],
                            ['notify_expiry','Expiry alerts'],
                            ['notify_online_orders','Online-order alerts'],
                        ];
                        foreach ($notices as $sw):
                        ?>
                            <label class="switch-card"><span><strong><?= cfg_h($sw[1]) ?></strong><small>Store this notification preference for the pharmacy.</small></span><input type="checkbox" name="<?= cfg_h($sw[0]) ?>" value="1" <?= $settings[$sw[0]]==='1'?'checked':'' ?>><i></i></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions"><button class="btn primary" type="submit"><i class="fas fa-floppy-disk"></i> Save POS Configuration</button></div>
                </form>
            </section>

            <section class="info-grid">
                <div class="info-card"><span><i class="fas fa-database"></i></span><div><strong>Tenant-safe setting keys</strong><p>The existing <code>pos_settings</code> table has a globally unique key. EchoTech stores keys as <code>pharmacy_ID:key</code>, keeping configuration separated without changing the database schema.</p></div></div>
                <div class="info-card"><span><i class="fas fa-circle-info"></i></span><div><strong>Integration note</strong><p>These preferences are persisted now. Individual POS modules must read the corresponding keys before enforcing a setting across the entire application.</p></div></div>
            </section>
        <?php else: ?>
            <section class="card">
                <div class="card-head"><div><h2><i class="fas fa-user-shield"></i> Role &amp; Page Access Control</h2><span>Uses the existing <code>role_page_permissions</code> table. Admin access remains protected by the server-side admin guard.</span></div></div>
                <?php if (!$roles || !$pages): ?>
                    <div class="empty"><i class="fas fa-user-lock"></i><strong>No permission matrix found</strong><span>The pharmacy does not currently have role/page permission rows to manage.</span></div>
                <?php else: ?>
                    <form method="post" class="permissions-form">
                        <input type="hidden" name="csrf" value="<?= cfg_h($csrf) ?>">
                        <input type="hidden" name="action" value="save_permissions">
                        <input type="hidden" name="tab" value="access">
                        <div class="permission-toolbar"><div><strong><?= number_format(count($roles)) ?> roles</strong><span>×</span><strong><?= number_format(count($pages)) ?> pages</strong></div><div><span class="legend access"></span> Access <span class="legend action"></span> Action</div></div>
                        <div class="permission-wrap">
                            <table class="permission-table">
                                <thead><tr><th>Role</th><?php foreach ($pages as $page): ?><th title="<?= cfg_h($page) ?>"><?= cfg_h($page) ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                <?php foreach ($roles as $role): ?>
                                    <?php $roleKey = preg_replace('/[^a-zA-Z0-9_-]/','_',$role); ?>
                                    <tr>
                                        <th><strong><?= cfg_h($role) ?></strong></th>
                                        <?php foreach ($pages as $page): ?>
                                            <?php
                                            $pageKey = preg_replace('/[^a-zA-Z0-9_-]/','_',$page);
                                            $perm = $permissionMap[$role][$page] ?? ['access'=>0,'action'=>0];
                                            ?>
                                            <td>
                                                <label class="perm-box"><input type="checkbox" name="access[<?= cfg_h($roleKey) ?>][<?= cfg_h($pageKey) ?>]" value="1" <?= $perm['access']===1?'checked':'' ?>><span>A</span></label>
                                                <label class="perm-box action"><input type="checkbox" name="action_perm[<?= cfg_h($roleKey) ?>][<?= cfg_h($pageKey) ?>]" value="1" <?= $perm['action']===1?'checked':'' ?>><span>✓</span></label>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-actions"><button class="btn primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Permission Matrix</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="info-grid">
                <div class="info-card"><span><i class="fas fa-key"></i></span><div><strong>Access vs Action</strong><p><b>Access</b> controls whether a role may open/use the page. <b>Action</b> controls whether the role is permitted to perform page actions where the underlying module checks that permission.</p></div></div>
                <div class="info-card"><span><i class="fas fa-shield-halved"></i></span><div><strong>Admin remains protected</strong><p>This matrix does not replace <code>require_admin()</code>. The Admin control center remains server-side protected regardless of the matrix values.</p></div></div>
            </section>
        <?php endif; ?>
    </main>
</div>

<style>
.config-admin-main{margin-left:var(--sidebar);min-height:100vh;background:var(--bg)}.config-content{padding:24px 28px 42px;max-width:1600px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:18px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1.1px;color:var(--blue);font-weight:850;margin-bottom:8px}.page-head h1{margin:0;font-size:27px;color:var(--text);letter-spacing:-.5px}.page-head p{margin:7px 0 0;color:var(--muted);font-size:12px;line-height:1.6;max-width:900px}.head-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid transparent;border-radius:9px;min-height:39px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:11px;font-weight:800;cursor:pointer;text-decoration:none}.btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}.btn.light{background:#fff;color:#465363;border-color:var(--border)}.btn.small{min-height:33px;padding:0 11px}
.alert{display:flex;align-items:center;gap:10px;border:1px solid;border-radius:10px;padding:11px 13px;margin-bottom:16px;font-size:11px;font-weight:750}.alert button{margin-left:auto;border:0;background:transparent;font-size:19px;color:inherit}.alert.success{background:var(--green-soft);border-color:#bce6d2;color:#19764f}.alert.error{background:var(--red-soft);border-color:#f2c3ca;color:#b43d4e}
.config-tabs{display:flex;gap:7px;flex-wrap:wrap;background:#fff;border:1px solid var(--border);border-radius:12px;padding:7px;box-shadow:var(--shadow);margin-bottom:16px}.config-tabs a{display:inline-flex;align-items:center;gap:7px;padding:10px 13px;border-radius:8px;text-decoration:none;color:#687582;font-size:10px;font-weight:850}.config-tabs a.active{background:var(--blue-soft);color:var(--blue)}.config-tabs a i{font-size:11px}
.two-col{display:grid;grid-template-columns:1.35fr .65fr;gap:16px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);margin-bottom:16px;overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 18px;border-bottom:1px solid #edf0f3}.card-head h2{margin:0;color:var(--text);font-size:14px;font-weight:850}.card-head h2 i{color:var(--blue);font-size:12px;margin-right:7px}.card-head span{display:block;margin-top:4px;color:var(--muted);font-size:9px}.card-head code{font-size:8px}
.form-body{padding:18px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}.field>span{font-size:9px;text-transform:uppercase;letter-spacing:.7px;color:#687582;font-weight:850}.field input,.field select,.field textarea{width:100%;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);padding:0 10px;font-size:11px;outline:none}.field input,.field select{height:39px}.field textarea{padding:10px;resize:vertical}.field small{font-size:8.5px;color:#8a95a0}.field input:focus,.field select:focus,.field textarea:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}.form-actions{display:flex;justify-content:flex-end;margin-top:18px;padding-top:15px;border-top:1px solid #edf0f3}
.brand-preview{text-align:center;padding:24px}.logo-box{width:112px;height:112px;margin:0 auto 14px;border-radius:16px;background:#f3f6fb;border:1px solid #e2e7ed;display:grid;place-items:center;overflow:hidden}.logo-box img{width:100%;height:100%;object-fit:contain}.logo-box span{width:54px;height:54px;border-radius:14px;background:var(--blue);color:#fff;display:grid;place-items:center;font-size:23px}.brand-preview>strong{display:block;color:#1d2832;font-size:16px}.brand-preview>small{display:block;color:#7b8792;font-size:9px;margin-top:5px}.profile-meta{display:flex;justify-content:space-between;border-top:1px solid #edf0f3;padding-top:9px;margin-top:10px;text-align:left}.profile-meta span{font-size:9px;color:#8a95a0}.profile-meta b{font-size:9px;color:#34404b}
.branch-mini-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:15px}.branch-mini{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e7ebef;border-radius:9px;text-decoration:none;background:#fff}.branch-mini:hover{border-color:#b8ccef}.branch-mini-icon{width:34px;height:34px;border-radius:8px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}.branch-mini strong{display:block;font-size:10px;color:#28333d}.branch-mini small{display:block;color:#87929d;font-size:8.5px;margin-top:3px}.mini-status{margin-left:auto;padding:5px 7px;border-radius:999px;font-size:8px;font-weight:850}.mini-status.active{background:var(--green-soft);color:#19764f}.mini-status.inactive{background:#f1f3f5;color:#697582}
.section-label{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--blue);font-weight:850;margin:5px 0 12px}.switch-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-top:2px}.switch-grid.three{grid-template-columns:repeat(3,1fr)}.switch-card{position:relative;display:flex;align-items:center;gap:10px;border:1px solid #e5e9ed;background:#fafbfd;border-radius:9px;padding:12px}.switch-card span{flex:1}.switch-card strong{display:block;color:#2a3540;font-size:10px}.switch-card small{display:block;color:#818d98;font-size:8.5px;line-height:1.45;margin-top:3px}.switch-card input{position:absolute;opacity:0}.switch-card i{width:38px;height:22px;border-radius:20px;background:#c7ced6;position:relative;flex:0 0 38px;cursor:pointer}.switch-card i:after{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18);transition:.18s}.switch-card input:checked+i{background:var(--blue)}.switch-card input:checked+i:after{left:19px}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.info-card{display:flex;gap:11px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:var(--shadow)}.info-card>span{width:31px;height:31px;flex:0 0 31px;border-radius:8px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}.info-card strong{font-size:10.5px;color:#27323c}.info-card p{margin:4px 0 0;color:#7a8691;font-size:8.8px;line-height:1.55}.info-card code{font-size:8px}
.permissions-form{padding:0}.permission-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #edf0f3;color:#687582;font-size:9px}.permission-toolbar strong{color:#27323c}.permission-toolbar span{margin:0 5px}.legend{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--blue)}.legend.action{background:#7a5ed1}.permission-wrap{overflow:auto}.permission-table{width:100%;min-width:1250px;border-collapse:collapse}.permission-table th,.permission-table td{border-right:1px solid #edf0f3;border-bottom:1px solid #edf0f3}.permission-table thead th{position:sticky;top:0;background:#fafbfd;color:#66727e;font-size:8px;padding:10px 8px;text-align:center;min-width:74px;max-width:100px;writing-mode:vertical-rl;transform:rotate(180deg);height:125px}.permission-table thead th:first-child{writing-mode:horizontal-tb;transform:none;position:sticky;left:0;z-index:3;min-width:145px;height:auto;text-align:left}.permission-table tbody th{position:sticky;left:0;background:#fff;z-index:2;text-align:left;padding:10px 12px;font-size:9px;color:#27323c}.permission-table td{text-align:center;padding:7px 5px}.perm-box{display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;margin:0 2px}.perm-box input{position:absolute;opacity:0}.perm-box span{width:20px;height:20px;border:1px solid #dce2e8;border-radius:5px;display:grid;place-items:center;color:#9aa4ae;font-size:8px;font-weight:850;cursor:pointer;background:#fff}.perm-box input:checked+span{background:var(--blue-soft);border-color:#9fbcff;color:var(--blue)}.perm-box.action span{border-radius:50%}.perm-box.action input:checked+span{background:#f0ecff;border-color:#c8bbef;color:#7057c7}
.empty{text-align:center;padding:45px 20px;color:#8b96a1}.empty i{display:block;font-size:27px;margin-bottom:10px}.empty strong{display:block;color:#596571;font-size:12px}.empty span{display:block;margin-top:4px;font-size:9px}
@media(max-width:1050px){.two-col{grid-template-columns:1fr}.switch-grid.three{grid-template-columns:1fr 1fr}.branch-mini-grid{grid-template-columns:1fr}}
@media(max-width:900px){.config-admin-main{margin-left:0}.config-content{padding:20px}.page-head{flex-direction:column;align-items:flex-start}.head-actions{width:100%}.head-actions .btn{flex:1}}
@media(max-width:650px){.config-content{padding:16px 12px 30px}.form-grid,.switch-grid,.switch-grid.three,.info-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.config-tabs a{flex:1;justify-content:center}.form-actions .btn{width:100%}}
</style>
