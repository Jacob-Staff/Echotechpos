<?php
session_start();
require "conn.php"; // Make sure path is correct

if (isset($_POST['branch_id'])) {
    $branch_id = intval($_POST['branch_id']);
    
    // Fetch the name of the new branch to update the session
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['branch_name'] = $row['branch_name'];
        echo "ok";
    } else {
        echo "Branch not found in database.";
    }
} else {
    echo "No branch ID provided.";
}
?>