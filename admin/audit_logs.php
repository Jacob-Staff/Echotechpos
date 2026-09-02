<?php
declare(strict_types=1);

/**
 * EchoTech POS — Admin Audit & Security
 *
 * Uses:
 *   - compliance_audit_log for general/admin/compliance audit events
 *   - pos_zra_audit_log for ZRA/POS integration events
 *
 * The page is pharmacy-group scoped and read-only: audit history is never
 * silently deleted from the UI.
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
if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

$user_role = function_exists('current_role') ? current_role() : (string)($_SESSION['role'] ?? 'Admin');
$user_display_name = function_exists('current_user') ? current_user() : 'Administrator';

function al_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function al_rows(mysqli $db, string $sql, string $types = '', array $values = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    if ($types !== '') {
        $refs = [];
        foreach ($values as $key => &$value) {
            $refs[$key] = &$value;
        }
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

function al_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array {
    $rows = al_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}

function al_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function al_valid_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

$pharmacy = al_one(
    $adminDb,
    'SELECT id, name FROM pharmacies WHERE id=? LIMIT 1',
    'i',
    [$pharmacy_id]
);
if (!$pharmacy) {
    http_response_code(403);
    exit('Pharmacy not found.');
}
$pharmacy_name = (string)($pharmacy['name'] ?? 'PHARMACY POS');

$branches = al_rows(
    $adminDb,
    'SELECT id, branch_name, branch_code
     FROM branches
     WHERE pharmacy_id=?
     ORDER BY is_active DESC, branch_name ASC',
    'i',
    [$pharmacy_id]
);

$users = al_rows(
    $adminDb,
    'SELECT id, COALESCE(NULLIF(full_name,""), username) AS display_name, role
     FROM users
     WHERE pharmacy_id=?
     ORDER BY display_name ASC, id ASC',
    'i',
    [$pharmacy_id]
);

$branchMap = [];
foreach ($branches as $branch) {
    $branchMap[(int)$branch['id']] = (string)$branch['branch_name'];
}

$userMap = [];
foreach ($users as $user) {
    $userMap[(int)$user['id']] = (string)$user['display_name'];
}

/* ---------- Filters ---------- */
$period = (string)($_GET['period'] ?? '30');
$allowedPeriods = ['today', 'yesterday', '7', '30', '90', 'year', 'custom'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = '30';
}

$today = date('Y-m-d');
switch ($period) {
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = $start_date;
        break;
    case '7':
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = $today;
        break;
    case '90':
        $start_date = date('Y-m-d', strtotime('-89 days'));
        $end_date = $today;
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = $today;
        break;
    case 'custom':
        $start_date = (string)($_GET['start_date'] ?? date('Y-m-01'));
        $end_date = (string)($_GET['end_date'] ?? $today);
        if (!al_valid_date($start_date)) $start_date = date('Y-m-01');
        if (!al_valid_date($end_date)) $end_date = $today;
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = $today;
        break;
}
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$source = (string)($_GET['source'] ?? 'all');
if (!in_array($source, ['all', 'admin', 'zra'], true)) $source = 'all';

$action = trim((string)($_GET['action_filter'] ?? ''));
$user_id_filter = (int)($_GET['user_id'] ?? 0);
$branch_id_filter = (int)($_GET['branch_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));

if ($branch_id_filter > 0 && !isset($branchMap[$branch_id_filter])) {
    $branch_id_filter = 0;
}
if ($user_id_filter > 0 && !isset($userMap[$user_id_filter])) {
    $user_id_filter = 0;
}

$start_dt = $start_date . ' 00:00:00';
$end_dt = $end_date . ' 23:59:59';

/* ---------- Security posture ---------- */
$security = al_one(
    $adminDb,
    'SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN status="Active" THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN status<>"Active" OR status IS NULL THEN 1 ELSE 0 END) AS inactive_users,
        SUM(CASE WHEN is_frozen=1 THEN 1 ELSE 0 END) AS frozen_users,
        SUM(CASE WHEN role="Admin" THEN 1 ELSE 0 END) AS admin_users
     FROM users
     WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
) ?? [];

$audit_count = (int)(al_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM compliance_audit_log WHERE pharmacy_id=? AND created_at BETWEEN ? AND ?',
    'iss',
    [$pharmacy_id, $start_dt, $end_dt]
)['c'] ?? 0);

$zra_count = (int)(al_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM pos_zra_audit_log WHERE pharmacy_id=? AND created_at BETWEEN ? AND ?',
    'iss',
    [$pharmacy_id, $start_dt, $end_dt]
)['c'] ?? 0);

