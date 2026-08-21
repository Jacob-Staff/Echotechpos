<?php
// Ensure database connection and session exist
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../includes/db_connect.php';

// Session variables
$pharmacy_id   = (int)($_SESSION['pharmacy_id'] ?? 1);
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$user_name     = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Jac';
$user_role     = $_SESSION['role'] ?? 'Pharmacist';
$branch_name   = $_SESSION['branch_name'] ?? 'Nova Lsk';

$is_supervisor = in_array(strtolower($user_role), ['admin', 'supervisor', 'manager'], true);

// -------------------------------------------------------------------------
// HANDLE CLOCK IN / CLOCK OUT POST ACTIONS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clock_in') {
        $stmt = $conn->prepare("INSERT INTO shift_logs (pharmacy_id, user_id, branch_id, clock_in, status, notes) VALUES (?, ?, (SELECT id FROM branches WHERE branch_name = ? LIMIT 1), NOW(), 'active', 'Morning duty')");
        if ($stmt) {
            $stmt->bind_param("iis", $pharmacy_id, $user_id, $branch_name);
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
// FETCH CURRENT ACTIVE SHIFT FOR LOGGED IN USER
// -------------------------------------------------------------------------
$active_shift = null;
$active_stmt = $conn->prepare("SELECT * FROM shift_logs WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
if ($active_stmt) {
    $active_stmt->bind_param("i", $user_id);
    $active_stmt->execute();
    $active_shift = $active_stmt->get_result()->fetch_assoc();
}

// -------------------------------------------------------------------------
// FETCH LOGS FOR THE TABLE
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

// Helper to calculate duration formatted string
function get_shift_duration($start, $end) {
    if (!$end) return '-';
    $d1 = new DateTime($start);
    $d2 = new DateTime($end);
    $diff = $d1->diff($d2);
    $hours = $diff->h + ($diff->days * 24);
    return "{$hours} hrs {$diff->i} mins";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHARMANOVA - Shift Reporting & Clock-In</title>
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --sidebar-width: 240px;
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
            --primary-blue: #0284c7;
            --accent-red: #ef4444;
            --accent-green: #10b981;
            --bg-body: #f1f5f9;
            --card-bg: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-dark); display: flex; min-height: 100vh; }

        /* ASIDE / SIDEBAR */
        aside {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 100;
            transition: all 0.3s ease;
        }

        .brand-header {
            padding: 20px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #fff;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .user-profile-summary {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: #475569;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #cbd5e1;
        }

        .profile-info .name { font-size: 14px; font-weight: 600; }
        .profile-info .role { font-size: 12px; color: #94a3b8; }

        .nav-menu { list-style: none; padding: 15px 10px; flex-grow: 1; overflow-y: auto; }
        .nav-item { margin-bottom: 4px; }
        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 15px; color: #cbd5e1;
            text-decoration: none; font-size: 14px;
            border-radius: 6px; transition: 0.2s;
        }
        .nav-link:hover, .nav-link.active { background-color: var(--sidebar-hover); color: #fff; }
        .nav-link i { width: 20px; text-align: center; }

        .logout-container { padding: 15px; }
        .btn-logout {
            width: 100%; padding: 10px;
            background-color: #f43f5e; color: #fff;
            border: none; border-radius: 6px;
            font-weight: 600; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        /* MAIN CONTENT AREA */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* HEADER */
        header {
            height: 60px;
            background-color: var(--primary-blue);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .search-box {
            position: relative;
            width: 300px;
        }
        .search-box input {
            width: 100%; padding: 8px 12px;
            border-radius: 4px; border: none;
            outline: none; font-size: 14px;
        }

        .header-actions { display: flex; align-items: center; gap: 15px; font-size: 14px; }
        .header-pill {
            background: rgba(255,255,255,0.15);
            padding: 6px 12px; border-radius: 4px;
            display: flex; align-items: center; gap: 6px;
        }

        /* PAGE BODY */
        .content-container { padding: 25px; }

        .page-title { margin-bottom: 20px; }
        .page-title h1 { font-size: 24px; color: var(--text-dark); display: flex; align-items: center; gap: 10px; }
        .page-title p { color: var(--text-muted); font-size: 14px; margin-top: 4px; }

        /* CLOCK ACTION WIDGET */
        .clock-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .user-status-block h3 { font-size: 18px; color: var(--text-dark); margin-bottom: 4px; }
        .user-status-block p { font-size: 14px; color: var(--text-muted); }

        .live-clock-display { text-align: center; }
        .live-clock-display .label { font-size: 11px; font-weight: 700; color: var(--text-muted); letter-spacing: 1px; }
        .live-clock-display .time { font-size: 28px; font-weight: 700; color: var(--text-dark); margin-top: 2px; }

        .shift-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-clock-in { background-color: var(--accent-green); }
        .btn-clock-out { background-color: var(--accent-red); }

        .clock-subtext { font-size: 12px; color: var(--accent-green); margin-top: 6px; text-align: right; font-weight: 500; }

        /* REPORT TABLE CARD */
        .table-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .table-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-card-title { font-size: 18px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }

        .filter-group { display: flex; gap: 8px; align-items: center; }
        .filter-group input[type="date"] {
            padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; outline: none;
        }
        .btn-filter { background: var(--primary-blue); color: #fff; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; }
        .btn-reset { background: #94a3b8; color: #fff; text-decoration: none; padding: 7px 12px; border-radius: 4px; font-size: 13px; }

        /* DATA TABLE */
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        th { background: #f8fafc; padding: 12px 15px; color: var(--text-muted); font-weight: 600; border-bottom: 2px solid #e2e8f0; }
        td { padding: 14px 15px; border-bottom: 1px solid #e2e8f0; color: var(--text-dark); }

        .staff-cell .name { font-weight: 600; }
        .staff-cell .role { font-size: 12px; color: var(--text-muted); }

        .time-blue { color: #0284c7; font-weight: 600; }

        .badge-status {
            padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-block;
        }
        .badge-active { background-color: #dcfce7; color: #15803d; }
        .badge-completed { background-color: #f1f5f9; color: #475569; }

        /* FOOTER */
        footer {
            margin-top: auto;
            padding: 15px 25px;
            background-color: #fff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text-muted);
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 992px) {
            aside { width: 70px; }
            aside .brand-header, aside .user-profile-summary span, aside .nav-link span { display: none; }
            .main-wrapper { margin-left: 70px; }
            .search-box { width: 180px; }
        }

        @media (max-width: 768px) {
            .clock-card { flex-direction: column; gap: 15px; text-align: center; }
            .clock-subtext { text-align: center; }
            .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        }
    </style>
</head>
<body>

    <!-- LEFT SIDEBAR -->
    <aside>
        <div class="brand-header">PHARMANOVA</div>
        
        <div class="user-profile-summary">
            <div class="avatar"><i class="fa-solid fa-user"></i></div>
            <div class="profile-info">
                <div class="name">Staff: <?= htmlspecialchars($user_name) ?></div>
                <div class="role"><?= htmlspecialchars($user_role) ?></div>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-plus-square"></i><span>Top up Pharmacy</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-border-all"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-box"></i><span>Pharmacy Stock</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-cart-shopping"></i><span>Purchase-orders</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-truck"></i><span>Suppliers</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-circle-plus"></i><span>Add Product</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link"><i class="fa-solid fa-right-left"></i><span>Stock Transfers</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link active"><i class="fa-solid fa-clipboard-user"></i><span>Duty & Shift Log</span></a></li>
        </ul>

        <div class="logout-container">
            <button class="btn-logout" onclick="window.location.href='../logout.php'"><i class="fa-solid fa-power-off"></i> <span>Logout</span></button>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="main-wrapper">
        
        <!-- HEADER -->
        <header>
            <div class="search-box">
                <input type="text" placeholder="Search products...">
            </div>
            
            <div class="header-actions">
                <div class="header-pill"><i class="fa-solid fa-house"></i></div>
                <div class="header-pill"><i class="fa-solid fa-store"></i> <?= htmlspecialchars($branch_name) ?></div>
                <div class="header-pill"><i class="fa-solid fa-calendar"></i> <?= date('d M Y') ?></div>
                <div class="header-pill" style="background:#10b981;"><i class="fa-solid fa-plus"></i></div>
                <div class="header-pill"><i class="fa-solid fa-gear"></i></div>
            </div>
        </header>

        <!-- CONTENT BODY -->
        <div class="content-container">
            
            <div class="page-title">
                <h1><i class="fa-solid fa-user-clock" style="color:var(--primary-blue);"></i> Shift Reporting & Clock-In</h1>
                <p>Record duty start times and monitor team reporting activity.</p>
            </div>

            <!-- LIVE CLOCK CARD -->
            <div class="clock-card">
                <div class="user-status-block">
                    <h3><i class="fa-solid fa-user" style="color:var(--primary-blue);"></i> User</h3>
                    <p>Role: <strong><?= htmlspecialchars($user_role) ?></strong></p>
                </div>

                <div class="live-clock-display">
                    <div class="label">LIVE TIME</div>
                    <div class="time" id="liveClock">--:--:-- --</div>
                </div>

                <div>
                    <form method="POST">
                        <?php if ($active_shift): ?>
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="shift-btn btn-clock-out">
                                <i class="fa-solid fa-right-from-bracket"></i> Clock Out Shift
                            </button>
                            <div class="clock-subtext">
                                <i class="fa-solid fa-circle" style="font-size:8px;"></i> Clocked in at <?= date('H:i', strtotime($active_shift['clock_in'])) ?>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="action" value="clock_in">
                            <button type="submit" class="shift-btn btn-clock-in">
                                <i class="fa-solid fa-right-to-bracket"></i> Clock In Shift
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- REPORTING TABLE CARD -->
            <div class="table-card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <i class="fa-solid fa-clipboard-list"></i> Duty Reporting Logs
                    </div>

                    <form method="GET" class="filter-group">
                        <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a href="?" class="btn-reset">Reset</a>
                    </form>
                </div>

                <div class="table-responsive">
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
                                    <tr>
                                        <td class="staff-cell">
                                            <div class="name"><?= htmlspecialchars($row['staff_name']) ?></div>
                                            <div class="role"><?= htmlspecialchars($row['role']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                        <td class="time-blue"><?= date('d M Y, H:i:s', strtotime($row['clock_in'])) ?></td>
                                        <td>
                                            <?= $row['clock_out'] ? date('d M Y, H:i:s', strtotime($row['clock_out'])) : '<span style="color:#94a3b8;">In Progress</span>' ?>
                                        </td>
                                        <td><?= get_shift_duration($row['clock_in'], $row['clock_out']) ?></td>
                                        <td>
                                            <span class="badge-status badge-<?= strtolower($row['status']) ?>">
                                                <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(!empty($row['notes']) ? $row['notes'] : '-') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding: 30px; color: var(--text-muted);">
                                        No shift logs recorded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <footer>
            <div>Prime Tech. All Rights Reserved</div>
            <div><i class="fa-solid fa-calendar-day"></i> <?= date('d M Y | H:i:s') ?></div>
        </footer>

    </div>

    <!-- LIVE CLOCK SCRIPT -->
    <script>
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12; // hour '0' should be '12'
            
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('liveClock').textContent = timeString;
        }

        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
