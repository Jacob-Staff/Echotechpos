<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php';

// Protection: Only Admins can process money
require_admin();

if (isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    $admin_id = $_SESSION['user_id'];
    $current_month = date('F Y');

    // 1. Get the staff member's salary details
    $user_query = $conn->query("SELECT username, salary_amount FROM users WHERE id = $user_id");
    
    if ($user_query && $user_row = $user_query->fetch_assoc()) {
        $salary = $user_row['salary_amount'];
        $username = $user_row['username'];

        // 2. Insert into payroll_history
        $stmt = $conn->prepare("INSERT INTO payroll_history (user_id, amount_paid, payment_month, processed_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("idsi", $user_id, $salary, $current_month, $admin_id);

        if ($stmt->execute()) {
            // Success: Redirect back with a message
            header("Location: staff_management.php?paid=1&staff=" . urlencode($username));
            exit();
        } else {
            die("Error processing payment: " . $conn->error);
        }
    }
} else {
    header("Location: staff_management.php");
    exit();
}