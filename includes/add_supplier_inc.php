<?php
session_start();
require "conn.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Sanitize all inputs
    $name    = mysqli_real_escape_string($conn, $_POST['name']);
    $cp      = mysqli_real_escape_string($conn, $_POST['contact_person'] ?? ''); // Added this for the Contact Person field
    $phone   = mysqli_real_escape_string($conn, $_POST['phone']);
    $email   = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    
    // 2. Multi-tenant IDs from session (Ensuring they are integers)
    $p_id = isset($_SESSION['pharmacy_id']) ? (int)$_SESSION['pharmacy_id'] : 8;
    $b_id = isset($_SESSION['branch_id'])   ? (int)$_SESSION['branch_id']   : 10;

    if ($p_id == 0) {
        echo "Session Error: Pharmacy ID not found.";
        exit();
    }

    // 3. The Query - Matching your DB table structure exactly
    // Column order: pharmacy_id, name, contact_person, phone, email, address, branch_id
    $sql = "INSERT INTO suppliers (pharmacy_id, name, contact_person, phone, email, address, branch_id) 
            VALUES ('$p_id', '$name', '$cp', '$phone', '$email', '$address', '$b_id')";

    if (mysqli_query($conn, $sql)) {
        echo "success";
    } else {
        // This will tell us if there is a specific DB error like a missing column
        echo "DB Error: " . mysqli_error($conn);
    }
}
?>