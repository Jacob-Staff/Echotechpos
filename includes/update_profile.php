<?php
session_start();
require "conn.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$user_id = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobile = mysqli_real_escape_string($conn, $_POST['mobile_number']);
    $new_password = $_POST['new_password'];

    // 1. Update Mobile Number
    $sql = "UPDATE users SET mobile_number = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $mobile, $user_id);
    
    if ($stmt->execute()) {
        $response = "success";
    } else {
        exit("Error updating profile");
    }

    // 2. Update Password (only if a new one was typed)
    if (!empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $pass_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $pass_stmt->bind_param("si", $hashed_password, $user_id);
        $pass_stmt->execute();
    }

    // Return 'success' so the AJAX 'showCustomAlert' triggers
    echo "success";
    exit;
}