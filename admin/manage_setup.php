<?php
declare(strict_types=1);

/**
 * EchoTech POS â€” Manage Setup / Branch Configuration
 * Modern Admin shell version.
 *
 * Uses the existing:
 *   includes/conn.php
 *   includes/auth.php
 *   admin/actions/admin_header.php
 *   admin/actions/admin_aside.php
 *
 * Branch fields follow the live branches schema.
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

$user_role = function_exists('current_role')
    ? current_role()
    : (string)($_SESSION['role'] ?? 'Admin');

$user_display_name = function_exists('current_user')
    ? current_user()
    : 'Administrator';

function ms_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ms_rows(mysqli $db, string $sql, string $types = '', array $values = []): array
{
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

function ms_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array
{
    $rows = ms_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}

function ms_csrf(): string
{
    if (empty($_SESSION['manage_setup_csrf'])) {
        $_SESSION['manage_setup_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['manage_setup_csrf'];
}

function ms_check_csrf(): void
{
    if (!hash_equals(
        (string)($_SESSION['manage_setup_csrf'] ?? ''),
        (string)($_POST['csrf'] ?? '')
    )) {
        http_response_code(419);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

function ms_slug(string $name, string $code = ''): string
{
    $base = trim($name . ($code !== '' ? '-' . $code : ''));
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/i', '-', $base) ?? '';
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'branch-' . bin2hex(random_bytes(3));
    }

    return substr($base, 0, 50);
}

function ms_log(mysqli $db, int $pharmacyId, int $userId, string $action, string $entityType, ?int $entityId, string $description): void
{
    $stmt = $db->prepare(
        'INSERT INTO compliance_audit_log
         (pharmacy_id,user_id,action,entity_type,entity_id,description,ip_address)
         VALUES (?,?,?,?,?,?,?)'
    );

    if (!$stmt) {
        return;
    }

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $stmt->bind_param(
        'iississ',
        $pharmacyId,
        $userId,
        $action,
        $entityType,
        $entityId,
        $description,
        $ip
    );
    @$stmt->execute();
    $stmt->close();
}

function ms_parse_bank(?string $value): array
{
    $result = [
        'bank_name' => '',
        'bank_branch_code' => '',
        'acc_name' => '',
        'acc_no' => '',
    ];

    $value = trim((string)$value);
    if ($value === '') {
        return $result;
    }

    foreach (explode(' | ', $value) as $part) {
        $parts = explode(':', $part, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = strtolower(trim($parts[0]));
        $val = trim($parts[1]);

        if ($key === 'bank') $result['bank_name'] = $val;
        elseif ($key === 'bcode') $result['bank_branch_code'] = $val;
        elseif ($key === 'acc') $result['acc_name'] = $val;
        elseif ($key === 'no') $result['acc_no'] = $val;
    }

    return $result;
}

function ms_parse_momo(?string $value): array
{
    $result = [
        'momo_mtn' => '',
        'momo_airtel' => '',
    ];

    $value = trim((string)$value);
    if ($value === '') {
        return $result;
    }

    foreach (explode(' | ', $value) as $part) {
        $parts = explode(':', $part, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = strtolower(trim($parts[0]));
        $val = trim($parts[1]);

        if ($key === 'mtn') $result['momo_mtn'] = $val;
        elseif ($key === 'airtel') $result['momo_airtel'] = $val;
    }

    return $result;
}

$csrf = ms_csrf();
$notice = '';
$error = '';

/* ---------- Pharmacy ---------- */
$pharmacy = ms_one(
    $adminDb,
    'SELECT id,name,logo,address,phone FROM pharmacies WHERE id=? LIMIT 1',
    'i',
    [$pharmacy_id]
);

if (!$pharmacy) {
    http_response_code(403);
    exit('Pharmacy not found.');
}

$pharmacy_name = (string)($pharmacy['name'] ?? 'PHARMACY POS');

