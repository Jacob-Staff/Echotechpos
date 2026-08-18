<?php
session_start();
require "../../includes/conn.php";

if (!isset($_POST['product_id']) || !isset($_FILES['product_image'])) {
    die("Invalid request");
}

$product_id = intval($_POST['product_id']);
$file = $_FILES['product_image'];

// ==============================
// VALIDATION
// ==============================
$allowed = ['image/jpeg','image/png','image/webp'];

if (!in_array($file['type'], $allowed)) {
    die("Invalid file type");
}

if ($file['size'] > 2 * 1024 * 1024) {
    die("File too large (Max 2MB)");
}

// ==============================
// CREATE FOLDER IF NOT EXISTS
// ==============================
$uploadDir = "../../uploads/products/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// ==============================
// GENERATE SAFE FILE NAME
// ==============================
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$newName = "prod_" . time() . "_" . rand(1000,9999) . "." . $ext;

$target = $uploadDir . $newName;

// ==============================
// MOVE FILE
// ==============================
if (!move_uploaded_file($file['tmp_name'], $target)) {
    die("Upload failed");
}

// ==============================
// SAVE TO DATABASE
// ==============================
$stmt = $conn->prepare("UPDATE store_items SET image=? WHERE id=?");
$stmt->bind_param("si", $newName, $product_id);
$stmt->execute();

// ==============================
// REDIRECT BACK
// ==============================
header("Location: ../online_manager.php?success=1");
exit;