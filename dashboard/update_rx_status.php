<?php
session_start();
require "../includes/conn.php";
require "../includes/auth.php";

if (isset($_GET['id']) && isset($_GET['status'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    $status = mysqli_real_escape_string($conn, $_GET['status']);
    $branch_id = $_SESSION['branch_id'];

    // Update the status
    $sql = "UPDATE prescriptions SET status = '$status' WHERE id = '$id' AND branch_id = '$branch_id'";
    
    if (mysqli_query($conn, $sql)) {
        // Redirect back with a success message
        header("Location: view_prescriptions.php?msg=Status Updated");
    } else {
        echo "Error updating record: " . mysqli_error($conn);
    }
} else {
    header("Location: view_prescriptions.php");
}
?>