<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db_connect.php';

// Session variables
$pharmacy_id   = (int)($_SESSION['pharmacy_id'] ?? 1);
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$user_role     = strtolower($_SESSION['role'] ?? 'pharmacist');
$user_name     = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Jac';
$branch_name   = $_SESSION['branch_name'] ?? 'Nova Lsk';
$branch_id     = (int)($_SESSION['branch_id'] ?? 1);
$is_supervisor = in_array($user_role, ['admin', 'supervisor', 'manager'], true);

// -------------------------------------------------------------------------
// CLOCK IN / CLOCK OUT POST ACTION HANDLER
// -------------------------------------------------------------------------
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $notes  = trim($_POST['notes'] ?? 'Morning duty');

    if ($action === 'clock_in') {
        // Ensure no open active shift exists for this user
        $check_stmt = $conn->prepare("SELECT id FROM shift_logs WHERE user_id = ? AND status = 'active' LIMIT 1");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $message = "You already have an active shift logged!";
            $message_type = "danger";
        } else {
            $stmt = $conn->prepare("INSERT INTO shift_logs (pharmacy_id, branch_id, user_id, clock_in, status, notes) VALUES (?, ?, ?, NOW(), 'active', ?)");
            $stmt->bind_param("iiis", $pharmacy_id, $branch_id, $user_id, $notes);
            if ($stmt->execute()) {
                $message = "Successfully clocked in!";
                $message_type = "success";
            }
        }
    } elseif ($action === 'clock_out') {
        $stmt = $conn->prepare("UPDATE shift_logs SET clock_out = NOW(), status = 'completed' WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Successfully clocked out!";
            $message_type = "success";
        } else {
            $message = "No active shift found to clock out.";
            $message_type = "danger";
        }
    }
}

// Check current user shift status
$active_shift = null;
$status_stmt = $conn->prepare("SELECT clock_in FROM shift_logs WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
if ($status_stmt) {
    $status_stmt->bind_param("i", $user_id);
    $status_stmt->execute();
    $active_shift = $status_stmt->get_result()->fetch_assoc();
}

// -------------------------------------------------------------------------
// QUERY DUTY REPORTING LOGS
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
        sl.pharmacy_id, 
        sl.branch_id, 
        sl.user_id, 
        sl.clock_in, 
        sl.clock_out, 
        sl.status, 
        sl.notes,
        COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'Staff #' || sl.user_id) AS staff_name, 
        COALESCE(u.role, 'Pharmacist') AS role, 
        COALESCE(b.branch_name, ?) AS branch_name 
    FROM shift_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN branches b ON sl.branch_id = b.id
    WHERE $where_sql
    ORDER BY sl.id DESC
";

