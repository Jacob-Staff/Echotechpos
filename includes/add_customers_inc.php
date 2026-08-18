<?php
session_start();
require "../includes/conn.php";

header('Content-Type: text/plain'); 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!$conn) {
        http_response_code(500);
        die("Database connection error.");
    }

    // Sanitize and collect form data
    $branch_id          = mysqli_real_escape_string($conn, $_POST['branch_id'] ?? '');
    $patient_no         = mysqli_real_escape_string($conn, $_POST['patient_no'] ?? '');
    $patient_name       = mysqli_real_escape_string($conn, $_POST['patient_name'] ?? '');
    $dob                = mysqli_real_escape_string($conn, $_POST['dob'] ?? '');
    $location           = mysqli_real_escape_string($conn, $_POST['location'] ?? '');
    $patient_condation  = mysqli_real_escape_string($conn, $_POST['patient_condation'] ?? 'No');
    $status             = mysqli_real_escape_string($conn, $_POST['status'] ?? '0');
    
    // Mapping: Use patient_name for first_name
    $first_name = $patient_name;
    
    // Construct the SQL query with ALL columns that MUST be addressed
    $sql = "INSERT INTO patients (
        patient_no,
        branch_id,
        first_name,         /* Mapped from patient_name */
        last_name,          /* Set to NULL or empty string (now optional) */
        contact_number,     /* Set to NULL (now optional) */
        invoice_number,     /* Set to NULL (now optional) */
        patient_condation,
        status,
        dob,
        location
    ) VALUES (
        '$patient_no', 
        '$branch_id', 
        '$first_name', 
        NULL, /* last_name */
        NULL, /* contact_number */
        NULL, /* invoice_number */
        '$patient_condation', 
        '$status',
        '$dob', 
        '$location'
    )";
    
    if (mysqli_query($conn, $sql)) {
        http_response_code(200);
        echo "Patient added successfully.";
    } else {
        http_response_code(500);
        // !! Crucial Debugging Step: SHOW THE ACTUAL ERROR !!
        echo "Error: " . mysqli_error($conn); 
    }
} else {
    http_response_code(405);
    echo "Invalid request method.";
}
?>