<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db_connect.php';

$pharmacy_id   = (int)($_SESSION['pharmacy_id'] ?? 1);
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$user_name     = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Jac';
$user_role     = $_SESSION['role'] ?? 'Pharmacist';
$branch_name   = $_SESSION['branch_name'] ?? 'Nova Lsk';

$is_supervisor = in_array(strtolower($user_role), ['admin', 'supervisor', 'manager'], true);

// -------------------------------------------------------------------------
// POST ACTIONS: CLOCK IN / CLOCK OUT
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clock_in') {
        $shift_type = trim($_POST['shift_type'] ?? 'Morning Shift');
        
        $stmt = $conn->prepare("INSERT INTO shift_logs (pharmacy_id, user_id, branch_id, clock_in, status, notes) VALUES (?, ?, (SELECT id FROM branches WHERE branch_name = ? LIMIT 1), NOW(), 'active', ?)");
        if ($stmt) {
            $stmt->bind_param("iiss", $pharmacy_id, $user_id, $branch_name, $shift_type);
            $stmt->execute();
        }
    } elseif ($_POST['action'] === 'clock_out') {
        $stmt = $conn->prepare("UPDATE shift_logs SET clock_out = NOW(), status = 'completed' WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
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
        COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), '$user_name') AS staff_name, 
        COALESCE(u.role, '$user_role') AS role, 
        COALESCE(b.branch_name, '$branch_name') AS branch_name 
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
    if (!$end) return '-';
    $d1 = new DateTime($start);
    $d2 = new DateTime($end);
    $diff = $d1->diff($d2);
    $hours = $diff->h + ($diff->days * 24);
    return "{$hours} hrs {$diff->i} mins";
}

// Include shared head
include_once __DIR__ . '/../includes/head.php';
?>

<style>
    /* Page specific overrides */
    .clock-card {
        background: #ffffff;
        border-radius: 10px;
        padding: 20px 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .live-clock-display { text-align: center; }
    .live-clock-display .label { font-size: 11px; font-weight: 700; color: #64748b; letter-spacing: 1px; }
    .live-clock-display .time { font-size: 28px; font-weight: 700; color: #0f172a; }
    
    .shift-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-clock-in { background-color: #10b981; }
    .btn-clock-out { background-color: #ef4444; }
    
    .table-card {
        background: #ffffff;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .staff-cell .name { font-weight: 600; color: #0f172a; }
    .staff-cell .role { font-size: 12px; color: #64748b; }
    .time-blue { color: #0284c7; font-weight: 600; }

    /* Modal Styles */
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
    .modal-header { font-size: 18px; font-weight: 700; margin-bottom: 15px; color: #0f172a; }
    .modal-body { margin-bottom: 20px; }
    .modal-body label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #334155; }
    .modal-body select {
        width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; font-size: 14px;
    }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
    .btn-cancel { background: #94a3b8; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
    
    @media (max-width: 768px) {
        .clock-card { flex-direction: column; gap: 15px; text-align: center; }
    }
</style>

<body>
    <?php include_once __DIR__ . '/../includes/aside.php'; ?>
    
    <div class="main-wrapper">
        <?php include_once __DIR__ . '/../includes/header.php'; ?>

        <div class="content-container p-4">
            
            <div class="page-title mb-4">
                <h1 class="h4 font-weight-bold"><i class="fa-solid fa-user-clock text-primary"></i> Shift Reporting & Clock-In</h1>
                <p class="text-muted small">Record duty start times and monitor team reporting activity.</p>
            </div>

            <!-- LIVE CLOCK & ACTION CARD -->
            <div class="clock-card">
                <div>
                    <h3 class="h6 font-weight-bold mb-1"><i class="fa-solid fa-user text-primary"></i> User</h3>
                    <p class="text-muted small mb-0">Role: <strong><?= htmlspecialchars($user_role) ?></strong></p>
                </div>

                <div class="live-clock-display">
                    <div class="label">LIVE TIME</div>
                    <div class="time" id="liveClock">--:--:-- --</div>
                </div>

                <div>
                    <?php if ($active_shift): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="shift-btn btn-clock-out">
                                <i class="fa-solid fa-right-from-bracket"></i> Clock Out Shift
                            </button>
                        </form>
                        <div class="text-success small mt-1 text-right font-weight-bold">
                            <i class="fa-solid fa-circle" style="font-size:8px;"></i> Clocked in at <?= date('H:i', strtotime($active_shift['clock_in'])) ?>
                        </div>
                    <?php else: ?>
                        <button type="button" class="shift-btn btn-clock-in" onclick="openShiftModal()">
                            <i class="fa-solid fa-right-to-bracket"></i> Clock In Shift
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DUTY REPORTING LOGS TABLE -->
            <div class="table-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="h6 font-weight-bold mb-0">
                        <i class="fa-solid fa-clipboard-list"></i> Duty Reporting Logs
                    </div>

                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a href="?" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
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
                                        <td class="staff-cell">
                                            <div class="name"><?= htmlspecialchars($row['staff_name']) ?></div>
                                            <div class="role"><?= htmlspecialchars($row['role']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                        <td class="time-blue"><?= date('d M Y, H:i:s', strtotime($row['clock_in'])) ?></td>
                                        <td>
                                            <?= $row['clock_out'] ? date('d M Y, H:i:s', strtotime($row['clock_out'])) : '<span class="text-muted">In Progress</span>' ?>
                                        </td>
                                        <td><?= get_shift_duration($row['clock_in'], $row['clock_out']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= strtolower($row['status']) === 'active' ? 'success' : 'secondary' ?>">
                                                <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(!empty($row['notes']) ? $row['notes'] : '-') ?></td>
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

        <?php include_once __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- CLOCK IN SHIFT SELECTION MODAL -->
    <div class="modal-overlay" id="shiftModal">
        <div class="modal-card">
            <div class="modal-header">Select Shift Type</div>
            <form method="POST">
                <input type="hidden" name="action" value="clock_in">
                <div class="modal-body">
                    <label for="shift_type">Choose Duty Shift</label>
                    <select name="shift_type" id="shift_type" required>
                        <option value="Morning Shift">Morning Shift</option>
                        <option value="Afternoon Shift">Afternoon Shift</option>
                        <option value="Full Day">Full Day</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeShiftModal()">Cancel</button>
                    <button type="submit" class="shift-btn btn-clock-in">Confirm Clock In</button>
                </div>
            </form>
        </div>
    </div>

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
