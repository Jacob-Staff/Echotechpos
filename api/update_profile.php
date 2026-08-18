<?php
session_start();
require "../includes/conn.php"; // Notice the ../ to go up one folder

// Security Check: Ensure user is logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: ../login_client.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $client_id = $_SESSION['client_id'];
    
    // Sanitize inputs to prevent SQL Injection
$full_name = $conn->real_escape_string($_POST['full_name']);
$phone     = $conn->real_escape_string($_POST['phone']);
$email     = $conn->real_escape_string($_POST['email']);

    // Optional: Basic validation
    if (empty($full_name) || empty($phone) || empty($email)) {
        header("Location: ../profile.php?error=emptyfields");
        exit();
    }

    // Update the database
    $sql = "UPDATE clients SET 
            full_name = '$full_name', 
            phone = '$phone', 
            email = '$email' 
            WHERE id = '$client_id'";

    if ($conn->query($sql)) {
        // Update the session name so the header changes immediately
        $_SESSION['client_name'] = $full_name;
        
        // Redirect back with a success message
        header("Location: ../profile.php?status=success");
        exit();
    } else {
        // Redirect back with an error message
        header("Location: ../profile.php?status=error&msg=" . urlencode($conn->error));
        exit();
    }
} else {
    // If someone tries to access this file directly without POST
    header("Location: ../profile.php");
    exit();
}