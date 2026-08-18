<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php'; // Ensure user is logged in

if (!isset($_GET['id'])) {
    header("Location: lay_by_sell.php?error=missing_id");
    exit;
}

$layby_id = intval($_GET['id']);
$active_branch_id = $_SESSION['branch_id'] ?? 10;

// Fetch lay-by details with Branch Protection
$query = "SELECT * FROM laybys WHERE id = ? AND branch_id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $layby_id, $active_branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    die("Lay-by not found or permission denied.");
}

$layby = mysqli_fetch_assoc($result);
$success = "";
$error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deposit  = floatval($_POST['deposit']);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date']);
    $status   = mysqli_real_escape_string($conn, $_POST['status']);

    // Recalculate balance
    $total_amount = floatval($layby['total_amount']);
    $balance_due = $total_amount - $deposit;
    if ($balance_due < 0) $balance_due = 0;

    // Logic: Auto-complete if balance is 0
    if ($balance_due <= 0 && $status == 'Pending') {
        $status = 'Completed';
    }

    $update = "UPDATE laybys 
               SET deposit_amount = ?, 
                   balance_due = ?, 
                   due_date = ?, 
                   status = ? 
               WHERE id = ? AND branch_id = ?";
    
    $up_stmt = mysqli_prepare($conn, $update);
    mysqli_stmt_bind_param($up_stmt, "ddssii", $deposit, $balance_due, $due_date, $status, $layby_id, $active_branch_id);

    if (mysqli_stmt_execute($up_stmt)) {
        $success = "Lay-by updated successfully.";
        
        // RE-FETCH DATA for display
        $layby['deposit_amount'] = $deposit;
        $layby['balance_due'] = $balance_due;
        $layby['due_date'] = $due_date;
        $layby['status'] = $status;
    } else {
        $error = "Update failed: " . mysqli_error($conn);
    }
}
?>