<?php
session_start();
require_once("../../includes/conn.php");

$response = ['status' => 'error', 'message' => 'Invalid Request'];

if (isset($_POST['action']) && $_POST['action'] == 'add') {
    $p_id = intval($_POST['product_id']);
    $qty = intval($_POST['qty']);

    // Initialize cart if not exists
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // If item exists, increase quantity, else add new
    if (isset($_SESSION['cart'][$p_id])) {
        $_SESSION['cart'][$p_id] += $qty;
    } else {
        $_SESSION['cart'][$p_id] = $qty;
    }

    $count = array_sum($_SESSION['cart']);
    $response = ['status' => 'success', 'cart_count' => $count];
}

echo json_encode($response);