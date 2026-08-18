<?php
session_start();
require "../includes/conn.php";

if(isset($_GET['id'])) {
    $id = $_GET['id'];
    // Switch 1 to 0 or 0 to 1
    $update = "UPDATE store_items SET is_online = NOT is_online WHERE id = '$id'";
    mysqli_query($conn, $update);
}
header("Location: online_manager.php");
?>