<?php
session_start();
require "../includes/conn.php";

// Define the upload directory
$target_dir = "../uploads/products/";

// 1. Check for Single Image Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['single_image_update_id'])) {
    $item_id = mysqli_real_escape_string($conn, $_POST['single_image_update_id']);
    $file_field = "image_" . $item_id;

    if (isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] == 0) {
        // Create directory if it doesn't exist
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $file_ext = pathinfo($_FILES[$file_field]['name'], PATHINFO_EXTENSION);
        // Rename file to item_ID_timestamp to keep it unique
        $new_file_name = "prod_" . $item_id . "_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_file_name;

        if (move_uploaded_file($_FILES[$file_field]['tmp_name'], $target_file)) {
            // Update database with the new file name
            $update_img_sql = "UPDATE store_items SET image = '$new_file_name' WHERE id = '$item_id'";
            mysqli_query($conn, $update_img_sql);
            header("Location: online_manager.php?success=Image updated successfully");
            exit();
        } else {
            header("Location: online_manager.php?error=Failed to upload image");
            exit();
        }
    }
}

// 2. Check for Bulk Status Action (Online/Offline)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['item_ids']) && isset($_POST['action'])) {
    // Sanitize the IDs
    $clean_ids = array_map(function($id) use ($conn) {
        return mysqli_real_escape_string($conn, $id);
    }, $_POST['item_ids']);
    
    $ids_string = implode(',', $clean_ids);
    $status = ($_POST['action'] == 'online') ? 1 : 0;
    
    $sql = "UPDATE store_items SET is_online = $status WHERE id IN ($ids_string)";
    
    if (mysqli_query($conn, $sql)) {
        header("Location: online_manager.php?success=Items updated successfully");
    } else {
        header("Location: online_manager.php?error=Database error: " . mysqli_error($conn));
    }
    exit();
}

// If nothing was caught
header("Location: online_manager.php?error=No action performed");
exit();
?>