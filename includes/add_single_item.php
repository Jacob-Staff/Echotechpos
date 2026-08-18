<?php
session_start();
require "conn.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $p_id = (int)$_SESSION['pharmacy_id'];
    $b_id = (int)$_SESSION['branch_id'];

    $name     = mysqli_real_escape_string($conn, $_POST['item_name']);
    $strength = mysqli_real_escape_string($conn, $_POST['strength']);
    $barcode  = mysqli_real_escape_string($conn, $_POST['barcode']);
    $cost     = mysqli_real_escape_string($conn, $_POST['cost']);
    $price    = mysqli_real_escape_string($conn, $_POST['price']);
    $qty      = (int)$_POST['quantity'];
    $expiry   = mysqli_real_escape_string($conn, $_POST['expiry_date']);

    // Combine Name and Strength for the display, or save separately if you have a column
    $full_name = $name . " " . $strength;

    $sql = "INSERT INTO store_items (pharmacy_id, branch_id, item_name, price, cost, barcode, quantity, expiry_date, is_active) 
            VALUES ($p_id, $b_id, '$full_name', '$price', '$cost', '$barcode', $qty, '$expiry', 1)";

    if (mysqli_query($conn, $sql)) {
        header("Location: ../dashboard/update_items_stock.php?status=success");
    } else {
        die("Query Failed: " . mysqli_error($conn));
    }
}
?>