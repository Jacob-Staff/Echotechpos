<?php
// /var/www/html/dashboard/shift_log.php
session_start();
require_once '../config/db.php'; // Adjust path if necessary

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = $_SESSION['branch_id'] ?? null;
$message = '';
$error = '';

// Handle Shift Start / End actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'start_shift') {
            $opening_balance = floatval($_POST['opening_balance'] ?? 0);
            
            // Check if user already has an active shift
            $stmt = $pdo->prepare("SELECT shift_id FROM shift_logs WHERE user_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $error = "You already have an active shift in progress.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO shift_logs (user_id, branch_id, start_time, opening_balance, status) VALUES (?, ?, NOW(), ?, 'active')");
                if ($stmt->execute([$user_id, $branch_id, $opening_balance])) {
                    $message = "Shift started successfully.";
                } else {
                    $error = "Failed to start shift. Please try again.";
                }
            }
        } elseif ($action === 'end_shift') {
            $closing_balance = floatval($_POST['closing_balance'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            // Get current active shift
            $stmt = $pdo->prepare("SELECT shift_id FROM shift_logs WHERE user_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$user_id]);
            $active_shift = $stmt->fetch();

            if ($active_shift) {
                $shift_id = $active_shift['shift_id'];
                $stmt = $pdo->prepare("UPDATE shift_logs SET end_time = NOW(), closing_balance = ?, notes = ?, status = 'closed' WHERE shift_id = ?");
                if ($stmt->execute([$closing_balance, $notes, $shift_id])) {
                    $message = "Shift ended and logged successfully.";
                } else {
                    $error = "Failed to close shift.";
                }
            } else {
                $error = "No active shift found to close.";
            }
        }
    }
}

// Fetch current active shift
$stmt = $pdo->prepare("SELECT * FROM shift_logs WHERE user_id = ? AND status = 'active' ORDER BY start_time DESC LIMIT 1");
$stmt->execute([$user_id]);
$current_shift = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch historical shift logs
$stmt = $pdo->prepare("SELECT sl.*, u.username FROM shift_logs sl LEFT JOIN users u ON sl.user_id = u.user_id WHERE sl.branch_id = ? ORDER BY sl.start_time DESC LIMIT 50");
$stmt->execute([$branch_id]);
$shift_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Management - POS Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f6f9; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .card { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 6px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group textarea { width: 100%; padding: 8px; box-sizing: border-box; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; color: #fff; }
        .btn-success { background-color: #28a745; }
        .btn-danger { background-color: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; color: #fff; }
        .badge-active { background-color: #28a745; }
        .badge-closed { background-color: #6c757d; }
    </style>
</head>
<body>
<div class="container">
    <h2>Shift Operations Log</h2>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Current Shift Controls -->
    <div class="card">
        <h3>Current Status</h3>
        <?php if ($current_shift): ?>
            <p><strong>Status:</strong> <span class="badge badge-active">ACTIVE</span></p>
            <p><strong>Started At:</strong> <?= htmlspecialchars($current_shift['start_time']) ?></p>
            <p><strong>Opening Balance:</strong> $<?= number_format($current_shift['opening_balance'], 2) ?></p>

            <form method="POST" action="shift_log.php">
                <input type="hidden" name="action" value="end_shift">
                <div class="form-group">
                    <label for="closing_balance">Closing Drawer Balance ($)</label>
                    <input type="number" step="0.01" id="closing_balance" name="closing_balance" required>
                </div>
                <div class="form-group">
                    <label for="notes">Shift Notes / Discrepancies</label>
                    <textarea id="notes" name="notes" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-danger">End & Close Shift</button>
            </form>
        <?php else: ?>
            <p><strong>Status:</strong> <span class="badge badge-closed">NO ACTIVE SHIFT</span></p>
            
            <form method="POST" action="shift_log.php">
                <input type="hidden" name="action" value="start_shift">
                <div class="form-group">
                    <label for="opening_balance">Opening Drawer Balance ($)</label>
                    <input type="number" step="0.01" id="opening_balance" name="opening_balance" required>
                </div>
                <button type="submit" class="btn btn-success">Start New Shift</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Historical Shift Table -->
    <div class="card">
        <h3>Recent Branch Shifts</h3>
        <table>
            <thead>
                <tr>
                    <th>Shift ID</th>
                    <th>User</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Opening ($)</th>
                    <th>Closing ($)</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shift_history)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">No shift records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($shift_history as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['shift_id']) ?></td>
                            <td><?= htmlspecialchars($log['username'] ?? 'User #' . $log['user_id']) ?></td>
                            <td><?= htmlspecialchars($log['start_time']) ?></td>
                            <td><?= htmlspecialchars($log['end_time'] ?? 'N/A') ?></td>
                            <td><?= number_format($log['opening_balance'], 2) ?></td>
                            <td><?= $log['closing_balance'] !== null ? number_format($log['closing_balance'], 2) : '-' ?></td>
                            <td>
                                <?php if ($log['status'] === 'active'): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-closed">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($log['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
