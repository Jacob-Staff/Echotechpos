<?php
declare(strict_types=1);

/**
 * EchoTech POS â€” Admin Branch Management
 * Uses the same authentication, DB connection, header and aside architecture
 * as the working Admin Dashboard / Reports pages.
 */

session_start();
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

function bm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bm_money(mixed $value): string
{
    return 'K' . number_format((float)$value, 2);
}

function bm_csrf(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['admin_csrf'];
}

function bm_check_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

function bm_bind(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($values as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function bm_rows(mysqli $db, string $sql, string $types = '', array $values = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    if ($types !== '') {
        bm_bind($stmt, $types, $values);
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

function bm_one(mysqli $db, string $sql, string $types = '', array $values = []): ?array
{
    $rows = bm_rows($db, $sql, $types, $values);
    return $rows[0] ?? null;
}

function bm_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function bm_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return substr($slug, 0, 50);
}

function bm_unique_code(mysqli $db, string $preferred = ''): string
{
    $preferred = strtoupper(trim($preferred));
    if ($preferred !== '') {
        return substr($preferred, 0, 10);
    }

    for ($i = 0; $i < 8; $i++) {
        $candidate = 'BR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $row = bm_one($db, 'SELECT id FROM branches WHERE branch_code=? LIMIT 1', 's', [$candidate]);
        if (!$row) {
            return $candidate;
        }
    }

    return 'BR-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function bm_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$csrf = bm_csrf();
$notice = '';
$error = '';

/* ---------- Pharmacy / shared-header data ---------- */
$pharmacy = bm_one(
    $adminDb,
    'SELECT id, name, logo, address, phone FROM pharmacies WHERE id=? LIMIT 1',
    'i',
    [$pharmacy_id]
);

if (!$pharmacy) {
    http_response_code(403);
    exit('The pharmacy assigned to this administrator could not be found.');
}

$pharmacy_name = (string)($pharmacy['name'] ?? 'PHARMACY POS');

/* ---------- POST actions ---------- */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bm_check_csrf();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_branch') {
            $branch_id = (int)($_POST['id'] ?? 0);

            $branch_code = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $branch_name = trim((string)($_POST['branch_name'] ?? ''));
            $branch_slug = trim((string)($_POST['branch_slug'] ?? ''));
            $location = trim((string)($_POST['location'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $branch_email = trim((string)($_POST['branch_email'] ?? ''));
            $bank_details = trim((string)($_POST['bank_details'] ?? ''));
            $mobile_money_details = trim((string)($_POST['mobile_money_details'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($branch_name === '') {
                throw new RuntimeException('Branch name is required.');
            }
            if (mb_strlen($branch_name) > 100) {
                throw new RuntimeException('Branch name cannot exceed 100 characters.');
            }
            if ($branch_code !== '' && mb_strlen($branch_code) > 10) {
                throw new RuntimeException('Branch code cannot exceed 10 characters.');
            }
            if ($branch_slug !== '' && mb_strlen($branch_slug) > 50) {
                throw new RuntimeException('Branch slug cannot exceed 50 characters.');
            }
            if ($location !== '' && mb_strlen($location) > 255) {
                throw new RuntimeException('Location cannot exceed 255 characters.');
            }
            if ($phone !== '' && mb_strlen($phone) > 20) {
                throw new RuntimeException('Phone cannot exceed 20 characters.');
            }
            if ($branch_email !== '' && (!filter_var($branch_email, FILTER_VALIDATE_EMAIL) || mb_strlen($branch_email) > 100)) {
                throw new RuntimeException('Please enter a valid branch email address.');
            }

            $branch_slug = $branch_slug !== '' ? bm_slug($branch_slug) : bm_slug($branch_name);
            $branch_code = bm_unique_code($adminDb, $branch_code);

            $duplicate = bm_one(
                $adminDb,
                'SELECT id FROM branches WHERE branch_code=? AND id<>? LIMIT 1',
                'si',
                [$branch_code, $branch_id]
            );
            if ($duplicate) {
                throw new RuntimeException('That branch code is already in use. Please choose another code.');
            }

            if ($branch_id > 0) {
                $existing = bm_one(
                    $adminDb,
                    'SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1',
                    'ii',
                    [$branch_id, $pharmacy_id]
                );
                if (!$existing) {
                    throw new RuntimeException('The selected branch does not belong to this pharmacy.');
                }

                $stmt = $adminDb->prepare(
                    'UPDATE branches
                     SET branch_code=?, branch_name=?, branch_slug=?, location=?, phone=?,
                         branch_email=?, is_active=?, bank_details=?, mobile_money_details=?
                     WHERE id=? AND pharmacy_id=?'
                );
                if (!$stmt) {
                    throw new RuntimeException($adminDb->error);
                }

                $stmt->bind_param(
                    'ssssssissii',
                    $branch_code,
                    $branch_name,
                    $branch_slug,
                    $location,
                    $phone,
                    $branch_email,
                    $is_active,
                    $bank_details,
                    $mobile_money_details,
                    $branch_id,
                    $pharmacy_id
                );

                if (!$stmt->execute()) {
                    $msg = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException($msg);
                }
                $stmt->close();

                $notice = 'Branch updated successfully.';
            } else {
                $stmt = $adminDb->prepare(
                    'INSERT INTO branches
                     (pharmacy_id, branch_code, branch_name, branch_slug, location, phone,
                      branch_email, is_active, bank_details, mobile_money_details)
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
                    $branch_slug,
                    $location,
                    $phone,
                    $branch_email,
                    $is_active,
                    $bank_details,
                    $mobile_money_details
                );

                if (!$stmt->execute()) {
                    $msg = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException($msg);
                }
                $new_branch_id = $stmt->insert_id;
                $stmt->close();

                $notice = 'Branch created successfully. Branch #' . $new_branch_id . ' is now available.';
            }
        } elseif ($action === 'toggle_branch') {
            $branch_id = (int)($_POST['id'] ?? 0);
            $branch = bm_one(
                $adminDb,
                'SELECT id, branch_name, is_active FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1',
                'ii',
                [$branch_id, $pharmacy_id]
            );
            if (!$branch) {
                throw new RuntimeException('Branch not found.');
            }

            $new_status = ((int)$branch['is_active'] === 1) ? 0 : 1;

            if ($new_status === 0) {
                $active_count = (int)(bm_one(
                    $adminDb,
                    'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1',
                    'i',
                    [$pharmacy_id]
                )['c'] ?? 0);

                if ($active_count <= 1) {
                    throw new RuntimeException('You cannot deactivate the only active branch. Activate another branch first.');
                }
            }

            $stmt = $adminDb->prepare('UPDATE branches SET is_active=? WHERE id=? AND pharmacy_id=?');
            if (!$stmt) {
                throw new RuntimeException($adminDb->error);
            }
            $stmt->bind_param('iii', $new_status, $branch_id, $pharmacy_id);
            if (!$stmt->execute()) {
                $msg = $stmt->error;
                $stmt->close();
                throw new RuntimeException($msg);
            }
            $stmt->close();

            $notice = $new_status ? 'Branch activated successfully.' : 'Branch deactivated successfully.';
        } elseif ($action === 'delete_branch') {
            $branch_id = (int)($_POST['id'] ?? 0);

            $branch = bm_one(
                $adminDb,
                'SELECT id, branch_name, is_active FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1',
                'ii',
                [$branch_id, $pharmacy_id]
            );
            if (!$branch) {
                throw new RuntimeException('Branch not found.');
            }

            $dependencyLabels = [
                'sales' => ['sql' => 'SELECT COUNT(*) AS c FROM sales WHERE branch_id=?', 'types' => 'i'],
                'sales_items' => ['sql' => 'SELECT COUNT(*) AS c FROM sales_items WHERE branch_id=?', 'types' => 'i'],
                'store_items' => ['sql' => 'SELECT COUNT(*) AS c FROM store_items WHERE branch_id=?', 'types' => 'i'],
                'users' => ['sql' => 'SELECT COUNT(*) AS c FROM users WHERE branch_id=?', 'types' => 'i'],
                'clients_orders' => ['sql' => 'SELECT COUNT(*) AS c FROM clients_orders WHERE branch_id=?', 'types' => 'i'],
                'clients_order_items' => ['sql' => 'SELECT COUNT(*) AS c FROM clients_order_items WHERE branch_id=?', 'types' => 'i'],
                'customers' => ['sql' => 'SELECT COUNT(*) AS c FROM customers WHERE branch_id=?', 'types' => 'i'],
                'expenses' => ['sql' => 'SELECT COUNT(*) AS c FROM expenses WHERE branch_id=?', 'types' => 'i'],
                'prescriptions' => ['sql' => 'SELECT COUNT(*) AS c FROM prescriptions WHERE branch_id=?', 'types' => 'i'],
                'lab_results' => ['sql' => 'SELECT COUNT(*) AS c FROM lab_results WHERE branch_id=?', 'types' => 'i'],
                'purchase_orders' => ['sql' => 'SELECT COUNT(*) AS c FROM purchase_orders WHERE branch_id=?', 'types' => 'i'],
                'purchase_order' => ['sql' => 'SELECT COUNT(*) AS c FROM purchase_order WHERE branch_id=?', 'types' => 'i'],
                'purchase_items' => ['sql' => 'SELECT COUNT(*) AS c FROM purchase_items WHERE branch_id=?', 'types' => 'i'],
                'help_inquiries' => ['sql' => 'SELECT COUNT(*) AS c FROM help_inquiries WHERE branch_id=?', 'types' => 'i'],
                'employee_loans' => ['sql' => 'SELECT COUNT(*) AS c FROM employee_loans WHERE branch_id=?', 'types' => 'i'],
                'stock_transfers' => ['sql' => 'SELECT COUNT(*) AS c FROM stock_transfers WHERE from_branch_id=? OR to_branch_id=?', 'types' => 'ii'],
                'compliance_branches' => ['sql' => 'SELECT COUNT(*) AS c FROM compliance_branches WHERE branch_id=?', 'types' => 'i'],
                'compliance_branch_devices' => ['sql' => 'SELECT COUNT(*) AS c FROM compliance_branch_devices WHERE branch_id=?', 'types' => 'i'],
                'compliance_invoices' => ['sql' => 'SELECT COUNT(*) AS c FROM compliance_invoices WHERE branch_id=?', 'types' => 'i'],
            ];

            $foundDependency = '';
            foreach ($dependencyLabels as $table => $definition) {
                if (!bm_table_exists($adminDb, $table)) {
                    continue;
                }

                $values = $table === 'stock_transfers'
                    ? [$branch_id, $branch_id]
                    : [$branch_id];

                $countRow = bm_one($adminDb, $definition['sql'], $definition['types'], $values);
                if ((int)($countRow['c'] ?? 0) > 0) {
                    $foundDependency = $table;
                    break;
                }
            }

            if ($foundDependency !== '') {
                throw new RuntimeException(
                    'This branch cannot be permanently deleted because it has records in ' .
                    $foundDependency . '. Deactivate it instead so historical records remain intact.'
                );
            }

            $active_count = (int)(bm_one(
                $adminDb,
                'SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id=? AND is_active=1',
                'i',
                [$pharmacy_id]
            )['c'] ?? 0);

            if ((int)$branch['is_active'] === 1 && $active_count <= 1) {
                throw new RuntimeException('The only active branch cannot be deleted.');
            }

            $stmt = $adminDb->prepare('DELETE FROM branches WHERE id=? AND pharmacy_id=?');
            if (!$stmt) {
                throw new RuntimeException($adminDb->error);
            }
            $stmt->bind_param('ii', $branch_id, $pharmacy_id);
            if (!$stmt->execute()) {
                $msg = $stmt->error;
                $stmt->close();
                throw new RuntimeException($msg);
            }
            $stmt->close();

            $notice = 'Branch deleted successfully.';
        } else {
            throw new RuntimeException('Unknown branch action.');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* ---------- Filters ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$status_filter = (string)($_GET['status'] ?? 'all');
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'all';
}

$location_filter = trim((string)($_GET['location'] ?? ''));

$where = ['b.pharmacy_id=?'];
$types = 'i';
$values = [$pharmacy_id];

if ($status_filter === 'active') {
    $where[] = 'b.is_active=1';
} elseif ($status_filter === 'inactive') {
    $where[] = 'b.is_active=0';
}

if ($q !== '') {
    $where[] = '(b.branch_name LIKE ? OR b.branch_code LIKE ? OR b.location LIKE ? OR b.phone LIKE ? OR b.branch_email LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sssss';
    array_push($values, $like, $like, $like, $like, $like);
}

if ($location_filter !== '') {
    $where[] = 'b.location LIKE ?';
    $types .= 's';
    $values[] = '%' . $location_filter . '%';
}

$branchWhere = implode(' AND ', $where);

/* ---------- Branch directory with operational metrics ---------- */
$branches = bm_rows(
    $adminDb,
    "SELECT
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
        COALESCE(s.sales_count,0) AS sales_count,
        COALESCE(s.sales_value,0) AS sales_value,
        COALESCE(i.item_count,0) AS item_count,
        COALESCE(i.stock_units,0) AS stock_units,
        COALESCE(i.stock_value,0) AS stock_value,
        COALESCE(u.staff_count,0) AS staff_count,
        COALESCE(o.online_count,0) AS online_count,
        COALESCE(e.expense_value,0) AS expense_value,
        COALESCE(p.pending_prescriptions,0) AS pending_prescriptions
     FROM branches b
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS sales_count,
               SUM(COALESCE(total_amount,total,0)) AS sales_value
        FROM sales
        WHERE pharmacy_id=?
        GROUP BY branch_id
     ) s ON s.branch_id=b.id
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS item_count,
               SUM(COALESCE(quantity,0)) AS stock_units,
               SUM(COALESCE(quantity,0)*COALESCE(price,0)) AS stock_value
        FROM store_items
        WHERE pharmacy_id=?
        GROUP BY branch_id
     ) i ON i.branch_id=b.id
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS staff_count
        FROM users
        WHERE pharmacy_id=?
        GROUP BY branch_id
     ) u ON u.branch_id=b.id
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS online_count
        FROM clients_orders
        WHERE pharmacy_id=?
        GROUP BY branch_id
     ) o ON o.branch_id=b.id
     LEFT JOIN (
        SELECT branch_id, SUM(COALESCE(amount,0)) AS expense_value
        FROM expenses
        WHERE pharmacy_id=?
        GROUP BY branch_id
     ) e ON e.branch_id=b.id
     LEFT JOIN (
        SELECT branch_id, COUNT(*) AS pending_prescriptions
        FROM prescriptions
        WHERE pharmacy_id=? AND status <> 'Ready'
        GROUP BY branch_id
     ) p ON p.branch_id=b.id
     WHERE {$branchWhere}
     ORDER BY b.is_active DESC, b.branch_name ASC",
    'iiiiii' . $types,
    array_merge([$pharmacy_id, $pharmacy_id, $pharmacy_id, $pharmacy_id, $pharmacy_id, $pharmacy_id], $values)
);

/* ---------- Summary metrics ---------- */
$summary = bm_one(
    $adminDb,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) AS inactive
     FROM branches WHERE pharmacy_id=?",
    'i',
    [$pharmacy_id]
) ?? ['total' => 0, 'active' => 0, 'inactive' => 0];

$networkRevenue = (float)(bm_one(
    $adminDb,
    'SELECT COALESCE(SUM(COALESCE(total_amount,total,0)),0) AS v FROM sales WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['v'] ?? 0);

$networkStock = (float)(bm_one(
    $adminDb,
    'SELECT COALESCE(SUM(COALESCE(quantity,0)*COALESCE(price,0)),0) AS v
     FROM store_items WHERE pharmacy_id=? AND is_active=1',
    'i',
    [$pharmacy_id]
)['v'] ?? 0);

$networkStaff = (int)(bm_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM users WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$networkOnlineOrders = (int)(bm_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM clients_orders WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$branch_count = (int)($summary['active'] ?? 0);
$total_orders = (int)(bm_one(
    $adminDb,
    'SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id=?',
    'i',
    [$pharmacy_id]
)['c'] ?? 0);

$current_admin_page = 'branches.php';
$admin_page_title = 'Branch Management';
?>
<?php require __DIR__ . '/actions/admin_aside.php'; ?>
<div class="branch-admin-main">
    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="branch-content">
        <div class="page-head">
            <div>
                <div class="eyebrow"><i class="fas fa-network-wired"></i> Network / Branch Management</div>
                <h1>Branch Management</h1>
                <p>Manage every operating location for <strong><?= bm_h($pharmacy_name) ?></strong>, including contact details, payment instructions, operational status and branch performance.</p>
            </div>
            <div class="head-actions">
                <a class="btn light" href="branches.php"><i class="fas fa-rotate"></i> Refresh</a>
                <button class="btn primary" type="button" onclick="openBranchModal()"><i class="fas fa-plus"></i> Add Branch</button>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
            <div class="alert success"><i class="fas fa-circle-check"></i><span><?= bm_h($notice) ?></span><button type="button" onclick="this.parentElement.remove()">Ã—</button></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert error"><i class="fas fa-circle-exclamation"></i><span><?= bm_h($error) ?></span><button type="button" onclick="this.parentElement.remove()">Ã—</button></div>
        <?php endif; ?>

        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-top"><span>Total Branches</span><span class="summary-icon blue"><i class="fas fa-store"></i></span></div>
                <div class="summary-value"><?= number_format((int)$summary['total']) ?></div>
                <div class="summary-sub">All locations in this pharmacy</div>
            </div>
            <div class="summary-card">
                <div class="summary-top"><span>Active</span><span class="summary-icon green"><i class="fas fa-circle-check"></i></span></div>
                <div class="summary-value"><?= number_format((int)$summary['active']) ?></div>
                <div class="summary-sub">Operational locations</div>
            </div>
            <div class="summary-card">
                <div class="summary-top"><span>Network Revenue</span><span class="summary-icon purple"><i class="fas fa-coins"></i></span></div>
                <div class="summary-value money"><?= bm_money($networkRevenue) ?></div>
                <div class="summary-sub"><?= number_format($total_orders) ?> completed POS records</div>
            </div>
            <div class="summary-card">
                <div class="summary-top"><span>Stock at Cost of Sale Price</span><span class="summary-icon yellow"><i class="fas fa-boxes-stacked"></i></span></div>
                <div class="summary-value money"><?= bm_money($networkStock) ?></div>
                <div class="summary-sub"><?= number_format($networkStaff) ?> staff Â· <?= number_format($networkOnlineOrders) ?> online orders</div>
            </div>
        </section>

        <section class="card filter-card">
            <div class="card-head">
                <div>
                    <h2><i class="fas fa-filter"></i> Branch Directory Filters</h2>
                    <span>Search by branch identity, location or contact information.</span>
                </div>
                <a class="btn light small" href="branches.php"><i class="fas fa-broom"></i> Clear</a>
            </div>
            <form class="filters" method="get">
                <label class="filter-field grow">
                    <span>Search</span>
                    <div class="input-icon"><i class="fas fa-search"></i><input name="q" value="<?= bm_h($q) ?>" placeholder="Branch name, code, phone or email"></div>
                </label>
                <label class="filter-field">
                    <span>Status</span>
                    <select name="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All statuses</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active only</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Location</span>
                    <input name="location" value="<?= bm_h($location_filter) ?>" placeholder="e.g. Lusaka">
                </label>
                <button class="btn primary filter-submit" type="submit"><i class="fas fa-sliders"></i> Apply Filters</button>
            </form>
        </section>

        <section class="card directory-card">
            <div class="card-head">
                <div>
                    <h2><i class="fas fa-building-circle-check"></i> Operating Locations</h2>
                    <span><?= number_format(count($branches)) ?> branch<?= count($branches) === 1 ? '' : 'es' ?> shown</span>
                </div>
                <div class="head-meta">
                    <span class="legend-dot active-dot"></span> Active
                    <span class="legend-dot inactive-dot"></span> Inactive
                </div>
            </div>

            <div class="table-wrap">
                <table class="branch-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Contact / Location</th>
                            <th>Operations</th>
                            <th>Financial</th>
                            <th>Status</th>
                            <th class="right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$branches): ?>
                        <tr><td colspan="6" class="empty"><i class="fas fa-store-slash"></i><strong>No branches found</strong><span>Try clearing your filters or create a new branch.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($branches as $b): ?>
                            <?php
                                $payload = [
                                    'id' => (int)$b['id'],
                                    'branch_code' => (string)($b['branch_code'] ?? ''),
                                    'branch_name' => (string)($b['branch_name'] ?? ''),
                                    'branch_slug' => (string)($b['branch_slug'] ?? ''),
                                    'location' => (string)($b['location'] ?? ''),
                                    'phone' => (string)($b['phone'] ?? ''),
                                    'branch_email' => (string)($b['branch_email'] ?? ''),
                                    'is_active' => (int)$b['is_active'],
                                    'bank_details' => (string)($b['bank_details'] ?? ''),
                                    'mobile_money_details' => (string)($b['mobile_money_details'] ?? ''),
                                ];
                            ?>
                            <tr>
                                <td>
                                    <div class="branch-identity">
                                        <span class="branch-icon"><i class="fas fa-store"></i></span>
                                        <div>
                                            <strong><?= bm_h($b['branch_name']) ?></strong>
                                            <span><?= bm_h($b['branch_code'] ?: 'No branch code') ?></span>
                                            <?php if (!empty($b['branch_slug'])): ?><small><?= bm_h($b['branch_slug']) ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="contact-stack">
                                        <span><i class="fas fa-location-dot"></i><?= bm_h($b['location'] ?: 'Location not set') ?></span>
                                        <span><i class="fas fa-phone"></i><?= bm_h($b['phone'] ?: 'Phone not set') ?></span>
                                        <span><i class="fas fa-envelope"></i><?= bm_h($b['branch_email'] ?: 'Email not set') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-stack">
                                        <strong><?= number_format((int)$b['sales_count']) ?> sales</strong>
                                        <span><?= number_format((int)$b['item_count']) ?> active items Â· <?= number_format((int)$b['stock_units']) ?> units</span>
                                        <span><?= number_format((int)$b['staff_count']) ?> staff Â· <?= number_format((int)$b['online_count']) ?> online orders</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="metric-stack">
                                        <strong><?= bm_money($b['sales_value']) ?></strong>
                                        <span>Sales revenue</span>
                                        <span><?= bm_money($b['expense_value']) ?> expenses</span>
                                        <span><?= bm_money($b['stock_value']) ?> stock value</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status <?= (int)$b['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                        <span></span><?= (int)$b['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <?php if ((int)$b['pending_prescriptions'] > 0): ?>
                                        <div class="mini-warning"><i class="fas fa-prescription-bottle-medical"></i><?= number_format((int)$b['pending_prescriptions']) ?> pending prescriptions</div>
                                    <?php endif; ?>
                                </td>
                                <td class="right">
                                    <div class="row-actions">
                                        <a class="icon-btn" href="view_branch.php?id=<?= (int)$b['id'] ?>" title="Open branch dashboard"><i class="fas fa-chart-line"></i></a>
                                        <button class="icon-btn" type="button" title="Edit branch" onclick='editBranch(<?= json_encode($payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><i class="fas fa-pen"></i></button>
                                        <form method="post" class="inline-form" onsubmit="return confirm(<?= json_encode(((int)$b['is_active'] === 1) ? 'Deactivate this branch? Existing records will remain safe and the branch can be reactivated later.' : 'Activate this branch?') ?>);">
                                            <input type="hidden" name="csrf" value="<?= bm_h($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle_branch">
                                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                            <button class="icon-btn <?= (int)$b['is_active'] === 1 ? 'warning' : 'success' ?>" type="submit" title="<?= (int)$b['is_active'] === 1 ? 'Deactivate branch' : 'Activate branch' ?>">
                                                <i class="fas <?= (int)$b['is_active'] === 1 ? 'fa-pause' : 'fa-play' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Permanently delete this branch? This is only allowed when the branch has no linked operational records.');">
                                            <input type="hidden" name="csrf" value="<?= bm_h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_branch">
                                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                            <button class="icon-btn danger" type="submit" title="Delete branch"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="info-grid">
            <div class="info-card">
                <span class="info-icon"><i class="fas fa-shield-halved"></i></span>
                <div><strong>Tenant protected</strong><p>All branch operations are restricted to the administrator's current <code>pharmacy_id</code>. A branch from another pharmacy cannot be edited or managed through this page.</p></div>
            </div>
            <div class="info-card">
                <span class="info-icon"><i class="fas fa-database"></i></span>
                <div><strong>History protected</strong><p>Branches with sales, stock, staff, orders, expenses, prescriptions, purchases, transfers or compliance records cannot be permanently deleted. Deactivate them instead.</p></div>
            </div>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="branchModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="branchModalTitle">
        <div class="modal-head">
            <div><span class="modal-eyebrow">Branch setup</span><h2 id="branchModalTitle">Add Branch</h2></div>
            <button type="button" class="modal-close" onclick="closeBranchModal()">Ã—</button>
        </div>
        <form method="post" id="branchForm">
            <input type="hidden" name="csrf" value="<?= bm_h($csrf) ?>">
            <input type="hidden" name="action" value="save_branch">
            <input type="hidden" name="id" id="branch_id" value="0">

            <div class="form-grid">
                <label class="field"><span>Branch Name <b>*</b></span><input required maxlength="100" name="branch_name" id="branch_name" placeholder="e.g. Nova Lusaka"></label>
                <label class="field"><span>Branch Code</span><input maxlength="10" name="branch_code" id="branch_code" placeholder="e.g. PMV-01"></label>
                <label class="field"><span>Branch Slug</span><input maxlength="50" name="branch_slug" id="branch_slug" placeholder="nova-lusaka"></label>
                <label class="field"><span>Location</span><input maxlength="255" name="location" id="branch_location" placeholder="Lusaka, Zambia"></label>
                <label class="field"><span>Phone</span><input maxlength="20" name="phone" id="branch_phone" placeholder="0974..."></label>
                <label class="field"><span>Branch Email</span><input type="email" maxlength="100" name="branch_email" id="branch_email" placeholder="lusaka@pharmacy.com"></label>
                <label class="field full"><span>Bank Details</span><textarea maxlength="5000" name="bank_details" id="branch_bank" placeholder="Bank: ... | Account name: ... | Account no: ..."></textarea><small>Used for branch payment / transfer instructions.</small></label>
                <label class="field full"><span>Mobile Money Details</span><textarea maxlength="5000" name="mobile_money_details" id="branch_mobile" placeholder="MTN: ... | Airtel: ..."></textarea><small>Keep these details current if customers use mobile-money payment instructions.</small></label>
            </div>

            <label class="switch-row">
                <span><strong>Operational status</strong><small>Inactive branches remain in history but should not be used for new operations.</small></span>
                <input type="checkbox" name="is_active" id="branch_active" value="1" checked>
                <i></i>
            </label>

            <div class="modal-actions">
                <button type="button" class="btn light" onclick="closeBranchModal()">Cancel</button>
                <button type="submit" class="btn primary"><i class="fas fa-floppy-disk"></i> <span id="saveLabel">Create Branch</span></button>
            </div>
        </form>
    </div>
</div>

<style>
.branch-admin-main{margin-left:var(--sidebar);min-height:100vh;background:var(--bg)}
.branch-content{padding:24px 28px 42px;max-width:1600px;margin:0 auto}
.page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:1.15px;color:var(--blue);font-weight:800;margin-bottom:7px}
.page-head h1{margin:0;font-size:27px;line-height:1.15;color:var(--text);letter-spacing:-.5px}
.page-head p{margin:7px 0 0;max-width:850px;color:var(--muted);font-size:13px;line-height:1.6}
.head-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{border:1px solid transparent;border-radius:9px;min-height:39px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.btn.primary:hover{filter:brightness(.96)}
.btn.light{background:#fff;color:#465363;border-color:var(--border)}
.btn.light:hover{border-color:#b7c4d5;color:var(--blue)}
.btn.small{min-height:34px;padding:0 11px}
.alert{display:flex;align-items:center;gap:10px;border:1px solid;border-radius:10px;padding:11px 13px;margin-bottom:16px;font-size:12px;font-weight:700}
.alert button{margin-left:auto;border:0;background:transparent;font-size:19px;line-height:1;color:inherit}
.alert.success{background:var(--green-soft);border-color:#bce6d2;color:#19764f}
.alert.error{background:var(--red-soft);border-color:#f2c3ca;color:#b53a4c}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
.summary-card{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:17px 18px;box-shadow:var(--shadow)}
.summary-card:after{content:"";position:absolute;right:-20px;bottom:-38px;width:95px;height:95px;border-radius:50%;background:#f2f6ff}
.summary-top{display:flex;align-items:center;justify-content:space-between;color:#66717d;font-size:10px;text-transform:uppercase;letter-spacing:1px;font-weight:800}
.summary-icon{position:relative;z-index:1;width:32px;height:32px;border-radius:8px;display:grid;place-items:center}
.summary-icon.blue{background:var(--blue-soft);color:var(--blue)}
.summary-icon.green{background:var(--green-soft);color:var(--green)}
.summary-icon.purple{background:#f0ecff;color:var(--purple)}
.summary-icon.yellow{background:var(--yellow-soft);color:#c58a12}
.summary-value{position:relative;z-index:1;font-size:25px;line-height:1.2;font-weight:850;color:#17202a;margin-top:12px}
.summary-value.money{font-size:21px}
.summary-sub{position:relative;z-index:1;color:var(--muted);font-size:10px;margin-top:5px}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);margin-bottom:16px;overflow:hidden}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:15px 18px;border-bottom:1px solid #edf0f3}
.card-head h2{margin:0;font-size:14px;color:var(--text);font-weight:800}
.card-head h2 i{color:var(--blue);margin-right:7px;font-size:12px}
.card-head span{display:block;margin-top:4px;color:var(--muted);font-size:10px}
.filter-card .card-head span{font-size:10px}
.filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;padding:14px 18px}
.filter-field{display:flex;flex-direction:column;gap:5px;min-width:165px}
.filter-field.grow{flex:1;min-width:280px}
.filter-field>span{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#6d7782}
.filter-field input,.filter-field select{height:38px;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0 10px;color:var(--text);outline:none;font-size:12px}
.filter-field input:focus,.filter-field select:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
.input-icon{position:relative}
.input-icon i{position:absolute;left:11px;top:12px;color:#8b97a4;font-size:11px}
.input-icon input{width:100%;padding-left:30px}
.filter-submit{height:38px}
.head-meta{display:flex!important;align-items:center;gap:5px!important;font-size:10px!important;white-space:nowrap}
.legend-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-left:8px}
.active-dot{background:var(--green)}
.inactive-dot{background:#aab3bd}
.table-wrap{overflow:auto}
.branch-table{width:100%;min-width:1080px;border-collapse:collapse}
.branch-table th{background:#fafbfd;color:#697582;text-transform:uppercase;letter-spacing:.7px;font-size:9px;font-weight:850;padding:11px 14px;text-align:left;border-bottom:1px solid var(--border)}
.branch-table td{padding:13px 14px;border-bottom:1px solid #edf0f3;vertical-align:middle;font-size:11px;color:#46515d}
.branch-table tbody tr:hover{background:#fbfcfe}
.branch-table .right{text-align:right}
.branch-identity{display:flex;align-items:center;gap:10px;min-width:190px}
.branch-icon{width:36px;height:36px;flex:0 0 36px;border-radius:9px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}
.branch-identity strong{display:block;color:#17202a;font-size:12px;font-weight:850}
.branch-identity span{display:block;color:#65717e;font-size:10px;margin-top:3px;font-weight:700}
.branch-identity small{display:block;color:#9aa4af;font-size:9px;margin-top:2px}
.contact-stack,.metric-stack{display:flex;flex-direction:column;gap:4px}
.contact-stack span{white-space:nowrap}
.contact-stack i{width:14px;color:#8b97a4}
.metric-stack strong{color:#1c2732;font-size:11px}
.metric-stack span{color:#7a8692;font-size:9px}
.status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 8px;font-size:10px;font-weight:850}
.status>span{width:6px;height:6px;border-radius:50%}
.status.active{background:var(--green-soft);color:#19764f}.status.active>span{background:var(--green)}
.status.inactive{background:#f1f3f5;color:#697582}.status.inactive>span{background:#a6afb8}
.mini-warning{margin-top:7px;color:#9b7115;font-size:9px;font-weight:700}
.mini-warning i{margin-right:4px}
.row-actions{display:flex;justify-content:flex-end;gap:5px}
.icon-btn{width:32px;height:32px;border:1px solid var(--border);background:#fff;color:#65717d;border-radius:8px;display:grid;place-items:center;cursor:pointer}
.icon-btn:hover{border-color:#a9c0ec;color:var(--blue);background:#fbfdff}
.icon-btn.warning{color:#9a6d11;background:var(--yellow-soft);border-color:#f2dfae}
.icon-btn.success{color:#19764f;background:var(--green-soft);border-color:#c6e8d8}
.icon-btn.danger{color:#c54a5c;background:var(--red-soft);border-color:#f0c5cc}
.inline-form{margin:0}
.empty{padding:50px 20px!important;text-align:center;color:#8994a0!important}
.empty i{display:block;font-size:28px;color:#b3bdc7;margin-bottom:10px}
.empty strong{display:block;color:#56616d;font-size:13px}
.empty span{display:block;margin-top:4px;font-size:10px}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.info-card{display:flex;gap:11px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:var(--shadow)}
.info-icon{width:31px;height:31px;flex:0 0 31px;border-radius:8px;background:var(--blue-soft);color:var(--blue);display:grid;place-items:center}
.info-card strong{font-size:11px;color:#25303a}
.info-card p{margin:4px 0 0;color:#77828e;font-size:10px;line-height:1.55}
.info-card code{font-size:9px}
.modal-backdrop{position:fixed;inset:0;background:rgba(22,31,40,.46);z-index:2000;display:none;align-items:center;justify-content:center;padding:20px}
.modal-backdrop.open{display:flex}
.modal-card{width:min(760px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;border:1px solid #d7dee6;box-shadow:0 22px 70px rgba(10,20,30,.25)}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:17px 19px;border-bottom:1px solid #edf0f3}
.modal-eyebrow{font-size:9px!important;text-transform:uppercase;letter-spacing:1px;color:var(--blue)!important;font-weight:850}
.modal-head h2{margin:3px 0 0;font-size:18px;color:#18232d}
.modal-close{width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:#fff;color:#67727e;font-size:20px;cursor:pointer}
.modal-card form{padding:18px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.field{display:flex;flex-direction:column;gap:6px}
.field.full{grid-column:1/-1}
.field span{font-size:10px;text-transform:uppercase;letter-spacing:.65px;font-weight:850;color:#606c78}
.field span b{color:var(--red)}
.field input,.field textarea{width:100%;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);padding:10px;font-size:12px;outline:none}
.field input{height:39px}
.field textarea{min-height:80px;resize:vertical}
.field input:focus,.field textarea:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
.field small{font-size:9px;color:#8a95a0}
.switch-row{display:flex;align-items:center;gap:12px;margin-top:16px;padding:12px;border:1px solid #e7ebef;background:#fafbfd;border-radius:9px;position:relative}
.switch-row span{flex:1}.switch-row strong{display:block;font-size:11px;color:#2b3640}.switch-row small{display:block;font-size:9px;color:#7e8995;margin-top:3px}
.switch-row input{position:absolute;opacity:0}
.switch-row i{width:40px;height:23px;background:#c7ced6;border-radius:20px;position:relative;cursor:pointer}
.switch-row i:after{content:"";position:absolute;width:17px;height:17px;top:3px;left:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18);transition:.18s}
.switch-row input:checked+i{background:var(--blue)}
.switch-row input:checked+i:after{left:20px}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:15px;border-top:1px solid #edf0f3}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(2,1fr)}.page-head{align-items:flex-start}.branch-content{padding:20px}.info-grid{grid-template-columns:1fr}}
@media(max-width:900px){.branch-admin-main{margin-left:0}.page-head{flex-direction:column}.head-actions{width:100%}.head-actions .btn{flex:1}.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.branch-content{padding:16px 12px 30px}.summary-grid{grid-template-columns:1fr}.filters{padding:13px}.filter-field,.filter-field.grow{min-width:100%;width:100%}.filter-submit{width:100%}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.modal-backdrop{padding:8px}.modal-card form{padding:14px}}
</style>

<script>
(function(){
    const modal = document.getElementById('branchModal');
    const form = document.getElementById('branchForm');
    const nameInput = document.getElementById('branch_name');
    const slugInput = document.getElementById('branch_slug');

    function slugify(value){
        return value.toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,50);
    }

    if(nameInput && slugInput){
        nameInput.addEventListener('input', function(){
            if(!slugInput.dataset.manual){
                slugInput.value = slugify(nameInput.value);
            }
        });
        slugInput.addEventListener('input', function(){
            slugInput.dataset.manual = '1';
        });
    }

    window.openBranchModal = function(){
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        document.body.style.overflow='hidden';
        setTimeout(function(){ nameInput.focus(); },50);
    };

    window.closeBranchModal = function(){
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow='';
    };

    window.editBranch = function(branch){
        document.getElementById('branchModalTitle').textContent = 'Edit Branch';
        document.getElementById('saveLabel').textContent = 'Update Branch';
        document.getElementById('branch_id').value = branch.id || 0;
        document.getElementById('branch_name').value = branch.branch_name || '';
        document.getElementById('branch_code').value = branch.branch_code || '';
        document.getElementById('branch_slug').value = branch.branch_slug || '';
        document.getElementById('branch_slug').dataset.manual = '1';
        document.getElementById('branch_location').value = branch.location || '';
        document.getElementById('branch_phone').value = branch.phone || '';
        document.getElementById('branch_email').value = branch.branch_email || '';
        document.getElementById('branch_bank').value = branch.bank_details || '';
        document.getElementById('branch_mobile').value = branch.mobile_money_details || '';
        document.getElementById('branch_active').checked = Number(branch.is_active) === 1;
        openBranchModal();
    };

    function resetForm(){
        form.reset();
        document.getElementById('branch_id').value = '0';
        document.getElementById('branchModalTitle').textContent = 'Add Branch';
        document.getElementById('saveLabel').textContent = 'Create Branch';
        document.getElementById('branch_slug').dataset.manual = '';
        document.getElementById('branch_active').checked = true;
    }

    form.addEventListener('submit', function(){
        const button = form.querySelector('button[type="submit"]');
        if(button){ button.disabled = true; button.style.opacity = '.7'; }
    });

    modal.addEventListener('click', function(e){
        if(e.target === modal){ closeBranchModal(); }
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && modal.classList.contains('open')) closeBranchModal();
        if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k'){
            e.preventDefault();
            openBranchModal();
        }
    });

    // Keep the shared header search useful on this page.
    const headerSearch = document.getElementById('adminHeaderSearch');
    if(headerSearch){
        headerSearch.placeholder = 'Search branches...';
        headerSearch.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                const value = this.value.trim();
                const url = new URL(window.location.href);
                if(value) url.searchParams.set('q', value); else url.searchParams.delete('q');
                window.location.href = url.toString();
            }
        });
    }

    window.addEventListener('load', function(){
        const autoOpen = new URLSearchParams(window.location.search).get('new');
        if(autoOpen === '1') openBranchModal();
    });

    window._resetBranchForm = resetForm;
})();
</script>
