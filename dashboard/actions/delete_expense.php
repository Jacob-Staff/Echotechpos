<?php
session_start();
require_once "../../includes/conn.php";

// Check if ID is provided and user is logged in
if (isset($_POST['id']) && isset($_SESSION['pharmacy_id'])) {
    $id = intval($_POST['id']);
    $pharmacy_id = $_SESSION['pharmacy_id'];
    $branch_id = $_SESSION['branch_id'];

    // Security: Only delete if the expense belongs to this pharmacy/branch
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
    $stmt->bind_param("iii", $id, $pharmacy_id, $branch_id);
    
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    $stmt->close();
} else {
    echo "unauthorized";
}
exit();