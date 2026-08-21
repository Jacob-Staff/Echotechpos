<?php
// Ensure database connection and session exist
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Point to includes/db_connect.php in the parent directory
require_once __DIR__ . '/../includes/db_connect.php';

// Ensure mandatory session variables are set
$pharmacy_id   = (int)($_SESSION['pharmacy_id'] ?? 0);
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$user_role     = strtolower($_SESSION['role'] ?? '');
$is_supervisor = in_array($user_role, ['admin', 'supervisor', 'manager'], true);

// -------------------------------------------------------------------------
// DATA FETCHING FOR REPORTING PANEL
// -------------------------------------------------------------------------
$filter_user = (int)($_GET['filter_user'] ?? 0);
$filter_date = trim($_GET['filter_date'] ?? '');

$where_clauses = ["sl.pharmacy_id = ?"];
$param_types   = "i";
$param_values  = [$pharmacy_id];

if (!$is_supervisor) {
    $where_clauses[] = "sl.user_id = ?";
    $param_types   .= "i";
    $param_values[]  = $user_id;
} elseif ($filter_user > 0) {
    $where_clauses[] = "sl.user_id = ?";
    $param_types   .= "i";
    $param_values[]  = $filter_user;
}

if (!empty($filter_date)) {
    $where_clauses[] = "DATE(sl.clock_in) = ?";
    $param_types   .= "s";
    $param_values[]  = $filter_date;
}

$where_sql = implode(' AND ', $where_clauses);

// Updated SQL Query: Safely handles user name resolution across users table
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
        b.branch_name 
    FROM shift_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN branches b ON sl.branch_id = b.id
    WHERE $where_sql
    ORDER BY sl.id DESC
";

$logs_stmt = $conn->prepare($logs_query);
if ($logs_stmt) {
    $logs_stmt->bind_param($param_types, ...$param_values);
    $logs_stmt->execute();
    $logs_res = $logs_stmt->get_result();
} else {
    die("Query Preparation Failed: " . htmlspecialchars($conn->error));
}

// Fetch staff list for supervisor filter dropdown
$staff_members = [];
if ($is_supervisor) {
    $staff_stmt = $conn->prepare("SELECT id, COALESCE(NULLIF(full_name, ''), username) AS name FROM users WHERE pharmacy_id = ?");
    if ($staff_stmt) {
        $staff_stmt->bind_param("i", $pharmacy_id);
        $staff_stmt->execute();
        $staff_members = $staff_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Logs Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f6f9; }
        .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #333; }
        .filter-form { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-form input, .filter-form select, .filter-form button, .filter-form a { padding: 8px 12px; font-size: 14px; text-decoration: none; }
        .btn-reset { background: #6c757d; color: white; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #007bff; color: white; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; color: #fff; display: inline-block; }
        .badge-active { background-color: #28a745; }
        .badge-completed { background-color: #6c757d; }
    </style>
</head>
<body>

<div class="container">
    <h2>Shift Logs Report</h2>

    <form method="GET" class="filter-form">
        <?php if ($is_supervisor): ?>
            <select name="filter_user">
                <option value="">-- All Staff --</option>
                <?php foreach ($staff_members as $staff): ?>
                    <option value="<?= $staff['id'] ?>" <?= $filter_user === (int)$staff['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($staff['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        
        <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
        <button type="submit">Filter</button>
        <a href="?" class="btn-reset">Reset</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Staff Name</th>
                <th>Role</th>
                <th>Branch</th>
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($logs_res && $logs_res->num_rows > 0): ?>
                <?php while ($row = $logs_res->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['id']) ?></td>
                        <td><?= htmlspecialchars($row['staff_name']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($row['role'])) ?></td>
                        <td><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['clock_in']) ?></td>
                        <td><?= htmlspecialchars($row['clock_out'] ?? 'Still Active') ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($row['status']) ?>">
                                <?= htmlspecialchars(ucfirst($row['status'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(!empty($row['notes']) ? $row['notes'] : '-') ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align:center;">No shift logs found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
