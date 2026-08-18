<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/conn.php';
require_admin();

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['pharmacy_logo']) && $pharmacy_id > 0) {
    $file = $_FILES['pharmacy_logo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (in_array($ext, $allowed)) {
        // Create a unique name: e.g., logo_10_17123456.png
        $new_filename = "logo_" . $pharmacy_id . "_" . time() . "." . $ext;
        $upload_path = "../uploads/logos/" . $new_filename;

        // Ensure directory exists
        if (!is_dir('../uploads/logos/')) {
            mkdir('../uploads/logos/', 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Update Database
            $stmt = $conn->prepare("UPDATE pharmacies SET logo = ? WHERE id = ?");
            $stmt->bind_param("si", $new_filename, $pharmacy_id);
            
            if ($stmt->execute()) {
                header("Location: admin_dashboard.php?msg=Logo updated successfully");
            } else {
                header("Location: admin_dashboard.php?error=Database update failed");
            }
        } else {
            header("Location: admin_dashboard.php?error=Failed to move file");
        }
    } else {
        header("Location: admin_dashboard.php?error=Invalid file type");
    }
} else {
    header("Location: admin_dashboard.php");
}
exit();