/* ---------- Action list ---------- */
$actions = [];
if (al_table_exists($adminDb, 'compliance_audit_log')) {
    $actionRows = al_rows(
        $adminDb,
        'SELECT DISTINCT action
         FROM compliance_audit_log
         WHERE pharmacy_id=? AND created_at BETWEEN ? AND ?
         ORDER BY action ASC',
        'iss',
        [$pharmacy_id, $start_dt, $end_dt]
    );
    foreach ($actionRows as $r) {
        $value = trim((string)($r['action'] ?? ''));
        if ($value !== '') $actions[] = $value;
    }
}
if (al_table_exists($adminDb, 'pos_zra_audit_log')) {
    $actionRows = al_rows(
        $adminDb,
        'SELECT DISTINCT action
         FROM pos_zra_audit_log
         WHERE pharmacy_id=? AND created_at BETWEEN ? AND ?
         ORDER BY action ASC',
        'iss',
        [$pharmacy_id, $start_dt, $end_dt]
    );
    foreach ($actionRows as $r) {
        $value = trim((string)($r['action'] ?? ''));
        if ($value !== '' && !in_array($value, $actions, true)) $actions[] = $value;
    }
}
sort($actions, SORT_NATURAL | SORT_FLAG_CASE);

/* ---------- Fetch filtered general audit records ---------- */
$events = [];

if ($source !== 'zra' && al_table_exists($adminDb, 'compliance_audit_log')) {
    $where = ['l.pharmacy_id=?', 'l.created_at BETWEEN ? AND ?'];
    $types = 'iss';
    $values = [$pharmacy_id, $start_dt, $end_dt];

    if ($action !== '') {
        $where[] = 'l.action=?';
        $types .= 's';
        $values[] = $action;
    }
    if ($user_id_filter > 0) {
        $where[] = 'l.user_id=?';
        $types .= 'i';
        $values[] = $user_id_filter;
    }
    if ($branch_id_filter > 0) {
        $where[] = '(ub.branch_id=? OR (l.entity_type="branch" AND l.entity_id=?))';
        $types .= 'ii';
        $values[] = $branch_id_filter;
        $values[] = $branch_id_filter;
    }
    if ($search !== '') {
        $where[] = '(l.action LIKE ? OR l.entity_type LIKE ? OR l.description LIKE ? OR l.ip_address LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssssss';
        array_push($values, $like, $like, $like, $like, $like, $like);
    }

    $rows = al_rows(
        $adminDb,
        'SELECT
            l.id,
            "ADMIN" AS source,
            l.action,
            "—" AS status,
            l.entity_type,
            l.entity_id,
            l.description,
            l.ip_address,
            l.created_at,
            l.user_id,
            COALESCE(NULLIF(u.full_name,""),u.username,"System") AS user_name,
            COALESCE(eb.branch_name, ub.branch_name, "—") AS branch_name
         FROM compliance_audit_log l
         LEFT JOIN users u ON u.id=l.user_id AND u.pharmacy_id=l.pharmacy_id
         LEFT JOIN branches ub ON ub.id=u.branch_id AND ub.pharmacy_id=l.pharmacy_id
         LEFT JOIN branches eb ON eb.id=l.entity_id
             AND l.entity_type="branch"
             AND eb.pharmacy_id=l.pharmacy_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 500',
        $types,
        $values
    );

    foreach ($rows as $row) {
        $events[] = $row;
    }
}

