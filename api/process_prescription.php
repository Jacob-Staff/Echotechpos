<?php
session_start();
require "../includes/conn.php";

// 1. Check if the request is actually a POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // 2. Collect and Sanitize Data
    $pharmacy_id = mysqli_real_escape_string($conn, $_POST['pharmacy_id']);
    $branch_id   = mysqli_real_escape_string($conn, $_POST['branch_id']);
    $client_id   = mysqli_real_escape_string($conn, $_POST['client_id']);
    $notes       = mysqli_real_escape_string($conn, $_POST['notes']);

    // 3. Handle File Upload
    if (isset($_FILES['prescription_file']) && $_FILES['prescription_file']['error'] == 0) {
        
        $file_name     = $_FILES['prescription_file']['name'];
        $file_tmp      = $_FILES['prescription_file']['tmp_name'];
        $file_ext      = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Generate a unique name to prevent overwriting (e.g., 17118822_rx.jpg)
        $new_file_name = time() . "_" . uniqid() . "." . $file_ext;
        
        // Path logic: Go into the 'api' folder and then 'uploads/prescriptions'
        // Adjust this if your process file is already inside the api folder
        $upload_path   = "../api/uploads/prescriptions/" . $new_file_name;

        // Ensure the directory exists
        if (!is_dir("../api/uploads/prescriptions/")) {
            mkdir("../api/uploads/prescriptions/", 0777, true);
        }

        if (move_uploaded_file($file_tmp, $upload_path)) {
            
            // 4. Insert into Database
            // Note: We use 'uploaded_at' and 'status' as per your standard table
            $sql = "INSERT INTO prescriptions (pharmacy_id, branch_id, client_id, file_path, notes, status, uploaded_at) 
                    VALUES ('$pharmacy_id', '$branch_id', '$client_id', '$new_file_name', '$notes', 'Pending', NOW())";

            if (mysqli_query($conn, $sql)) {
                // SUCCESS
                echo "<script>
                        alert('Prescription uploaded successfully!');
                        window.location.href = '../online_store.php?bid=$branch_id';
                      </script>";
            } else {
                // DATABASE ERROR
                die("Database Error: " . mysqli_error($conn));
            }

        } else {
            // FILE MOVE ERROR
            die("Error: Could not move the uploaded file. Check folder permissions.");
        }
    } else {
        // NO FILE ERROR
        die("Error: No file was uploaded or there was an upload error (Code: " . $_FILES['prescription_file']['error'] . ")");
    }
} else {
    // DIRECT ACCESS DENIED
    header("Location: upload_prescription.php");
    exit();
}
?>