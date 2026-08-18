<?php
session_start();
require "conn.php";

if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $p_id = (int)$_SESSION['pharmacy_id'];

    // Security: Only delete if the supplier belongs to this pharmacy
    $sql = "DELETE FROM suppliers WHERE id = $id AND pharmacy_id = $p_id";

    if (mysqli_query($conn, $sql)) {
        echo "success";
    } else {
        echo "error";
    }
}
?>