<?php
session_start();
require "../includes/conn.php"; // Adjust path if needed

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. Collect Data from the Online Store form
    $pharmacy_id = mysqli_real_escape_string($conn, $_POST['pharmacy_id']);
    $branch_id   = mysqli_real_escape_string($conn, $_POST['branch_id']);
    $client_id   = $_SESSION['client_id'] ?? 0; // 0 if guest
    $test_type   = mysqli_real_escape_string($conn, $_POST['test_type']);
    $notes       = mysqli_real_escape_string($conn, $_POST['notes']);
    $status      = "Pending";
    $uploaded_at = date('Y-m-d H:i:s');

    // 2. Handle File Upload
    if (isset($_FILES['lab_file']) && $_FILES['lab_file']['error'] == 0) {
        $target_dir = "uploads/lab_results/";
        
        // Create directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $file_ext = pathinfo($_FILES["lab_file"]["name"], PATHINFO_EXTENSION);
        $file_name = "LAB_" . time() . "_" . rand(100, 999) . "." . $file_ext;
        $target_file = $target_dir . $file_name;

        if (move_uploaded_file($_FILES["lab_file"]["tmp_name"], $target_file)) {
            
            // 3. Insert into Database
            $sql = "INSERT INTO lab_results (client_id, pharmacy_id, branch_id, file_path, test_type, notes, status, uploaded_at) 
                    VALUES ('$client_id', '$pharmacy_id', '$branch_id', '$file_name', '$test_type', '$notes', '$status', '$uploaded_at')";
            
            if (mysqli_query($conn, $sql)) {
                // Success - Redirect back to the store with a success message
                header("Location: ../online_store.php?bid=$branch_id&status=success");
            } else {
                echo "Database Error: " . mysqli_error($conn);
            }
        } else {
            echo "File Upload Failed.";
        }
    } else {
        echo "No file selected or upload error.";
    }
}
?>s