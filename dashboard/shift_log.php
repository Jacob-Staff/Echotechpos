<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

// Multi-tenant Security Check
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_name   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role   = $_SESSION['role'] ?? 'Pharmacist';

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../index.php?error=session_expired");
    exit();
}

$is_supervisor = in_array(strtolower($user_role), ['admin', 'supervisor', 'manager'], true);

// Fetch Pharmacy & Branch Name
$display_pharmacy_name = "Pharmacy";
$display_branch_name   = "Main Branch";

$pharm_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_stmt->bind_param("i", $pharmacy_id);
$pharm_stmt->execute();
$pharm_res = $pharm_stmt->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_stmt->close();

$branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_stmt->bind_param("ii", $branch_id, $pharmacy_id);
$branch_stmt->execute();
$branch_res = $branch_stmt->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_stmt->close();

// -------------------------------------------------------------------------
// POST ACTIONS: CLOCK IN / CLOCK OUT
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clock_in') {
        $shift_type = trim($_POST['shift_type'] ?? 'Morning duty');
        
        $stmt = $conn->prepare("INSERT INTO shift_logs (pharmacy_id, branch_id, user_id, clock_in, status, notes) VALUES (?, ?, ?, NOW(), 'active', ?)");
        if ($stmt) {
            $stmt->bind_param("iiis", $pharmacy_id, $branch_id, $user_id, $shift_type);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($_POST['action'] === 'clock_out') {
        $stmt = $conn->prepare("UPDATE shift_logs SET clock_out = NOW(), status = 'completed' WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// -------------------------------------------------------------------------
// FETCH CURRENT ACTIVE SHIFT
// -------------------------------------------------------------------------
$active_shift = null;
$active_stmt = $conn->prepare(
    "SELECT * FROM shift_logs
     WHERE user_id = ? AND pharmacy_id = ? AND branch_id = ? AND status = 'active'
     ORDER BY id DESC LIMIT 1"
);
if ($active_stmt) {
    $active_stmt->bind_param("iii", $user_id, $pharmacy_id, $branch_id);
    $active_stmt->execute();
    $active_shift = $active_stmt->get_result()->fetch_assoc();
    $active_stmt->close();
}

// -------------------------------------------------------------------------
// FETCH SHIFT LOGS
// -------------------------------------------------------------------------
$filter_date = trim($_GET['filter_date'] ?? '');
$where_clauses = ["sl.pharmacy_id = ?", "sl.branch_id = ?"];
$param_types   = "ii";
$param_values  = [$pharmacy_id, $branch_id];

if (!$is_supervisor) {
    $where_clauses[] = "sl.user_id = ?";
    $param_types   .= "i";
    $param_values[]  = $user_id;
}

if (!empty($filter_date)) {
    $where_clauses[] = "DATE(sl.clock_in) = ?";
    $param_types   .= "s";
    $param_values[]  = $filter_date;
}

$where_sql = implode(' AND ', $where_clauses);

$logs_query = "
    SELECT 
        sl.id, 
        sl.clock_in, 
        sl.clock_out, 
        sl.status, 
        sl.notes,
        COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'Staff') AS staff_name, 
        COALESCE(NULLIF(u.role, ''), 'Pharmacist') AS role, 
        COALESCE(b.branch_name, 'Main Branch') AS branch_name 
    FROM shift_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN branches b ON sl.branch_id = b.id
    WHERE $where_sql
    ORDER BY sl.id DESC
";

$logs_stmt = $conn->prepare($logs_query);
$logs_res = null;
if ($logs_stmt) {
    $logs_stmt->bind_param($param_types, ...$param_values);
    $logs_stmt->execute();
    $logs_res = $logs_stmt->get_result();
}

function get_shift_duration($start, $end) {
    if (!$end || !$start) return 'In progress';

    try {
        $d1 = new DateTime($start);
        $d2 = new DateTime($end);
        if ($d2 < $d1) return '-';

        $diff = $d1->diff($d2);
        $parts = [];

        if ($diff->days > 0) $parts[] = $diff->days . ' day' . ($diff->days === 1 ? '' : 's');
        if ($diff->h > 0) $parts[] = $diff->h . ' hr' . ($diff->h === 1 ? '' : 's');
        if ($diff->i > 0) $parts[] = $diff->i . ' min' . ($diff->i === 1 ? '' : 's');

        return !empty($parts) ? implode(' ', $parts) : '0 mins';
    } catch (Exception $e) {
        return '-';
    }
}

require_once "../includes/head.php";
?>

<style>
:root {
    --primary-color: #0284c7;
    --primary-hover: #0369a1;
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
}

html, body {
    max-width: 100vw;
    overflow-x: hidden; /* Prevents unwanted horizontal scrollbars */
}

.page-wrapper {
    margin-left: 240px;
    width: calc(100% - 240px); /* Adjusts width to account for sidebar offset */
    padding: 2rem 1.5rem;
    min-height: 100vh;
    box-sizing: border-box;
}

@media (max-width: 768px) {
    .page-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 1rem;
    }
}

.clock-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.live-clock-display { text-align: center; }
.live-clock-display .label { font-size: 11px; font-weight: 700; color: #64748b; letter-spacing: 1px; }
.live-clock-display .time { font-size: 32px; font-weight: 700; color: #0f172a; margin-top: 2px; }

.table-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    padding: 1.5rem;
}

.badge-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}
.badge-status.active { background-color: #d1fae5; color: #065f46; }
.badge-status.completed { background-color: #e2e8f0; color: #475569; }

.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.6);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal-card {
    background: #fff;
    width: 100%;
    max-width: 420px;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}
</style>

<?php 
if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
?>

<div class="page-wrapper">
    <div class="container-fluid p-0">

        <!-- PAGE TITLE HEADER -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1">Duty & Shift Log</h3>
                <p class="text-muted mb-0 small">
                    <i class="fas fa-building text-primary me-1"></i>
                    <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong> &bull; <?= htmlspecialchars($display_branch_name) ?>
                </p>
            </div>
        </div>

        <!-- LIVE CLOCK CARD -->
        <div class="clock-card mb-4">
            <div class="row align-items-center g-3">
                <div class="col-md-4">
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fas fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($user_name) ?>
                    </h6>
                    <p class="text-muted small mb-0">Role: <strong><?= htmlspecialchars($user_role) ?></strong></p>
                </div>

                <div class="col-md-4 text-center">
                    <div class="live-clock-display">
                        <div class="label">LIVE TIME</div>
                        <div class="time text-dark" id="liveClock">--:--:-- --</div>
                    </div>
                </div>

                <div class="col-md-4 text-md-end">
                    <?php if ($active_shift): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="btn btn-danger font-weight-bold px-4 py-2">
                                <i class="fas fa-sign-out-alt me-1"></i> Clock Out Shift
                            </button>
                        </form>
                        <div class="text-success small mt-2 fw-bold">
                            <i class="fas fa-circle me-1" style="font-size:8px;"></i> Clocked in at <?= date('H:i', strtotime($active_shift['clock_in'])) ?>
                        </div>
                    <?php else: ?>
                        <button type="button" class="btn btn-success font-weight-bold px-4 py-2" onclick="openShiftModal()">
                            <i class="fas fa-sign-in-alt me-1"></i> Clock In Shift
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- DUTY LOGS TABLE CARD -->
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fas fa-clipboard-list text-secondary me-2"></i>Duty Reporting Logs
                    </h5>
                    <div class="small text-muted mt-1" id="shiftCount">Showing all records</div>
                </div>

                <div class="shift-filters d-flex gap-2 align-items-center flex-wrap">
                    <div class="input-group input-group-sm filter-search">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="search" id="shiftSearch" class="form-control border-start-0" placeholder="Search staff, branch, shift...">
                    </div>
                    <input type="date" id="filterDateLive" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                    <select id="statusLive" class="form-select form-select-sm">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                    </select>
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm fw-bold">
                        <i class="fas fa-undo me-1"></i>Reset
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle border mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Staff Name & Role</th>
                            <th>Branch</th>
                            <th>Clock In Time</th>
                            <th>Clock Out Time</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs_res && $logs_res->num_rows > 0): ?>
                            <?php while ($row = $logs_res->fetch_assoc()): ?>
                                <tr class="shift-log-row"
                                    data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($row['clock_in']))) ?>"
                                    data-status="<?= htmlspecialchars(strtolower($row['status'])) ?>">
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['staff_name']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($row['role']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                    <td class="text-primary fw-bold"><?= date('j M Y, H:i:s', strtotime($row['clock_in'])) ?></td>
                                    <td>
                                        <?= $row['clock_out'] ? date('j M Y, H:i:s', strtotime($row['clock_out'])) : '<span class="text-muted fst-italic">In Progress</span>' ?>
                                    </td>
                                    <td><?= get_shift_duration($row['clock_in'], $row['clock_out']) ?></td>
                                    <td>
                                        <span class="badge-status <?= strtolower($row['status']) === 'active' ? 'active' : 'completed' ?>">
                                            <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(!empty($row['notes']) ? $row['notes'] : 'Morning duty') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No shift logs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; ?>

<!-- CLOCK IN MODAL -->
<div class="modal-overlay" id="shiftModal">
    <div class="modal-card">
        <h5 class="fw-bold text-dark mb-3">Select Shift Type</h5>
        <form method="POST">
            <input type="hidden" name="action" value="clock_in">
            <div class="mb-4">
                <label for="shift_type" class="form-label fw-bold small text-muted">Choose Duty Shift</label>
                <select name="shift_type" id="shift_type" class="form-select" required>
                    <option value="Morning duty">Morning duty</option>
                    <option value="Afternoon duty">Afternoon duty</option>
                    <option value="Full Day">Full Day</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm px-3" onclick="closeShiftModal()">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm px-3 fw-bold">Confirm Clock In</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    const liveClock = document.getElementById('liveClock');
    const search = document.getElementById('shiftSearch');
    const dateFilter = document.getElementById('filterDateLive');
    const statusFilter = document.getElementById('statusLive');
    const count = document.getElementById('shiftCount');
    const clearBtn = document.getElementById('clearFilters');

    // Zambia uses CAT (UTC+02:00). Display the POS standard timezone
    // regardless of the workstation's local timezone.
    function updateClock() {
        if (!liveClock) return;

        const now = new Date();
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Africa/Lusaka',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        }).formatToParts(now);

        const get = key => (parts.find(p => p.type === key) || {}).value || '';
        liveClock.textContent =
            `${get('hour')}:${get('minute')}:${get('second')} ${get('dayPeriod')}`;
    }

    updateClock();
    setInterval(updateClock, 1000);

    window.openShiftModal = function () {
        document.getElementById('shiftModal').style.display = 'flex';
        document.body.classList.add('modal-open');
    };

    window.closeShiftModal = function () {
        document.getElementById('shiftModal').style.display = 'none';
        document.body.classList.remove('modal-open');
    };

    document.getElementById('shiftModal')?.addEventListener('click', function (e) {
        if (e.target === this) window.closeShiftModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeShiftModal();
    });

    function applyLiveFilters() {
        const q = (search?.value || '').trim().toLowerCase();
        const selectedDate = dateFilter?.value || '';
        const selectedStatus = statusFilter?.value || 'all';

        const rows = Array.from(document.querySelectorAll('.shift-log-row'));
        let visible = 0;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rowDate = row.dataset.date || '';
            const rowStatus = row.dataset.status || '';

            const matchesSearch = !q || text.includes(q);
            const matchesDate = !selectedDate || rowDate === selectedDate;
            const matchesStatus = selectedStatus === 'all' || rowStatus === selectedStatus;

            const show = matchesSearch && matchesDate && matchesStatus;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        let empty = document.getElementById('liveNoResults');
        if (!empty) {
            empty = document.createElement('tr');
            empty.id = 'liveNoResults';
            empty.innerHTML =
                '<td colspan="7" class="text-center py-5 text-muted">' +
                '<i class="fas fa-search d-block mb-2" style="font-size:1.5rem;color:#cbd5e1"></i>' +
                'No shift logs match the selected filters.</td>';
            document.querySelector('table tbody')?.appendChild(empty);
        }

        empty.style.display = (rows.length && visible === 0) ? '' : 'none';

        if (count) {
            count.textContent = `Showing ${visible} record${visible === 1 ? '' : 's'}`;
        }
    }

    search?.addEventListener('input', applyLiveFilters);
    dateFilter?.addEventListener('change', applyLiveFilters);
    statusFilter?.addEventListener('change', applyLiveFilters);

    clearBtn?.addEventListener('click', function () {
        if (search) search.value = '';
        if (dateFilter) dateFilter.value = '';
        if (statusFilter) statusFilter.value = 'all';
        applyLiveFilters();
    });

    // Confirm clock-out and prevent double submission.
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
            const action = form.querySelector('input[name="action"]')?.value;

            if (action === 'clock_out') {
                if (!window.confirm('Are you sure you want to clock out of your current shift?')) {
                    e.preventDefault();
                    return;
                }
            }

            if (action === 'clock_in') {
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Clocking In...';
                }
            }

            if (action === 'clock_out') {
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Clocking Out...';
                }
            }
        });
    });

    applyLiveFilters();
})();
</script>
</body>
</html>