/* ---------- Actions ---------- */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ms_check_csrf();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_branch') {
            $branch_id = (int)($_POST['branch_id'] ?? 0);

            $branch_name = trim((string)($_POST['branch_name'] ?? ''));
            $branch_code = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $location = trim((string)($_POST['location'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $branch_email = trim((string)($_POST['branch_email'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $bank_name = trim((string)($_POST['bank_name'] ?? ''));
            $bank_branch_code = trim((string)($_POST['bank_branch_code'] ?? ''));
            $acc_name = trim((string)($_POST['acc_name'] ?? ''));
            $acc_no = trim((string)($_POST['acc_no'] ?? ''));
            $momo_mtn = trim((string)($_POST['momo_mtn'] ?? ''));
            $momo_airtel = trim((string)($_POST['momo_airtel'] ?? ''));

            if ($branch_name === '') {
                throw new RuntimeException('Branch name is required.');
            }

            if (mb_strlen($branch_name) > 100) {
                throw new RuntimeException('Branch name cannot exceed 100 characters.');
            }

            if ($branch_code !== '' && mb_strlen($branch_code) > 10) {
                throw new RuntimeException('Branch code cannot exceed 10 characters.');
            }

            if (mb_strlen($location) > 255) {
                throw new RuntimeException('Location cannot exceed 255 characters.');
            }

            if (mb_strlen($phone) > 20) {
                throw new RuntimeException('Phone cannot exceed 20 characters.');
            }

            if ($branch_email !== '' && !filter_var($branch_email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid branch email address.');
            }

            if (mb_strlen($branch_email) > 100) {
                throw new RuntimeException('Branch email cannot exceed 100 characters.');
            }

            $duplicate = ms_one(
                $adminDb,
                'SELECT id
                 FROM branches
                 WHERE pharmacy_id=?
                   AND id<>?
                   AND (
                       (? <> "" AND branch_code=?)
                       OR LOWER(branch_name)=LOWER(?)
                   )
                 LIMIT 1',
                'iisss',
                [$pharmacy_id, $branch_id, $branch_code, $branch_code, $branch_name]
            );

            if ($duplicate) {
                throw new RuntimeException('Another branch already uses this branch name or branch code.');
            }

            $bank_combined = '';
            if ($bank_name !== '' || $bank_branch_code !== '' || $acc_name !== '' || $acc_no !== '') {
                $bank_combined =
                    'Bank: ' . $bank_name .
                    ' | BCode: ' . $bank_branch_code .
                    ' | Acc: ' . $acc_name .
                    ' | No: ' . $acc_no;
            }

            $momo_combined = '';
            if ($momo_mtn !== '' || $momo_airtel !== '') {
                $momo_combined =
                    'MTN: ' . $momo_mtn .
                    ' | Airtel: ' . $momo_airtel;
            }

            $slug = ms_slug($branch_name, $branch_code);

            if ($branch_id > 0) {
                $existing = ms_one(
                    $adminDb,
                    'SELECT id,branch_name,is_active
                     FROM branches
                     WHERE id=? AND pharmacy_id=?
                     LIMIT 1',
                    'ii',
                    [$branch_id, $pharmacy_id]
                );

                if (!$existing) {
                    throw new RuntimeException('The selected branch was not found.');
                }

                $stmt = $adminDb->prepare(
                    'UPDATE branches
                     SET branch_name=?,
                         branch_code=?,
                         branch_slug=?,
                         location=?,
                         phone=?,
                         branch_email=?,
                         is_active=?,
                         bank_details=?,
                         mobile_money_details=?
                     WHERE id=? AND pharmacy_id=?'
                );

                if (!$stmt) {
                    throw new RuntimeException($adminDb->error);
                }

                $stmt->bind_param(
                    'ssssssissii',
                    $branch_name,
                    $branch_code,
                    $slug,
                    $location,
                    $phone,
                    $branch_email,
                    $is_active,
                    $bank_combined,
                    $momo_combined,
                    $branch_id,
                    $pharmacy_id
                );

                if (!$stmt->execute()) {
                    $message = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException($message);
                }
                $stmt->close();

                ms_log(
                    $adminDb,
                    $pharmacy_id,
                    $current_user_id,
                    'UPDATE_BRANCH',
                    'branch',
                    $branch_id,
                    'Updated branch "' . $branch_name . '".'
                );

                $notice = 'Branch updated successfully.';
            } else {
                $stmt = $adminDb->prepare(
                    'INSERT INTO branches
                     (pharmacy_id,branch_code,branch_name,branch_slug,location,phone,branch_email,is_active,bank_details,mobile_money_details)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );

                if (!$stmt) {
                    throw new RuntimeException($adminDb->error);
                }

                $stmt->bind_param(
                    'issssssiss',
                    $pharmacy_id,
                    $branch_code,
                    $branch_name,
                    $slug,
                    $location,
                    $phone,
                    $branch_email,
                    $is_active,
                    $bank_combined,
                    $momo_combined
                );

                if (!$stmt->execute()) {
                    $message = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException($message);
                }

                $new_id = (int)$stmt->insert_id;
                $stmt->close();

                ms_log(
                    $adminDb,
                    $pharmacy_id,
                    $current_user_id,
                    'CREATE_BRANCH',
                    'branch',
                    $new_id,
                    'Created branch "' . $branch_name . '".'
                );

                $notice = 'Branch created successfully.';
            }
        } elseif ($action === 'toggle_branch') {
            $branch_id = (int)($_POST['branch_id'] ?? 0);

            $branch = ms_one(
                $adminDb,
                'SELECT id,branch_name,is_active
                 FROM branches
                 WHERE id=? AND pharmacy_id=?
                 LIMIT 1',
                'ii',
                [$branch_id, $pharmacy_id]
            );

            if (!$branch) {
                throw new RuntimeException('The selected branch was not found.');
            }

            $newStatus = (int)$branch['is_active'] === 1 ? 0 : 1;

            $stmt = $adminDb->prepare(
                'UPDATE branches SET is_active=? WHERE id=? AND pharmacy_id=?'
            );
            if (!$stmt) {
                throw new RuntimeException($adminDb->error);
            }

            $stmt->bind_param('iii', $newStatus, $branch_id, $pharmacy_id);

            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new RuntimeException($message);
            }
            $stmt->close();

            ms_log(
                $adminDb,
                $pharmacy_id,
                $current_user_id,
                $newStatus ? 'ACTIVATE_BRANCH' : 'DEACTIVATE_BRANCH',
                'branch',
                $branch_id,
                ($newStatus ? 'Activated' : 'Deactivated') . ' branch "' . $branch['branch_name'] . '".'
            );

            $notice = $newStatus
                ? 'Branch activated successfully.'
                : 'Branch deactivated successfully.';
        } elseif ($action === 'delete_branch') {
            $branch_id = (int)($_POST['branch_id'] ?? 0);

            $branch = ms_one(
                $adminDb,
                'SELECT id,branch_name
                 FROM branches
                 WHERE id=? AND pharmacy_id=?
                 LIMIT 1',
                'ii',
                [$branch_id, $pharmacy_id]
            );

            if (!$branch) {
                throw new RuntimeException('The selected branch was not found.');
            }

            /*
             * Deletion is only attempted when the branch has no operational
             * records. This protects historical sales, stock, orders, users,
             * prescriptions, expenses and other branch-linked records.
             */
            $checks = [
                ['sales', 'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=? AND branch_id=?'],
                ['stock', 'SELECT COUNT(*) AS c FROM store_items WHERE pharmacy_id=? AND branch_id=?'],
                ['orders', 'SELECT COUNT(*) AS c FROM clients_orders WHERE pharmacy_id=? AND branch_id=?'],
                ['users', 'SELECT COUNT(*) AS c FROM users WHERE pharmacy_id=? AND branch_id=?'],
                ['customers', 'SELECT COUNT(*) AS c FROM customers WHERE pharmacy_id=? AND branch_id=?'],
                ['prescriptions', 'SELECT COUNT(*) AS c FROM prescriptions WHERE pharmacy_id=? AND branch_id=?'],
                ['expenses', 'SELECT COUNT(*) AS c FROM expenses WHERE pharmacy_id=? AND branch_id=?'],
            ];

            foreach ($checks as [$label, $sql]) {
                if (!ms_one($adminDb, $sql, 'ii', [$pharmacy_id, $branch_id])) {
                    continue;
                }

                $count = (int)(ms_one($adminDb, $sql, 'ii', [$pharmacy_id, $branch_id])['c'] ?? 0);

                if ($count > 0) {
                    throw new RuntimeException(
                        'Branch "' . $branch['branch_name'] . '" cannot be deleted because it has '
                        . number_format($count) . ' ' . $label . ' record(s). Deactivate it instead.'
                    );
                }
            }

            $stmt = $adminDb->prepare(
                'DELETE FROM branches WHERE id=? AND pharmacy_id=?'
            );
            if (!$stmt) {
                throw new RuntimeException($adminDb->error);
            }

            $stmt->bind_param('ii', $branch_id, $pharmacy_id);

            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new RuntimeException(
                    'Branch could not be deleted. It may have linked records. Deactivate it instead. Database: ' . $message
                );
            }

            $stmt->close();

            ms_log(
                $adminDb,
                $pharmacy_id,
                $current_user_id,
                'DELETE_BRANCH',
                'branch',
                $branch_id,
                'Deleted empty branch "' . $branch['branch_name'] . '".'
            );

            $notice = 'Empty branch deleted successfully.';
        } else {
            throw new RuntimeException('Unknown branch action.');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ---------- Filters ---------- */
$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$location_filter = trim((string)($_GET['location'] ?? ''));

if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}

$where = ['b.pharmacy_id=?'];
$types = 'i';
$values = [$pharmacy_id];

if ($search !== '') {
    $where[] = '(b.branch_name LIKE ? OR b.branch_code LIKE ? OR b.phone LIKE ? OR b.branch_email LIKE ? OR b.location LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'sssss';
    array_push($values, $like, $like, $like, $like, $like);
}

if ($status === 'active') {
    $where[] = 'b.is_active=1';
} elseif ($status === 'inactive') {
    $where[] = 'b.is_active=0';
}

if ($location_filter !== '') {
    $where[] = 'b.location LIKE ?';
    $types .= 's';
    $values[] = '%' . $location_filter . '%';
}

$branches = ms_rows(
    $adminDb,
    'SELECT
        b.id,
        b.pharmacy_id,
        b.branch_code,
        b.branch_name,
        b.branch_slug,
        b.location,
        b.phone,
        b.branch_email,
        b.is_active,
        b.bank_details,
        b.mobile_money_details,

        (SELECT COUNT(*) FROM sales s
         WHERE s.pharmacy_id=b.pharmacy_id AND s.branch_id=b.id) AS sales_count,

        (SELECT COALESCE(SUM(COALESCE(s.total_amount,s.total,0)),0)
         FROM sales s
         WHERE s.pharmacy_id=b.pharmacy_id AND s.branch_id=b.id) AS sales_value,

        (SELECT COUNT(*) FROM store_items si
         WHERE si.pharmacy_id=b.pharmacy_id AND si.branch_id=b.id AND si.is_active=1) AS active_items,

        (SELECT COALESCE(SUM(CASE WHEN si.is_active=1 THEN si.quantity ELSE 0 END),0)
         FROM store_items si
         WHERE si.pharmacy_id=b.pharmacy_id AND si.branch_id=b.id) AS stock_units,

        (SELECT COUNT(*) FROM users u
         WHERE u.pharmacy_id=b.pharmacy_id AND u.branch_id=b.id) AS staff_count,

        (SELECT COUNT(*) FROM clients_orders co
         WHERE co.pharmacy_id=b.pharmacy_id AND co.branch_id=b.id) AS online_orders,

        (SELECT COUNT(*) FROM prescriptions pr
         WHERE pr.pharmacy_id=b.pharmacy_id AND pr.branch_id=b.id
           AND pr.status <> "Ready") AS pending_prescriptions

     FROM branches b
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY b.is_active DESC, b.branch_name ASC, b.id DESC',
    $types,
    $values
);

$all_stats = ms_one(
    $adminDb,
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) AS inactive
     FROM branches
     WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
) ?? [];

$active_count = (int)($all_stats['active'] ?? 0);
$inactive_count = (int)($all_stats['inactive'] ?? 0);
$total_count = (int)($all_stats['total'] ?? 0);

$network_sales = ms_one(
    $adminDb,
    'SELECT
        COUNT(*) AS c,
        COALESCE(SUM(COALESCE(total_amount,total,0)),0) AS value
     FROM sales
     WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
) ?? [];

$branch_count = $active_count;
$total_orders = (int)($network_sales['c'] ?? 0);

$current_admin_page = 'branches.php';
$admin_page_title = 'Branch Management';
?>
<?php require __DIR__ . '/actions/admin_aside.php'; ?>

<div class="manage-setup-main">
    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="manage-setup-content">
        <div class="page-head">
            <div>
                <div class="eyebrow"><i class="fas fa-store"></i> Network / Branch Operations</div>
                <h1>Manage Branches</h1>
                <p>Configure and maintain the operating locations for <strong><?= ms_h($pharmacy_name) ?></strong>. Branch data is restricted to your current pharmacy.</p>
            </div>

            <div class="head-actions">
                <button class="btn primary" type="button" onclick="newBranch()">
                    <i class="fas fa-plus"></i> Add Branch
                </button>
                <a class="btn light" href="branches.php">
                    <i class="fas fa-chart-line"></i> Branch Dashboard
                </a>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
            <div class="alert success">
                <i class="fas fa-circle-check"></i>
                <span><?= ms_h($notice) ?></span>
                <button type="button" onclick="this.parentElement.remove()">Ã—</button>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <i class="fas fa-circle-exclamation"></i>
                <span><?= ms_h($error) ?></span>
                <button type="button" onclick="this.parentElement.remove()">Ã—</button>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon blue"><i class="fas fa-store"></i></span>
                <div><small>Total Branches</small><strong><?= number_format($total_count) ?></strong><span>Operating locations</span></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon green"><i class="fas fa-circle-check"></i></span>
                <div><small>Active</small><strong><?= number_format($active_count) ?></strong><span>Currently operational</span></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon gray"><i class="fas fa-circle-pause"></i></span>
                <div><small>Inactive</small><strong><?= number_format($inactive_count) ?></strong><span>Not currently operational</span></div>
            </div>
            <div class="stat-card">
                <span class="stat-icon purple"><i class="fas fa-receipt"></i></span>
                <div><small>Network Sales</small><strong>K<?= number_format((float)($network_sales['value'] ?? 0), 2) ?></strong><span><?= number_format($total_orders) ?> recorded sales</span></div>
            </div>
        </section>

        <section class="filter-card">
            <form method="get" class="filters">
                <label class="field search-field">
                    <span>Search branches</span>
                    <div class="input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="search" name="q" value="<?= ms_h($search) ?>" placeholder="Branch name, code, phone or email">
                    </div>
                </label>

                <label class="field">
                    <span>Status</span>
                    <select name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active only</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
                    </select>
                </label>

                <label class="field">
                    <span>Location</span>
                    <input type="text" name="location" value="<?= ms_h($location_filter) ?>" placeholder="e.g. Lusaka">
                </label>

                <button class="btn primary filter-btn" type="submit">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>

                <?php if ($search !== '' || $status !== 'all' || $location_filter !== ''): ?>
                    <a class="btn light filter-btn" href="manage_setup.php">
                        <i class="fas fa-xmark"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </section>

        <section class="workspace-grid">
            <div class="card branch-list-card">
                <div class="card-head">
                    <div>
                        <h2><i class="fas fa-building"></i> Operating Locations</h2>
                        <span><?= number_format(count($branches)) ?> branch<?= count($branches) === 1 ? '' : 'es' ?> shown</span>
                    </div>
                    <div class="legend">
                        <span><i class="dot active"></i> Active</span>
                        <span><i class="dot inactive"></i> Inactive</span>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="branch-table">
                        <thead>
                            <tr>
                                <th>Branch Identity</th>
                                <th>Contact / Location</th>
                                <th>Operations</th>
                                <th>Financial</th>
                                <th>Status</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$branches): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty">
                                        <i class="fas fa-store-slash"></i>
                                        <strong>No branches found</strong>
                                        <span>Try clearing the filters or create a new branch.</span>
                                        <button type="button" class="btn primary" onclick="newBranch()"><i class="fas fa-plus"></i> Add Branch</button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($branches as $branch): ?>
                                <?php
                                $branchData = [
                                    'id' => (int)$branch['id'],
                                    'branch_code' => (string)($branch['branch_code'] ?? ''),
                                    'branch_name' => (string)($branch['branch_name'] ?? ''),
                                    'location' => (string)($branch['location'] ?? ''),
                                    'phone' => (string)($branch['phone'] ?? ''),
                                    'branch_email' => (string)($branch['branch_email'] ?? ''),
                                    'is_active' => (int)$branch['is_active'],
                                    'bank_details' => (string)($branch['bank_details'] ?? ''),
                                    'mobile_money_details' => (string)($branch['mobile_money_details'] ?? ''),
                                ];
                                ?>
                                <tr>
                                    <td>
                                        <div class="branch-identity">
                                            <span class="branch-avatar"><i class="fas fa-store"></i></span>
                                            <div>
                                                <strong><?= ms_h($branch['branch_name']) ?></strong>
                                                <span class="branch-code"><?= ms_h($branch['branch_code'] ?: 'No code') ?></span>
                                                <small><?= ms_h($branch['branch_slug'] ?: 'No slug') ?></small>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="contact-lines">
                                            <strong><?= ms_h($branch['location'] ?: 'Location not set') ?></strong>
                                            <span><?= ms_h($branch['phone'] ?: 'Phone not set') ?></span>
                                            <span><?= ms_h($branch['branch_email'] ?: 'Email not set') ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="metric-main"><?= number_format((int)$branch['sales_count']) ?> sales</div>
                                        <div class="metric-sub"><?= number_format((int)$branch['active_items']) ?> active items Â· <?= number_format((int)$branch['stock_units']) ?> units</div>
                                        <div class="metric-sub"><?= number_format((int)$branch['staff_count']) ?> staff Â· <?= number_format((int)$branch['online_orders']) ?> online orders</div>
                                    </td>

                                    <td>
                                        <div class="metric-main">K<?= number_format((float)$branch['sales_value'], 2) ?></div>
                                        <div class="metric-sub">Sales revenue</div>
                                        <div class="payment-state">
                                            <span class="<?= trim((string)$branch['bank_details']) !== '' ? 'configured' : 'missing' ?>">
                                                <i class="fas fa-building-columns"></i> Bank
                                            </span>
                                            <span class="<?= trim((string)$branch['mobile_money_details']) !== '' ? 'configured' : 'missing' ?>">
                                                <i class="fas fa-mobile-screen-button"></i> Mobile Money
                                            </span>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="status-pill <?= (int)$branch['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                            <i class="fas fa-circle"></i>
                                            <?= (int)$branch['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                        </span>
                                        <?php if ((int)$branch['pending_prescriptions'] > 0): ?>
                                            <small class="warning-note">
                                                <?= number_format((int)$branch['pending_prescriptions']) ?> pending prescriptions
                                            </small>
                                        <?php endif; ?>
                                    </td>

                                    <td class="actions-col">
                                        <div class="action-buttons">
                                            <a class="action-btn view" href="view_branch.php?id=<?= (int)$branch['id'] ?>" title="View branch dashboard">
                                                <i class="fas fa-chart-line"></i><span>View</span>
                                            </a>

                                            <button class="action-btn edit" type="button" onclick='editBranch(<?= ms_h(json_encode($branchData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)' title="Edit branch">
                                                <i class="fas fa-pen"></i><span>Edit</span>
                                            </button>

                                            <form method="post" class="inline-form" onsubmit="return confirmToggle(this)">
                                                <input type="hidden" name="csrf" value="<?= ms_h($csrf) ?>">
                                                <input type="hidden" name="action" value="toggle_branch">
                                                <input type="hidden" name="branch_id" value="<?= (int)$branch['id'] ?>">
                                                <button class="action-btn <?= (int)$branch['is_active'] === 1 ? 'pause' : 'activate' ?>" type="submit">
                                                    <i class="fas <?= (int)$branch['is_active'] === 1 ? 'fa-circle-pause' : 'fa-circle-play' ?>"></i>
                                                    <span><?= (int)$branch['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></span>
                                                </button>
                                            </form>

                                            <form method="post" class="inline-form" onsubmit="return confirmDelete(this, '<?= ms_h($branch['branch_name']) ?>')">
                                                <input type="hidden" name="csrf" value="<?= ms_h($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_branch">
                                                <input type="hidden" name="branch_id" value="<?= (int)$branch['id'] ?>">
                                                <button class="action-btn delete" type="submit">
                                                    <i class="fas fa-trash"></i><span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <aside class="card setup-card" id="branchEditor">
                <div class="card-head">
                    <div>
                        <h2><i class="fas fa-sliders"></i> <span id="formTitle">New Branch</span></h2>
                        <span id="formSubtitle">Create a new operating location.</span>
                    </div>
                    <button type="button" class="icon-clear" onclick="resetForm()" title="Clear form">
                        <i class="fas fa-rotate-left"></i>
                    </button>
                </div>

                <form method="post" id="branchForm" class="setup-form">
                    <input type="hidden" name="csrf" value="<?= ms_h($csrf) ?>">
                    <input type="hidden" name="action" value="save_branch">
                    <input type="hidden" name="branch_id" id="branch_id" value="">

                    <div class="section-label">Branch Identity</div>

                    <div class="form-grid two">
                        <label class="field">
                            <span>Branch Code *</span>
                            <input type="text" name="branch_code" id="branch_code" maxlength="10" placeholder="e.g. LSK-01">
                        </label>

                        <label class="field">
                            <span>Status</span>
                            <select name="is_active" id="is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </label>
                    </div>

                    <label class="field">
                        <span>Branch Name *</span>
                        <input type="text" name="branch_name" id="branch_name" maxlength="100" placeholder="Enter full branch name" required>
                    </label>

                    <div class="form-grid two">
                        <label class="field">
                            <span>Location / City</span>
                            <input type="text" name="location" id="location" maxlength="255" placeholder="e.g. Lusaka, Zambia">
                        </label>

                        <label class="field">
                            <span>Contact Phone</span>
                            <input type="text" name="phone" id="phone" maxlength="20" placeholder="+260...">
                        </label>
                    </div>

                    <label class="field">
                        <span>Branch Email</span>
                        <input type="email" name="branch_email" id="branch_email" maxlength="100" placeholder="branch@example.com">
                    </label>

                    <div class="section-label payment-label">Banking Details</div>
                    <div class="payment-box bank">
                        <div class="payment-title"><i class="fas fa-building-columns"></i><strong>Bank account</strong><span>Optional</span></div>

                        <div class="form-grid two">
                            <label class="field">
                                <span>Bank Name</span>
                                <input type="text" name="bank_name" id="bank_name" placeholder="e.g. Zanaco, FNB, ABSA">
                            </label>
                            <label class="field">
                                <span>Bank Branch Code</span>
                                <input type="text" name="bank_branch_code" id="bank_branch_code" placeholder="Branch code">
                            </label>
                        </div>

                        <div class="form-grid two">
                            <label class="field">
                                <span>Account Holder</span>
                                <input type="text" name="acc_name" id="acc_name" placeholder="Account holder name">
                            </label>
                            <label class="field">
                                <span>Account Number</span>
                                <input type="text" name="acc_no" id="acc_no" placeholder="Account number">
                            </label>
                        </div>
                    </div>

                    <div class="payment-box momo">
                        <div class="payment-title"><i class="fas fa-mobile-screen-button"></i><strong>Mobile Money</strong><span>Optional</span></div>

                        <div class="form-grid two">
                            <label class="field">
                                <span>MTN Number</span>
                                <input type="text" name="momo_mtn" id="momo_mtn" placeholder="MTN number">
                            </label>
                            <label class="field">
                                <span>Airtel Number</span>
                                <input type="text" name="momo_airtel" id="momo_airtel" placeholder="Airtel number">
                            </label>
                        </div>
                    </div>

                    <div class="form-note">
                        <i class="fas fa-circle-info"></i>
                        <span>Payment information is stored with the branch record and can be used by branch payment and online-order workflows.</span>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn light" onclick="resetForm()">
                            <i class="fas fa-xmark"></i> Clear
                        </button>
                        <button type="submit" class="btn primary" id="saveButton">
                            <i class="fas fa-floppy-disk"></i> Create Branch
                        </button>
                    </div>
                </form>
            </aside>
        </section>

        <section class="protection-grid">
            <div class="protection-card">
                <span><i class="fas fa-shield-halved"></i></span>
                <div>
                    <strong>Tenant protected</strong>
                    <p>All branch operations are restricted to the administrator's current <code>pharmacy_id</code>. A branch from another pharmacy cannot be edited or managed here.</p>
                </div>
            </div>

            <div class="protection-card">
                <span><i class="fas fa-history"></i></span>
                <div>
                    <strong>History protected</strong>
                    <p>Branches with sales, stock, staff, orders, expenses, prescriptions or other operational records cannot be permanently deleted. Deactivate them instead.</p>
                </div>
            </div>
        </section>
    </main>
</div>

<style>
.manage-setup-main{margin-left:var(--sidebar);min-height:100vh;background:var(--bg)}
.manage-setup-content{padding:24px 28px 42px;max-width:1700px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:18px}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1.1px;color:var(--blue);font-weight:850;margin-bottom:8px}
.page-head h1{margin:0;color:var(--text);font-size:27px;line-height:1.15;letter-spacing:-.5px}
.page-head p{margin:7px 0 0;color:var(--muted);font-size:12px;line-height:1.6;max-width:900px}
.head-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid transparent;border-radius:9px;min-height:39px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:11px;font-weight:800;text-decoration:none;cursor:pointer;white-space:nowrap}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.light{background:#fff;border-color:var(--border);color:#465363}
.alert{display:flex;align-items:center;gap:10px;border:1px solid;border-radius:10px;padding:11px 13px;margin-bottom:16px;font-size:11px;font-weight:750}.alert button{margin-left:auto;border:0;background:transparent;font-size:19px;color:inherit;cursor:pointer}.alert.success{background:var(--green-soft);border-color:#bce6d2;color:#19764f}.alert.error{background:var(--red-soft);border-color:#f2c3ca;color:#b43d4e}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:15px;display:flex;align-items:center;gap:12px}.stat-icon{width:39px;height:39px;border-radius:10px;display:grid;place-items:center;flex:0 0 39px}.stat-icon.blue{background:var(--blue-soft);color:var(--blue)}.stat-icon.green{background:var(--green-soft);color:var(--green)}.stat-icon.gray{background:#f0f2f4;color:#687582}.stat-icon.purple{background:#f0ecff;color:#7057c7}.stat-card small{display:block;color:#788490;font-size:8.5px;text-transform:uppercase;letter-spacing:.65px;font-weight:850}.stat-card strong{display:block;color:#1c2731;font-size:19px;margin-top:3px}.stat-card>div>span{display:block;color:#8a949e;font-size:8.5px;margin-top:2px}
.filter-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);margin-bottom:16px}.filters{padding:14px 16px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}.field{display:flex;flex-direction:column;gap:5px;min-width:150px}.field>span{font-size:8.5px;text-transform:uppercase;letter-spacing:.65px;color:#687582;font-weight:850}.field input,.field select{height:38px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);padding:0 10px;font-size:11px;outline:none}.field input:focus,.field select:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}.search-field{flex:1;min-width:280px}.input-wrap{position:relative}.input-wrap i{position:absolute;left:11px;top:13px;color:#8d98a4;font-size:11px}.input-wrap input{width:100%;padding-left:30px}.filter-btn{min-height:38px}
.workspace-grid{display:grid;grid-template-columns:minmax(0,1fr) 430px;gap:16px;align-items:start}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 18px;border-bottom:1px solid #edf0f3}.card-head h2{margin:0;color:var(--text);font-size:14px;font-weight:850}.card-head h2 i{color:var(--blue);font-size:12px;margin-right:7px}.card-head>div>span{display:block;margin-top:4px;color:var(--muted);font-size:9px}.legend{display:flex;gap:12px}.legend span{font-size:9px;color:#697582;display:flex;align-items:center;gap:5px}.dot{width:7px;height:7px;border-radius:50%;display:inline-block}.dot.active{background:#18a56c}.dot.inactive{background:#aab3bc}
.table-wrap{overflow:auto}.branch-table{width:100%;min-width:1050px;border-collapse:collapse}.branch-table th{background:#fafbfd;color:#6e7a86;text-transform:uppercase;letter-spacing:.65px;font-size:8px;font-weight:850;padding:11px 12px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}.branch-table td{padding:13px 12px;border-bottom:1px solid #edf0f3;vertical-align:middle;font-size:9.5px;color:#596572}.branch-table tbody tr:hover{background:#fbfcfe}.branch-table tr:last-child td{border-bottom:0}
.branch-identity{display:flex;align-items:center;gap:10px;min-width:170px}.branch-avatar{width:36px;height:36px;border-radius:9px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center;flex:0 0 36px}.branch-identity strong{display:block;color:#27323d;font-size:10.5px}.branch-code{display:inline-block;margin-top:3px;padding:3px 6px;border-radius:5px;background:#f0f3f6;color:#687582;font-size:8px;font-weight:850}.branch-identity small{display:block;color:#9aa3ac;font-size:7.5px;margin-top:3px}
.contact-lines strong{display:block;color:#33404b;font-size:9.5px}.contact-lines span{display:block;color:#8a949e;font-size:8.5px;margin-top:3px}
.metric-main{font-weight:850;color:#27333d;font-size:10px}.metric-sub{font-size:8px;color:#89939d;margin-top:3px}.payment-state{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.payment-state span{font-size:7.5px;font-weight:800}.payment-state .configured{color:#19764f}.payment-state .missing{color:#a2abb4}
.status-pill{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 8px;font-size:8px;font-weight:850}.status-pill i{font-size:6px}.status-pill.active{background:var(--green-soft);color:#19764f}.status-pill.inactive{background:#f0f2f4;color:#697582}.warning-note{display:block;color:#a87912;font-size:7.5px;margin-top:5px;font-weight:750}
.actions-col{min-width:255px}.action-buttons{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.inline-form{display:inline-flex;margin:0}.action-btn{height:32px;border:1px solid;border-radius:7px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;gap:5px;font-size:8px;font-weight:850;cursor:pointer;background:#fff;white-space:nowrap}.action-btn i{font-size:9px}.action-btn.view{border-color:#bdd0ff;color:var(--blue);background:#f8faff}.action-btn.edit{border-color:#d5c9f1;color:#7057c7;background:#fcfaff}.action-btn.pause{border-color:#ecdca8;color:#9a7210;background:#fffdf6}.action-btn.activate{border-color:#bce6d2;color:#19764f;background:#f7fffb}.action-btn.delete{border-color:#f2c3ca;color:#b43d4e;background:#fffafb}.action-btn:hover{filter:brightness(.98)}
.setup-card{position:sticky;top:80px}.icon-clear{width:31px;height:31px;border:1px solid var(--border);border-radius:8px;background:#fff;color:#687582;cursor:pointer}.setup-form{padding:16px 18px}.section-label{font-size:8.5px;text-transform:uppercase;letter-spacing:1px;color:var(--blue);font-weight:850;margin:1px 0 10px}.payment-label{margin-top:18px}.form-grid{display:grid;gap:10px}.form-grid.two{grid-template-columns:1fr 1fr}.setup-form>.field,.setup-form>.form-grid{margin-bottom:11px}.setup-form .field{min-width:0}.setup-form .field>span{font-size:8px}.setup-form .field input,.setup-form .field select{height:37px;font-size:10.5px}
.payment-box{border:1px solid #e6eaf0;border-radius:9px;padding:12px;margin-bottom:10px}.payment-box.bank{background:#fbfdff}.payment-box.momo{background:#fffdf9}.payment-title{display:flex;align-items:center;gap:7px;margin-bottom:10px}.payment-title i{color:var(--blue);font-size:11px}.momo .payment-title i{color:#b77b08}.payment-title strong{font-size:9.5px;color:#34404b}.payment-title span{margin-left:auto;color:#919aa3;font-size:7.5px;text-transform:uppercase;font-weight:800}
.form-note{display:flex;gap:8px;padding:9px;border-radius:8px;background:#f7f9fc;color:#75818c;font-size:8px;line-height:1.45}.form-note i{color:var(--blue);margin-top:1px}.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding-top:13px;border-top:1px solid #edf0f3}.form-actions .btn{min-height:37px}
.empty{text-align:center;padding:50px 20px;color:#89949f}.empty i{font-size:28px;color:#b5bec7;margin-bottom:10px}.empty strong{display:block;color:#596571;font-size:12px}.empty span{display:block;font-size:9px;margin:5px 0 13px}
.protection-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:16px}.protection-card{display:flex;gap:11px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:var(--shadow)}.protection-card>span{width:32px;height:32px;flex:0 0 32px;border-radius:8px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}.protection-card strong{font-size:10px;color:#2d3943}.protection-card p{margin:4px 0 0;color:#7d8893;font-size:8.5px;line-height:1.55}.protection-card code{font-size:8px}
@media(max-width:1250px){.workspace-grid{grid-template-columns:1fr}.setup-card{position:relative;top:auto}.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.manage-setup-main{margin-left:0}.manage-setup-content{padding:20px}.page-head{flex-direction:column;align-items:flex-start}.head-actions{width:100%}.head-actions .btn{flex:1}.protection-grid{grid-template-columns:1fr}}
@media(max-width:650px){.manage-setup-content{padding:16px 12px 30px}.stats-grid{grid-template-columns:1fr}.form-grid.two{grid-template-columns:1fr}.filters{padding:12px}.field,.search-field{width:100%;min-width:100%}.filter-btn{flex:1}.head-actions .btn{font-size:10px}.legend{display:none}}
</style>

<script>
(function(){
    const form = document.getElementById('branchForm');
    const branchId = document.getElementById('branch_id');
    const formTitle = document.getElementById('formTitle');
    const formSubtitle = document.getElementById('formSubtitle');
    const saveButton = document.getElementById('saveButton');

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value ?? '';
    }

    window.newBranch = function(){
        resetForm();
        const editor = document.getElementById('branchEditor');
        if (editor) editor.scrollIntoView({behavior:'smooth', block:'start'});
        setTimeout(function(){
            const name = document.getElementById('branch_name');
            if (name) name.focus();
        }, 250);
    };

    window.editBranch = function(data){
        setValue('branch_id', data.id);
        setValue('branch_code', data.branch_code);
        setValue('branch_name', data.branch_name);
        setValue('location', data.location);
        setValue('phone', data.phone);
        setValue('branch_email', data.branch_email);
        setValue('is_active', data.is_active);

        const bank = parseBank(data.bank_details || '');
        setValue('bank_name', bank.bank_name);
        setValue('bank_branch_code', bank.bank_branch_code);
        setValue('acc_name', bank.acc_name);
        setValue('acc_no', bank.acc_no);

        const momo = parseMomo(data.mobile_money_details || '');
        setValue('momo_mtn', momo.momo_mtn);
        setValue('momo_airtel', momo.momo_airtel);

        formTitle.textContent = 'Edit Branch';
        formSubtitle.textContent = 'Update ' + (data.branch_name || 'branch') + '.';
        saveButton.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Changes';

        const editor = document.getElementById('branchEditor');
        if (editor) editor.scrollIntoView({behavior:'smooth', block:'start'});
    };

    window.resetForm = function(){
        if (form) form.reset();
        setValue('branch_id', '');
        setValue('is_active', '1');
        formTitle.textContent = 'New Branch';
        formSubtitle.textContent = 'Create a new operating location.';
        saveButton.innerHTML = '<i class="fas fa-floppy-disk"></i> Create Branch';
    };

    function parseBank(value){
        const result = {bank_name:'', bank_branch_code:'', acc_name:'', acc_no:''};
        value.split(' | ').forEach(function(part){
            const pieces = part.split(':');
            if(pieces.length < 2) return;
            const key = pieces.shift().trim().toLowerCase();
            const val = pieces.join(':').trim();
            if(key === 'bank') result.bank_name = val;
            if(key === 'bcode') result.bank_branch_code = val;
            if(key === 'acc') result.acc_name = val;
            if(key === 'no') result.acc_no = val;
        });
        return result;
    }

    function parseMomo(value){
        const result = {momo_mtn:'', momo_airtel:''};
        value.split(' | ').forEach(function(part){
            const pieces = part.split(':');
            if(pieces.length < 2) return;
            const key = pieces.shift().trim().toLowerCase();
            const val = pieces.join(':').trim();
            if(key === 'mtn') result.momo_mtn = val;
            if(key === 'airtel') result.momo_airtel = val;
        });
        return result;
    }

    window.confirmToggle = function(formElement){
        const button = formElement.querySelector('button');
        const label = button ? button.textContent.trim() : 'change status';
        return window.confirm('Are you sure you want to ' + label.toLowerCase() + ' this branch?');
    };

    window.confirmDelete = function(formElement, branchName){
        return window.confirm(
            'Delete "' + branchName + '" permanently?\n\n' +
            'This is only allowed when the branch has no linked operational records. ' +
            'If it has history, the system will protect it and tell you to deactivate it instead.'
        );
    };
})();
</script>