/* ---------- Fetch filtered ZRA audit records ---------- */
if ($source !== 'admin' && al_table_exists($adminDb, 'pos_zra_audit_log')) {
    $where = ['l.pharmacy_id=?', 'l.created_at BETWEEN ? AND ?'];
    $types = 'iss';
    $values = [$pharmacy_id, $start_dt, $end_dt];

    if ($action !== '') {
        $where[] = 'l.action=?';
        $types .= 's';
        $values[] = $action;
    }
    if ($branch_id_filter > 0) {
        $where[] = 'l.branch_id=?';
        $types .= 'i';
        $values[] = $branch_id_filter;
    }
    if ($search !== '') {
        $where[] = '(l.action LIKE ? OR l.status LIKE ? OR l.message LIKE ? OR l.response_code LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($values, $like, $like, $like, $like);
    }

    $rows = al_rows(
        $adminDb,
        'SELECT
            l.id,
            "ZRA" AS source,
            l.action,
            l.status,
            "zra" AS entity_type,
            l.zra_invoice_id AS entity_id,
            l.message AS description,
            NULL AS ip_address,
            l.created_at,
            NULL AS user_id,
            "ZRA / System" AS user_name,
            COALESCE(b.branch_name,"—") AS branch_name
         FROM pos_zra_audit_log l
         LEFT JOIN branches b ON b.id=l.branch_id AND b.pharmacy_id=l.pharmacy_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 500',
        $types,
        $values
    );

    foreach ($rows as $row) {
        $events[] = $row;
    }
}

usort($events, static function (array $a, array $b): int {
    return strcmp((string)$b['created_at'], (string)$a['created_at']);
});
$events = array_slice($events, 0, 500);

