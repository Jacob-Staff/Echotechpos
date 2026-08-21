<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_role   = $_SESSION['role'] ?? 'Staff';
$user_name   = $_SESSION['full_name'] ?? 'User';

if (!$pharmacy_id || !$branch_id || !$user_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$is_supervisor = in_array(strtolower($user_role), ['admin', 'manager', 'supervisor', 'superadmin']);
$message = '';

// Check if current user has an active shift
$active_shift_stmt = $conn->prepare("SELECT * FROM shift_logs WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$active_shift_stmt->bind_param("i", $user_id);
$active_shift_stmt->execute();
$active_shift = $active_shift_stmt->get_result()->fetch_assoc();
$active_shift_stmt->close();

// -------------------------------------------------------------------------
// ACTION HANDLERS: CLOCK IN / CLOCK OUT
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'clock_in') {
        if ($active_shift) {
            $message = '<div class="alert alert-warning border-0 shadow-sm">You already have an active shift in progress.</div>';
        } else {
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $conn->prepare("INSERT INTO shift_logs (pharmacy_id, branch_id, user_id, clock_in, status, notes) VALUES (?, ?, ?, NOW(), 'active', ?)");
            $stmt->bind_param("iiis", $pharmacy_id, $branch_id, $user_id, $notes);
            
            if ($stmt->execute()) {
                header("Location: shift_log.php?msg=clocked_in");
                exit();
            } else {
                $message = '<div class="alert alert-danger border-0 shadow-sm">Failed to record clock-in time.</div>';
            }
            $stmt->close();
        }
    } elseif ($action === 'clock_out') {
        if (!$active_shift) {
            $message = '<div class="alert alert-warning border-0 shadow-sm">No active shift found to clock out from.</div>';
        } else {
            $shift_id = $active_shift['id'];
            $stmt = $conn->prepare("UPDATE shift_logs SET clock_out = NOW(), status = 'completed' WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $shift_id, $user_id);
            
            if ($stmt->execute()) {
                header("Location: shift_log.php?msg=clocked_out");
                exit();
            } else {
                $message = '<div class="alert alert-danger border-0 shadow-sm">Failed to end shift.</div>';
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'clocked_in') {
        $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Clocked In!</strong> Your shift reporting time has been registered.</div>';
    } elseif ($_GET['msg'] === 'clocked_out') {
        $message = '<div class="alert alert-info border-0 shadow-sm"><strong>Clocked Out!</strong> Shift logged successfully.</div>';
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING FOR REPORTING PANEL
// -------------------------------------------------------------------------
$filter_user = (int)($_GET['filter_user'] ?? 0);
$filter_date = $_GET['filter_date'] ?? '';

$where_conditions = ["sl.pharmacy_id = '$pharmacy_id'"];

// Regular staff members can only view their own shift logs
if (!$is_supervisor) {
    $where_conditions[] = "sl.user_id = '$user_id'";
} elseif ($filter_user > 0) {
    $where_conditions[] = "sl.user_id = '$filter_user'";
}

if (!empty($filter_date)) {
    $safe_date = mysqli_real_escape_string($conn, $filter_date);
    $where_conditions[] = "DATE(sl.clock_in) = '$safe_date'";
}
$where_sql = implode(' AND ', $where_conditions);

// Query shift logs
$logs_query = "
    SELECT sl.*, u.full_name, u.role, b.branch_name 
    FROM shift_logs sl
    JOIN users u ON sl.user_id = u.id
    JOIN branches b ON sl.branch_id = b.id
    WHERE $where_sql
    ORDER BY sl.id DESC
";
$logs_res = mysqli_query($conn, $logs_query);

// Fetch staff list for supervisor filter dropdown
$staff_res = mysqli_query($conn, "SELECT id, full_name FROM users WHERE pharmacy_id = '$pharmacy_id' ORDER BY full_name ASC");

require_once "../includes/head.php";
?>

<style>
    .clock-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; }
    .status-active { background-color: #d1e7dd; color: #0f5132; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .status-completed { background-color: #e2e3e5; color: #41464b; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .timer-display { font-size: 1.8rem; font-weight: 800; color: #1e293b; font-family: monospace; }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="container-fluid pt-4">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><i class="fas fa-user-clock me-2 text-primary"></i> Shift Reporting & Clock-In</h3>
                    <p class="text-muted small mb-0">Record duty start times and monitor team reporting activity.</p>
                </div>
            </div>

            <?= $message ?>

            <!-- Duty Clock Terminal -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card clock-card shadow-sm p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="fas fa-id-badge text-primary me-2"></i><?= htmlspecialchars($user_name ?? '') ?></h5>
                                <p class="text-muted small mb-0">Role: <strong><?= htmlspecialchars($user_role ?? '') ?></strong></p>
                            </div>

                            <div class="text-center my-2 my-md-0">
                                <small class="text-muted d-block text-uppercase fw-bold">Live Time</small>
                                <span class="timer-display" id="liveClock">00:00:00 AM</span>
                            </div>

                            <div>
                                <?php if ($active_shift): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="clock_out">
                                        <button type="submit" class="btn btn-danger fw-bold btn-lg px-4" onclick="return confirm('Confirm clocking out of current shift?')">
                                            <i class="fas fa-sign-out-alt me-1"></i> Clock Out Shift
                                        </button>
                                    </form>
                                    <div class="small text-success fw-bold text-end mt-1">
                                        <i class="fas fa-circle me-1 small"></i> Clocked in at <?= date('H:i', strtotime($active_shift['clock_in'])) ?>
                                    </div>
                                <?php else: ?>
                                    <button class="btn btn-success fw-bold btn-lg px-4" data-bs-toggle="modal" data-bs-target="#clockInModal">
                                        <i class="fas fa-sign-in-alt me-1"></i> Clock In Shift
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reporting Control Panel -->
            <div class="card clock-card shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-clipboard-list me-2 text-secondary"></i> Duty Reporting Logs</h5>
                        </div>
                        <div class="col-md-8">
                            <?php if ($is_supervisor): ?>
                                <!-- Filter controls for Supervisors and Admin -->
                                <form method="get" class="row g-2 justify-content-end">
                                    <div class="col-md-4">
                                        <select name="filter_user" class="form-select form-select-sm" onchange="this.form.submit()">
                                            <option value="0">All Staff Members</option>
                                            <?php while ($s = mysqli_fetch_assoc($staff_res)): ?>
                                                <option value="<?= $s['id'] ?>" <?= $filter_user == $s['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['full_name'] ?? '') ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date ?? '') ?>" onchange="this.form.submit()">
                                    </div>
                                    <?php if ($filter_user || $filter_date): ?>
                                        <div class="col-auto">
                                            <a href="shift_log.php" class="btn btn-sm btn-light border"><i class="fas fa-undo"></i></a>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
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
                                <?php if (mysqli_num_rows($logs_res) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($logs_res)): ?>
                                        <?php
                                            $cin = new DateTime($row['clock_in']);
                                            $cout = $row['clock_out'] ? new DateTime($row['clock_out']) : null;
                                            $duration_str = '-';

                                            if ($cout) {
                                                $diff = $cin->diff($cout);
                                                $duration_str = $diff->format('%h hrs %i mins');
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark d-block"><?= htmlspecialchars($row['full_name'] ?? 'Unknown User') ?></strong>
                                                <small class="text-muted"><?= htmlspecialchars($row['role'] ?? 'Staff') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($row['branch_name'] ?? '') ?></td>
                                            <td><span class="text-primary fw-bold"><?= $cin->format('d M Y, H:i:s') ?></span></td>
                                            <td>
                                                <?= $cout ? $cout->format('d M Y, H:i:s') : '<span class="text-muted">In Progress</span>' ?>
                                            </td>
                                            <td><small><?= $duration_str ?></small></td>
                                            <td>
                                                <span class="status-<?= htmlspecialchars($row['status'] ?? 'active') ?>">
                                                    <?= ucfirst(htmlspecialchars($row['status'] ?? 'active')) ?>
                                                </span>
                                            </td>
                                            <td><small class="text-muted"><?= htmlspecialchars($row['notes'] ?? 'None') ?></small></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No reporting shift logs found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; ?>
</div>

<!-- Modal: Clock In -->
<div class="modal fade" id="clockInModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post">
            <input type="hidden" name="action" value="clock_in">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-clock me-2 text-success"></i> Confirm Shift Start</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Clocking in will record your official shift start time for reporting and payroll review.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Shift Notes (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Morning Duty / Counter Shift">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold">Clock In Now</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateLiveClock() {
        const now = new Date();
        document.getElementById('liveClock').innerText = now.toLocaleTimeString();
    }
    setInterval(updateLiveClock, 1000);
    updateLiveClock();
</script>
</body>
</html>
