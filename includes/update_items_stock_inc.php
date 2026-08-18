<?php
session_start();
require "conn.php"; 
require "auth.php";

if (isset($_POST['submit'])) {
    $p_id = (int)$_SESSION['pharmacy_id'];
    $item_id = (int)$_POST['id'];
    $add_qty = (int)$_POST['quantity'];
    $new_price = mysqli_real_escape_string($conn, $_POST['price']);
    $new_expiry = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    // Optional: if you add a barcode field to your manual form later
    $new_barcode = isset($_POST['barcode']) ? trim(mysqli_real_escape_string($conn, $_POST['barcode'])) : '';

    $check = mysqli_query($conn, "SELECT id, barcode FROM store_items WHERE id = $item_id AND pharmacy_id = $p_id LIMIT 1");
    
    if (mysqli_num_rows($check) > 0) {
        $row = mysqli_fetch_assoc($check);
        $existing_barcodes = $row['barcode'];

        // Manage barcode appending
        $final_barcodes = $existing_barcodes;
        if (!empty($new_barcode) && strpos($existing_barcodes, $new_barcode) === false) {
            $final_barcodes = empty($existing_barcodes) ? $new_barcode : $existing_barcodes . ", " . $new_barcode;
        }
        
        // Update Logic
        $update_query = "UPDATE store_items SET 
                         quantity = quantity + $add_qty,
                         barcode = '$final_barcodes'";

        if (!empty($new_price)) {
            $update_query .= ", price = '$new_price'";
        }

        if (!empty($new_expiry)) {
            $update_query .= ", expiry_date = '$new_expiry'";
        }

        $update_query .= " WHERE id = $item_id AND pharmacy_id = $p_id";

        if (mysqli_query($conn, $update_query)) {
            $_SESSION['msg'] = "Stock updated successfully!";
            header("Location: ../dashboard/update_stock.php?status=success");
            exit();
        } else {
            header("Location: ../dashboard/update_stock.php?status=error&msg=db_fail");
            exit();
        }
    } else {
        header("Location: ../dashboard/update_stock.php?status=error&msg=access_denied");
        exit();
    }
}