/* ---------- CSV export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'echotech_audit_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $fp = fopen('php://output', 'wb');
    fputcsv($fp, ['Date/Time','Source','Action','Status','Entity','Entity ID','User','Branch','IP Address','Description']);
    foreach ($events as $event) {
        fputcsv($fp, [
            $event['created_at'],
            $event['source'],
            $event['action'],
            $event['status'],
            $event['entity_type'],
            $event['entity_id'],
            $event['user_name'],
            $event['branch_name'],
            $event['ip_address'],
            $event['description'],
        ]);
    }
    fclose($fp);
    exit;
}

$branch_count = (int)(al_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$total_orders = (int)(al_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$current_admin_page = 'audit_logs.php';
$admin_page_title = 'Audit & Security';
?>
<?php require __DIR__ . '/actions/admin_aside.php'; ?>
<div class="audit-admin-main">
    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="audit-content">
        <div class="page-head">
            <div>
                <div class="eyebrow"><i class="fas fa-shield-halved"></i> Network / Security & Governance</div>
                <h1>Audit &amp; Security</h1>
                <p>Review administrative activity, ZRA/POS audit events and the current security posture of <strong><?= al_h($pharmacy_name) ?></strong>. Records are pharmacy-scoped and audit history is read-only.</p>
            </div>
            <div class="head-actions">
                <a class="btn light" href="audit_logs.php"><i class="fas fa-rotate"></i> Refresh</a>
                <a class="btn primary" href="<?= al_h($_SERVER['REQUEST_URI'] . (str_contains((string)$_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'export=csv') ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </div>

        <section class="security-grid">
            <div class="security-card">
                <div class="security-icon blue"><i class="fas fa-shield-halved"></i></div>
                <div><span>Audit Events</span><strong><?= number_format(count($events)) ?></strong><small><?= number_format($audit_count) ?> admin · <?= number_format($zra_count) ?> ZRA in period</small></div>
            </div>
            <div class="security-card">
                <div class="security-icon green"><i class="fas fa-user-check"></i></div>
                <div><span>Active Users</span><strong><?= number_format((int)($security['active_users'] ?? 0)) ?></strong><small><?= number_format((int)($security['total_users'] ?? 0)) ?> total accounts</small></div>
            </div>
            <div class="security-card">
                <div class="security-icon red"><i class="fas fa-user-lock"></i></div>
                <div><span>Frozen Accounts</span><strong><?= number_format((int)($security['frozen_users'] ?? 0)) ?></strong><small>Review before allowing access</small></div>
            </div>
            <div class="security-card">
                <div class="security-icon purple"><i class="fas fa-user-shield"></i></div>
                <div><span>Administrators</span><strong><?= number_format((int)($security['admin_users'] ?? 0)) ?></strong><small>Admin accounts in this pharmacy</small></div>
            </div>
        </section>

        <section class="card posture-card">
            <div class="card-head">
                <div><h2><i class="fas fa-lock"></i> Security posture</h2><span>Current account controls available in the live database</span></div>
                <a class="text-link" href="settings.php?tab=access">Access Control <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="posture-grid">
                <div><span class="posture-dot good"></span><div><strong>Tenant isolation</strong><small>Audit queries are restricted to the current pharmacy.</small></div></div>
                <div><span class="posture-dot good"></span><div><strong>Audit history protected</strong><small>No delete or purge action is exposed here.</small></div></div>
                <div><span class="posture-dot <?= (int)($security['frozen_users'] ?? 0) > 0 ? 'warn' : 'good' ?>"></span><div><strong>Frozen accounts</strong><small><?= (int)($security['frozen_users'] ?? 0) > 0 ? 'There are frozen user accounts requiring review.' : 'No frozen accounts found.' ?></small></div></div>
                <div><span class="posture-dot <?= (int)($security['inactive_users'] ?? 0) > 0 ? 'warn' : 'good' ?>"></span><div><strong>Inactive accounts</strong><small><?= number_format((int)($security['inactive_users'] ?? 0)) ?> account(s) are not marked Active.</small></div></div>
            </div>
        </section>

        <section class="card filter-card">
            <div class="card-head">
                <div><h2><i class="fas fa-filter"></i> Audit Filters</h2><span>Search and narrow the security history without changing the records.</span></div>
                <a class="btn light small" href="audit_logs.php"><i class="fas fa-broom"></i> Clear</a>
            </div>
            <form method="get" class="filters">
                <label class="field grow"><span>Search</span><div class="input-icon"><i class="fas fa-search"></i><input name="q" value="<?= al_h($search) ?>" placeholder="Action, description, user, IP or response code"></div></label>
                <label class="field"><span>Period</span><select name="period" id="auditPeriod">
                    <option value="today" <?= $period==='today'?'selected':'' ?>>Today</option>
                    <option value="yesterday" <?= $period==='yesterday'?'selected':'' ?>>Yesterday</option>
                    <option value="7" <?= $period==='7'?'selected':'' ?>>Last 7 Days</option>
                    <option value="30" <?= $period==='30'?'selected':'' ?>>Last 30 Days</option>
                    <option value="90" <?= $period==='90'?'selected':'' ?>>Last 90 Days</option>
                    <option value="year" <?= $period==='year'?'selected':'' ?>>This Year</option>
                    <option value="custom" <?= $period==='custom'?'selected':'' ?>>Custom Range</option>
                </select></label>
                <label class="field"><span>Source</span><select name="source">
                    <option value="all" <?= $source==='all'?'selected':'' ?>>All sources</option>
                    <option value="admin" <?= $source==='admin'?'selected':'' ?>>Admin / Compliance</option>
                    <option value="zra" <?= $source==='zra'?'selected':'' ?>>ZRA / POS</option>
                </select></label>
                <label class="field"><span>Action</span><select name="action_filter">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $actionOption): ?>
                        <option value="<?= al_h($actionOption) ?>" <?= $action===$actionOption?'selected':'' ?>><?= al_h($actionOption) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="field"><span>User</span><select name="user_id">
                    <option value="0">All users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int)$user['id'] ?>" <?= $user_id_filter===(int)$user['id']?'selected':'' ?>><?= al_h($user['display_name']) ?> · <?= al_h($user['role']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="field"><span>Branch</span><select name="branch_id">
                    <option value="0">All branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $branch_id_filter===(int)$branch['id']?'selected':'' ?>><?= al_h($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <div class="custom-dates" id="customDates" style="<?= $period==='custom'?'display:grid':'display:none' ?>">
                    <label class="field"><span>From</span><input type="date" name="start_date" value="<?= al_h($start_date) ?>"></label>
                    <label class="field"><span>To</span><input type="date" name="end_date" value="<?= al_h($end_date) ?>"></label>
                </div>
                <button class="btn primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
            </form>
            <div class="quick-periods">
                <a class="<?= $period==='today'?'active':'' ?>" href="audit_logs.php?period=today">Today</a>
                <a class="<?= $period==='7'?'active':'' ?>" href="audit_logs.php?period=7">7 Days</a>
                <a class="<?= $period==='30'?'active':'' ?>" href="audit_logs.php?period=30">30 Days</a>
                <a class="<?= $period==='90'?'active':'' ?>" href="audit_logs.php?period=90">90 Days</a>
                <a class="<?= $period==='year'?'active':'' ?>" href="audit_logs.php?period=year">This Year</a>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div><h2><i class="fas fa-list-check"></i> Audit Event Register</h2><span><?= al_h(date('d M Y', strtotime($start_date))) ?> — <?= al_h(date('d M Y', strtotime($end_date))) ?> · maximum 500 displayed events</span></div>
                <div class="register-meta"><span><b><?= number_format(count($events)) ?></b> shown</span></div>
            </div>
            <div class="table-wrap">
                <table class="audit-table">
                    <thead><tr><th>Date / Time</th><th>Source</th><th>Action</th><th>User</th><th>Branch</th><th>Status</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (!$events): ?>
                        <tr><td colspan="9" class="empty"><i class="fas fa-shield"></i><strong>No audit events found</strong><span>Try a wider date range or clear one of the filters.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <?php
                            $statusText = trim((string)($event['status'] ?? '—'));
                            $statusLower = strtolower($statusText);
                            $statusClass = in_array($statusLower, ['success','ok','completed','posted','online'], true) ? 'success' :
                                (in_array($statusLower, ['error','failed','failure','offline'], true) ? 'danger' : 'neutral');
                            ?>
                            <tr>
                                <td><strong><?= al_h(date('d M Y', strtotime($event['created_at']))) ?></strong><small><?= al_h(date('H:i:s', strtotime($event['created_at']))) ?></small></td>
                                <td><span class="source-pill <?= strtolower($event['source']) ?>"><?= al_h($event['source']) ?></span></td>
                                <td><strong><?= al_h($event['action']) ?></strong><small><?= al_h($event['entity_type'] ?: 'system') ?></small></td>
                                <td><?= al_h($event['user_name']) ?></td>
                                <td><?= al_h($event['branch_name']) ?></td>
                                <td><span class="status-pill <?= $statusClass ?>"><?= al_h($statusText) ?></span></td>
                                <td><?= $event['entity_id'] !== null && $event['entity_id'] !== '' ? al_h($event['entity_type'] . ' #' . $event['entity_id']) : '—' ?></td>
                                <td class="description"><?= al_h($event['description'] ?: 'No description recorded.') ?></td>
                                <td class="ip"><?= al_h($event['ip_address'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="info-grid">
            <div class="info-card"><span><i class="fas fa-circle-info"></i></span><div><strong>What is recorded here?</strong><p>General administrative/compliance events come from <code>compliance_audit_log</code>. ZRA/POS integration events come from <code>pos_zra_audit_log</code>.</p></div></div>
            <div class="info-card"><span><i class="fas fa-ban"></i></span><div><strong>Why there is no delete button</strong><p>Audit history is evidence. The admin UI intentionally does not provide a purge or delete action, protecting the integrity of historical records.</p></div></div>
        </section>
    </main>
</div>

<style>
.audit-admin-main{margin-left:var(--sidebar);min-height:100vh;background:var(--bg)}
.audit-content{padding:24px 28px 42px;max-width:1600px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:18px}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1.1px;color:var(--blue);font-weight:850;margin-bottom:8px}
.page-head h1{margin:0;font-size:27px;line-height:1.15;color:var(--text);letter-spacing:-.5px}
.page-head p{margin:7px 0 0;max-width:900px;color:var(--muted);font-size:12px;line-height:1.6}
.head-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid transparent;border-radius:9px;min-height:39px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:11px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.btn.light{background:#fff;color:#465363;border-color:var(--border)}
.btn.small{min-height:33px;padding:0 11px}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);margin-bottom:16px;overflow:hidden}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:15px 18px;border-bottom:1px solid #edf0f3}
.card-head h2{margin:0;font-size:14px;color:var(--text);font-weight:850}.card-head h2 i{color:var(--blue);margin-right:7px;font-size:12px}
.card-head span{display:block;margin-top:4px;color:var(--muted);font-size:10px}
.text-link{color:var(--blue);font-size:10px;font-weight:800;text-decoration:none}
.security-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.security-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px;box-shadow:var(--shadow)}
.security-icon{width:38px;height:38px;flex:0 0 38px;border-radius:9px;display:grid;place-items:center}
.security-icon.blue{background:var(--blue-soft);color:var(--blue)}.security-icon.green{background:var(--green-soft);color:var(--green)}.security-icon.red{background:var(--red-soft);color:#c64b5c}.security-icon.purple{background:#f0ecff;color:var(--purple)}
.security-card span{display:block;text-transform:uppercase;letter-spacing:.7px;font-size:9px;color:#77828e;font-weight:850}.security-card strong{display:block;color:#18232d;font-size:22px;margin-top:4px}.security-card small{display:block;color:#89939e;font-size:9px;margin-top:2px}
.posture-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#edf0f3}
.posture-grid>div{background:#fff;padding:15px;display:flex;gap:10px;align-items:flex-start}.posture-dot{width:8px;height:8px;border-radius:50%;margin-top:4px;flex:0 0 8px}.posture-dot.good{background:var(--green)}.posture-dot.warn{background:#e0a21a}.posture-grid strong{display:block;font-size:11px;color:#27323c}.posture-grid small{display:block;font-size:9px;line-height:1.5;color:#7c8792;margin-top:3px}
.filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;padding:14px 18px}.field{display:flex;flex-direction:column;gap:5px;min-width:145px}.field.grow{flex:1;min-width:250px}.field>span{font-size:9px;text-transform:uppercase;letter-spacing:.7px;color:#687582;font-weight:850}.field input,.field select{height:38px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);padding:0 10px;font-size:11px;outline:none}.field input:focus,.field select:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
.input-icon{position:relative}.input-icon i{position:absolute;left:11px;top:13px;color:#8d98a4;font-size:11px}.input-icon input{width:100%;padding-left:30px}
.custom-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:310px}.quick-periods{display:flex;gap:6px;flex-wrap:wrap;padding:0 18px 13px}.quick-periods a{padding:7px 10px;border:1px solid #e1e6eb;border-radius:7px;background:#fff;color:#687582;font-size:9px;font-weight:800;text-decoration:none}.quick-periods a.active{background:var(--blue-soft);border-color:#b9ceff;color:var(--blue)}
.register-meta{font-size:10px;color:#77828d}.register-meta b{color:#1d2934}
.table-wrap{overflow:auto}.audit-table{width:100%;min-width:1250px;border-collapse:collapse}.audit-table th{background:#fafbfd;color:#697582;text-transform:uppercase;letter-spacing:.65px;font-size:8.5px;font-weight:850;padding:11px 12px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}.audit-table td{padding:11px 12px;border-bottom:1px solid #edf0f3;font-size:10px;color:#4b5763;vertical-align:top}.audit-table tbody tr:hover{background:#fbfcfe}.audit-table td strong{display:block;color:#26313b;font-size:10px}.audit-table td small{display:block;color:#89949f;font-size:8.5px;margin-top:3px}.audit-table .description{max-width:340px;line-height:1.5}.audit-table .ip{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:9px}
.source-pill,.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 7px;font-size:8px;font-weight:850}.source-pill.admin{background:var(--blue-soft);color:var(--blue)}.source-pill.zra{background:#f0ecff;color:#7057c7}.status-pill.success{background:var(--green-soft);color:#19764f}.status-pill.danger{background:var(--red-soft);color:#b43d4e}.status-pill.neutral{background:#f1f3f5;color:#697582}
.empty{text-align:center;padding:50px 20px!important;color:#8994a0!important}.empty i{display:block;font-size:28px;margin-bottom:10px;color:#b4bec7}.empty strong{display:block;font-size:13px;color:#56616d}.empty span{display:block;font-size:9px;margin-top:4px}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.info-card{display:flex;gap:11px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:var(--shadow)}.info-card>span{width:31px;height:31px;flex:0 0 31px;border-radius:8px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}.info-card strong{font-size:11px;color:#27323c}.info-card p{margin:4px 0 0;color:#7a8691;font-size:9px;line-height:1.55}.info-card code{font-size:8px}
@media(max-width:1100px){.security-grid{grid-template-columns:repeat(2,1fr)}.posture-grid{grid-template-columns:repeat(2,1fr)}.page-head{align-items:flex-start}.custom-dates{width:100%}}
@media(max-width:900px){.audit-admin-main{margin-left:0}.audit-content{padding:20px}.page-head{flex-direction:column}.head-actions{width:100%}.head-actions .btn{flex:1}}
@media(max-width:650px){.audit-content{padding:16px 12px 30px}.security-grid,.posture-grid,.info-grid{grid-template-columns:1fr}.filters{padding:13px}.field,.field.grow{min-width:100%;width:100%}.custom-dates{grid-template-columns:1fr;width:100%}.custom-dates .field{width:100%}}
</style>

<script>
(function(){
    const period = document.getElementById('auditPeriod');
    const custom = document.getElementById('customDates');
    function syncDates(){
        if(!period || !custom) return;
        custom.style.display = period.value === 'custom' ? 'grid' : 'none';
    }
    if(period){
        period.addEventListener('change', syncDates);
        syncDates();
    }
})();
</script>
