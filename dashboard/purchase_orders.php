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
$active_stmt = $conn->prepare("SELECT * FROM shift_logs WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
if ($active_stmt) {
    $active_stmt->bind_param("i", $user_id);
    $active_stmt->execute();
    $active_shift = $active_stmt->get_result()->fetch_assoc();
    $active_stmt->close();
}

// -------------------------------------------------------------------------
// FETCH SHIFT LOGS
// -------------------------------------------------------------------------
$filter_date = trim($_GET['filter_date'] ?? '');
$where_clauses = ["sl.pharmacy_id = ?"];
$param_types   = "i";
$param_values  = [$pharmacy_id];

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
        COALESCE(NULLIF(b.branch_name, ''), ?) AS branch_name 
    FROM shift_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN branches b ON sl.branch_id = b.id
    WHERE $where_sql
    ORDER BY sl.id DESC
";

$param_types  .= "s";
$param_values[] = $display_branch_name;

$logs_stmt = $conn->prepare($logs_query);
$logs_res = null;
if ($logs_stmt) {
    $logs_stmt->bind_param($param_types, ...$param_values);
    $logs_stmt->execute();
    $logs_res = $logs_stmt->get_result();
}

function get_shift_duration($start, $end) {
    if (!$end) return '-';
    $d1 = new DateTime($start);
    $d2 = new DateTime($end);
    $diff = $d1->diff($d2);
    $hours = $diff->h + ($diff->days * 24);
    return "{$hours} hrs {$diff->i} mins";
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

body {
    background-color: var(--bg-page) !important;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

/* Fix margin and padding alignment with sidebar */
.page-wrapper {
    margin-left: 240px; /* Offset for dashboard sidebar */
    padding: 2rem 1.5rem;
    min-height: 100vh;
    box-sizing: border-box;
}

@media (max-width: 768px) {
    .page-wrapper {
        margin-left: 0 !important;
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

/* Modal Overlay */
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
                <h5 class="fw-bold text-dark mb-0">
                    <i class="fas fa-clipboard-list text-secondary me-2"></i>Duty Reporting Logs
                </h5>

                <form method="GET" class="d-flex gap-2 align-items-center">
                    <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="?" class="btn btn-outline-secondary btn-sm fw-bold">Reset</a>
                </form>
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
                                <tr>
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
function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    document.getElementById('liveClock').textContent = `${hours}:${minutes}:${seconds} ${ampm}`;
}
setInterval(updateClock, 1000);
updateClock();

function openShiftModal() {
    document.getElementById('shiftModal').style.display = 'flex';
}
function closeShiftModal() {
    document.getElementById('shiftModal').style.display = 'none';
}
</script>
</body>
</html>
