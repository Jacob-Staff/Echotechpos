<?php
session_start();
require "conn.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $pharmacy_id = $_SESSION['pharmacy_id'];
    $purchase_no = mysqli_real_escape_string($conn, $_POST['purchase_no']);
    $pay_date = $_POST['date'];
    $total_paid = $_POST['total'];
    $status = 1; // Marked as Paid

    // 🔹 Secure Update: Only update if it belongs to THIS pharmacy
    $sql = "UPDATE purchase_order 
            SET status = ?, date = ?, total = ? 
            WHERE purchase_no = ? AND pharmacy_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdsi", $status, $pay_date, $total_paid, $purchase_no, $pharmacy_id);

    if ($stmt->execute()) {
        // Logic to update inventory would go here
        echo "success";
    } else {
        echo "Database error: Unable to process payment.";
    }
} else {
    echo "Unauthorized access.";
}