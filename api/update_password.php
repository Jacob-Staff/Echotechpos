<?php
session_start();
require_once(__DIR__ . "/../includes/conn.php");

// 1. Security Check: Must be logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: ../login_client.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $client_id = $_SESSION['client_id'];
    
    // 2. Get and Sanitize Inputs (Matching your HTML names)
    $current_pass = $_POST['old_pass'];
    $new_pass     = $_POST['new_pass'];
    $confirm_pass = $_POST['confirm_pass'];

    // 3. Basic Validation
    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        header("Location: ../profile.php?status=error&msg=All fields are required");
        exit();
    }

    if ($new_pass !== $confirm_pass) {
        header("Location: ../profile.php?status=error&msg=New passwords do not match");
        exit();
    }

    // 4. Fetch the existing password from the database
    $stmt = $conn->prepare("SELECT password FROM clients WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // 5. Verify Current Password
    if ($user && password_verify($current_pass, $user['password'])) {
        
        // 6. Hash the NEW password and Update
        $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
        
        $update_stmt = $conn->prepare("UPDATE clients SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $client_id);
        
        if ($update_stmt->execute()) {
            header("Location: ../profile.php?status=success&msg=Password updated successfully");
        } else {
            header("Location: ../profile.php?status=error&msg=Database update failed");
        }
    } else {
        header("Location: ../profile.php?status=error&msg=Current password is incorrect");
    }
    
    $stmt->close();
    exit();

} else {
    header("Location: ../profile.php");
    exit();
}