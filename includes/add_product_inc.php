<?php
session_start();
require "conn.php"; // Make sure path is correct

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_product'])) {
    
    // 1. Capture Data (Using 's' for strings to prevent the '0' issue)
    $pharmacy_id  = $_SESSION['pharmacy_id'];
    $branch_id    = $_SESSION['branch_id'];
    
    $item_name    = mysqli_real_escape_string($conn, $_POST['item_name']);
    $price        = mysqli_real_escape_string($conn, $_POST['price']);
    $cost         = mysqli_real_escape_string($conn, $_POST['cost']);
    $category     = mysqli_real_escape_string($conn, $_POST['product_group']);
    $quantity     = mysqli_real_escape_string($conn, $_POST['quantity']);
    $capacity     = mysqli_real_escape_string($conn, $_POST['capacity']);
    $expiry_date  = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    $barcode      = mysqli_real_escape_string($conn, $_POST['barcode']);
    $tax_rate     = 16.00; // Default Zambian VAT
    $is_active    = 1;

    // 2. Validation
    if (empty($item_name)) {
        header("Location: ../pharmacy/add_product.php?error=empty_name");
        exit();
    }

    // 3. Prepare SQL (Ensuring item_name is treated as a String 's')
    $sql = "INSERT INTO store_items (
                pharmacy_id, branch_id, item_name, price, tax_rate, 
                barcode, cost, product_group, quantity, capacity, 
                is_active, expiry_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $sql);
    
    // "i" = integer, "s" = string, "d" = double/decimal
    // item_name is the 3rd param, must be "s"
    mysqli_stmt_bind_param($stmt, "iissdsisiiis", 
        $pharmacy_id, 
        $branch_id, 
        $item_name, 
        $price, 
        $tax_rate, 
        $barcode, 
        $cost, 
        $category, 
        $quantity, 
        $capacity, 
        $is_active, 
        $expiry_date
    );

    if (mysqli_stmt_execute($stmt)) {
        header("Location: ../pharmacy/pharmacy_stock.php?success=added");
    } else {
        echo "Error: " . mysqli_error($conn);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
} else {
    header("Location: ../pharmacy/add_product.php");
    exit();
}