<?php
session_start();
// We use ../../ to go up two levels to find the includes folder
require_once '../../includes/conn.php';

// 1. Security Check
if (!isset($_SESSION['branch_id'], $_SESSION['user_id'])) {
    die("Session expired. Please log in again.");
}

$branch_id = $_SESSION['branch_id'];
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and clean the data
    $name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['customer_phone']);
    $total = floatval($_POST['total_amount']);
    $deposit = floatval($_POST['deposit']);
    $due_date = $_POST['due_date'];
    
    $balance = $total - $deposit;
    $status = ($balance <= 0) ? 'Completed' : 'Pending';

    // 2. Insert the Layby Agreement
    $sql = "INSERT INTO laybys (branch_id, user_id, customer_name, customer_phone, total_amount, deposit, balance_due, due_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iissdddss", $branch_id, $user_id, $name, $phone, $total, $deposit, $balance, $due_date, $status);

    if ($stmt->execute()) {
        $layby_id = $stmt->insert_id;

        // 3. Record the initial payment automatically
        if ($deposit > 0) {
            $p_sql = "INSERT INTO layby_payments (layby_id, branch_id, user_id, payment_amount, method, notes) 
                      VALUES (?, ?, ?, ?, 'Cash', 'Initial Deposit')";
            $p_stmt = $conn->prepare($p_sql);
            $p_stmt->bind_param("iiid", $layby_id, $branch_id, $user_id, $deposit);
            $p_stmt->execute();
        }
        echo "success";
    } else {
        echo "Database Error: " . $conn->error;
    }
}
?>