$logs_stmt = $conn->prepare($logs_query);
if ($logs_stmt) {
    // Prepend branch default parameter to array
    array_unshift($param_values, $branch_name);
    $param_types = "s" . $param_types;
    
    $logs_stmt->bind_param($param_types, ...$param_values);
    $logs_stmt->execute();
    $logs_res = $logs_stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHARMANOVA - Shift Reporting & Clock-In</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
            --primary: #2563eb;
            --danger: #ef4444;
            --success: #10b981;
            --bg-body: #f1f5f9;
            --card-bg: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { display: flex; background-color: var(--bg-body); min-height: 100vh; }

        /* Sidebar Styling */
        aside {
            width: 260px;
            background-color: var(--sidebar-bg);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 100;
            transition: transform 0.3s ease;
        }

        .brand-header {
            padding: 20px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #fff;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .user-profile-badge {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            gap: 12px;
            background: rgba(255,255,255,0.03);
        }

        .avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: #475569;
            display: flex; align-items: center; justify-content: center;
        }

        .nav-menu {
            list-style: none;
            padding: 15px 10px;
            flex: 1;
            overflow-y: auto;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .nav-item a:hover, .nav-item.active a {
            background-color: var(--sidebar-hover);
            color: #fff;
        }

        .btn-logout {
            background: #f43f5e;
            color: white;
            padding: 12px;
            margin: 15px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        /* Main Content Layout */
        main {
            margin-left: 260px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Top Header */
        header {
            height: 65px;
            background: var(--card-bg);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
        }

        .search-box {
            width: 320px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 8px 15px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            outline: none;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pill-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .icon-btn {
            background: var(--primary);
            color: white;
            border: none;
            width: 35px; height: 35px;
            border-radius: 6px;
            cursor: pointer;
        }

        /* Page Content Area */
        .content-area {
            padding: 25px;
        }

        .page-title {
            font-size: 24px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* Clock Control Banner */
        .clock-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .time-display {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-dark);
            text-align: center;
        }

        .clock-btn {
            padding: 12px 24px;
            border-radius: 6px;
            border: none;
            font-size: 16px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-clock-in { background-color: var(--primary); }
        .btn-clock-out { background-color: var(--danger); }

        /* Logs Table Card */
        .table-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .filter-row input, .filter-row button, .filter-row a {
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: #f8fafc;
            padding: 12px 16px;
            color: var(--text-muted);
            font-size: 13px;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active { background: #dcfce7; color: #15803d; }
        .status-completed { background: #e2e8f0; color: #475569; }

        .subtext { font-size: 12px; color: var(--text-muted); display: block; }

        /* Responsive Layout Adjustments */
        @media (max-width: 900px) {
            aside { transform: translateX(-100%); }
            aside.open { transform: translateX(0); }
            main { margin-left: 0; }
            .mobile-toggle { display: block !important; }
        }

        .mobile-toggle { display: none; background: none; border: none; font-size: 20px; cursor: pointer; }
        
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-danger { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <!-- ASIDE SIDEBAR -->
    <aside id="sidebar">
        <div class="brand-header">PHARMANOVA</div>
        
        <div class="user-profile-badge">
            <div class="avatar"><i class="fa-solid fa-user"></i></div>
            <div>
                <strong style="display:block; font-size:14px;">Staff: <?= htmlspecialchars($user_name) ?></strong>
                <span style="font-size:12px; color:#94a3b8;"><?= htmlspecialchars(ucfirst($user_role)) ?></span>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="#"><i class="fa-solid fa-plus-square"></i> Top up Pharmacy</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-box"></i> Pharmacy Stock</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-cart-shopping"></i> Purchase-orders</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-users"></i> Suppliers</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-circle-plus"></i> Add Product</a></li>
            <li class="nav-item"><a href="#"><i class="fa-solid fa-right-left"></i> Stock Transfers</a></li>
            <li class="nav-item active"><a href="#"><i class="fa-solid fa-user-clock"></i> Duty & Shift Log</a></li>
        </ul>

        <a href="../logout.php" class="btn-logout"><i class="fa-solid fa-power-off"></i> Logout</a>
    </aside>

    <!-- MAIN CONTENT -->
    <main>
        <!-- HEADER NAVBAR -->
        <header>
            <div style="display:flex; align-items:center; gap:15px;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="search-box">
                    <input type="text" placeholder="Search products...">
                </div>
            </div>

            <div class="header-actions">
                <button class="icon-btn" style="background:#2563eb;"><i class="fa-solid fa-house"></i></button>
                <div class="pill-badge"><i class="fa-solid fa-store"></i> <?= htmlspecialchars($branch_name) ?></div>
                <div class="pill-badge" style="background:#f1f5f9; color:#334155;">
                    <i class="fa-regular fa-calendar"></i> <?= date('d M Y') ?>
                </div>
                <button class="icon-btn" style="background:#10b981;"><i class="fa-solid fa-plus"></i></button>
                <button class="icon-btn" style="background:#64748b;"><i class="fa-solid fa-gear"></i></button>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <div class="content-area">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="page-title">
                <i class="fa-solid fa-user-clock" style="color:#2563eb;"></i> Shift Reporting & Clock-In
            </div>
            <div class="page-subtitle">Record duty start times and monitor team reporting activity.</div>

            <!-- CLOCK-IN / OUT WIDGET -->
            <div class="clock-card">
                <div>
                    <div style="display:flex; align-items:center; gap:8px; font-weight:600;">
                        <i class="fa-solid fa-user"></i> User
                    </div>
                    <span class="subtext">Role: <strong><?= htmlspecialchars(ucfirst($user_role)) ?></strong></span>
                </div>

                <div>
                    <div class="subtext" style="text-align:center; font-weight:bold;">LIVE TIME</div>
                    <div class="time-display" id="liveClock">--:--:-- --</div>
                </div>

                <div>
                    <form method="POST">
                        <?php if ($active_shift): ?>
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="clock-btn btn-clock-out">
                                <i class="fa-solid fa-right-from-bracket"></i> Clock Out Shift
                            </button>
                            <span class="subtext" style="color:#10b981; text-align:right; margin-top:4px;">
                                <i class="fa-solid fa-circle" style="font-size:8px;"></i> Clocked in at <?= date('H:i', strtotime($active_shift['clock_in'])) ?>
                            </span>
                        <?php else: ?>
                            <input type="hidden" name="action" value="clock_in">
                            <button type="submit" class="clock-btn btn-clock-in">
                                <i class="fa-solid fa-right-to-bracket"></i> Clock In Shift
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- DUTY REPORTING LOGS TABLE -->
            <div class="table-card">
                <div style="font-weight:bold; font-size:16px; margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-clipboard-list"></i> Duty Reporting Logs
                </div>

                <form method="GET" class="filter-row">
                    <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                    <button type="submit" style="background:#e2e8f0; cursor:pointer;">Filter</button>
                    <a href="?" style="background:#f1f5f9; color:#334155;">Reset</a>
                </form>

                <div style="overflow-x:auto;">
                    <table>
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
                                    <?php 
                                        // Calculate duration
                                        $duration = '-';
                                        if (!empty($row['clock_out'])) {
                                            $start = new DateTime($row['clock_in']);
                                            $end = new DateTime($row['clock_out']);
                                            $diff = $start->diff($end);
                                            $duration = $diff->h . ' hrs ' . $diff->i . ' mins';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['staff_name']) ?></strong>
                                            <span class="subtext"><?= htmlspecialchars(ucfirst($row['role'])) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                        <td style="color:#2563eb; font-weight:600;">
                                            <?= date('d M Y, H:i:s', strtotime($row['clock_in'])) ?>
                                        </td>
                                        <td>
                                            <?= !empty($row['clock_out']) ? date('d M Y, H:i:s', strtotime($row['clock_out'])) : 'In Progress' ?>
                                        </td>
                                        <td><?= $duration ?></td>
                                        <td>
                                            <span class="badge-status status-<?= strtolower($row['status']) ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($row['notes'] ?? '-') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;">No shift logs recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- LIVE CLOCK SCRIPT -->
    <script>
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            document.getElementById('liveClock').textContent = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
