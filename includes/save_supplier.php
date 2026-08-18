<?php
session_start();
require "conn.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['pharmacy_id'])) {
    $pharmacy_id = $_SESSION['pharmacy_id'];
    $name = mysqli_real_escape_string($conn, $_POST['supplier_name']);
    $id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;

    if ($id > 0) {
        // Update existing (Ensuring it belongs to this pharmacy)
        $stmt = $conn->prepare("UPDATE suppliers SET supplier_name = ? WHERE id = ? AND pharmacy_id = ?");
        $stmt->bind_param("sii", $name, $id, $pharmacy_id);
    } else {
        // Insert new
        $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, pharmacy_id) VALUES (?, ?)");
        $stmt->bind_param("si", $name, $pharmacy_id);
    }

    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "Error saving data.";
    }
}