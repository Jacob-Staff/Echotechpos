<?php
session_start();
require "conn.php";

if (isset($_POST['record_loss'])) {
    // 1. Capture and Sanitize Input
    $pharmacy_id = (int)$_SESSION['pharmacy_id'];
    $branch_id   = (int)$_SESSION['branch_id'];
    $item_id     = (int)$_POST['item_id'];
    $qty_to_remove = (int)$_POST['qty_damaged'];
    $notes       = mysqli_real_escape_with_string($conn, $_POST['notes']);
    $recorded_by = $_SESSION['user_id']; // Assuming you store the user's ID in session

    // 2. Double Check Stock Availability (Security Step)
    $check_sql = "SELECT quantity, item_name FROM store_items WHERE id = ? AND pharmacy_id = ? AND branch_id = ?";
    $stmt_check = $conn->prepare($check_sql);
    $stmt_check->bind_param("iii", $item_id, $pharmacy_id, $branch_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    $item_data = $res_check->fetch_assoc();

    if ($item_data && $item_data['quantity'] >= $qty_to_remove) {
        
        // Start Transaction to ensure both steps happen or neither happens
        $conn->begin_transaction();

        try {
            // 3. UPDATE main stock: Subtract the damaged quantity
            $update_sql = "UPDATE store_items SET quantity = quantity - ? WHERE id = ? AND pharmacy_id = ? AND branch_id = ?";
            $stmt_update = $conn->prepare($update_sql);
            $stmt_update->bind_param("iiii", $qty_to_remove, $item_id, $pharmacy_id, $branch_id);
            $stmt_update->execute();

            /* 4. OPTIONAL: LOG THE DAMAGE 
            If you have a table for stock history, insert a record here.
            If not, you can create one later to track losses for Echo Prime Ltd reports.
            */

            $conn->commit();
            header("Location: ../pharmacy_stock.php?status=damaged_recorded&item=" . urlencode($item_data['item_name']));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../pharmacy_stock.php?status=error&msg=TransactionFailed");
            exit();
        }

    } else {
        // Not enough stock to remove
        header("Location: ../pharmacy_stock.php?status=error&msg=InvalidQuantity");
        exit();
    }
} else {
    header("Location: ../pharmacy_stock.php");
    exit();
}