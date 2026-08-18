<?php
session_start();
require "../includes/conn.php";
require "../includes/auth.php";

$id = intval($_GET['id']);
$action = $_GET['action'];

if ($action == 'set' && isset($_GET['price'])) {
    $price = floatval($_GET['price']);
    // Set online price and ensure it is marked as online visible
    $sql = "UPDATE store_items SET online_price = '$price', is_online = 1 WHERE id = '$id'";
} else {
    // Remove offer by setting online_price to 0 or same as price
    $sql = "UPDATE store_items SET online_price = 0 WHERE id = '$id'";
}

if (mysqli_query($conn, $sql)) {
    header("Location: online_manager.php?msg=success");
} else {
    header("Location: online_manager.php?msg=error");
}