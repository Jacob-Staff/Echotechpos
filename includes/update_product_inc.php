<?php
// 🔥 FIX: Start output buffering immediately to catch any stray output 
ob_start();

session_start();
require "conn.php";
require "auth.php"; 

// ✅ Ensure branch_id is available
if (!isset($_SESSION['branch_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Branch not found in session. Please log in again.']);
    exit;
}

$branch_id = intval($_SESSION['branch_id']);

// 🔥 Clear buffer
if (ob_get_level() > 0) {
    ob_clean();
}

// Set Content-Type to JSON for AJAX response
header('Content-Type: application/json');

// Helper function for JSON response
function json_response($status, $message) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

// 1️⃣ Check request method
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response("error", "Invalid request method.");
}

// 2️⃣ Extract and validate data
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$item_name = trim($_POST['item_name'] ?? '');
$price = floatval($_POST['price'] ?? 0);
$quantity = intval($_POST['quantity'] ?? 0);
$expiry_date = trim($_POST['expiry_date'] ?? '');
$product_group = trim($_POST['category'] ?? ''); 
$tax_rate = floatval($_POST['tax_rate'] ?? 0);
$new_image = null;

if ($id <= 0 || empty($item_name) || $price <= 0 || $quantity < 0 || empty($expiry_date) || empty($product_group) || $tax_rate < 0) {
    json_response("error", "One or more required fields are missing or invalid.");
}

// 3️⃣ Handle File Upload (Optional)
if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
    $target_dir = "../uploads/";
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0777, true)) {
            json_response("error", "Failed to create uploads directory.");
        }
    }

    $image_file_type = strtolower(pathinfo($_FILES["product_image"]["name"], PATHINFO_EXTENSION));
    $new_image = uniqid('product_') . '.' . $image_file_type;
    $target_file = $target_dir . $new_image;

    if (!in_array($image_file_type, ['jpg', 'jpeg', 'png', 'gif'])) {
        json_response("error", "Only JPG, JPEG, PNG & GIF files are allowed.");
    }

    if ($_FILES["product_image"]["size"] > 5000000) {
        json_response("error", "Your file is too large (max 5MB).");
    }

    if (!move_uploaded_file($_FILES["product_image"]["tmp_name"], $target_file)) {
        error_log("Failed to move uploaded file. Check permissions: " . $target_dir);
        json_response("error", "Error uploading image. Check server permissions.");
    }
}

// 4️⃣ SQL Query with Branch Filter
$sql = "UPDATE store_items 
        SET item_name = ?, price = ?, quantity = ?, expiry_date = ?, product_group = ?, tax_rate = ?";

$types = "sdisid";
$variables_to_bind = [$item_name, $price, $quantity, $expiry_date, $product_group, $tax_rate];

if ($new_image !== null) {
    $sql .= ", image_path = ?"; 
    $types .= "s";
    $variables_to_bind[] = $new_image;
}

// ✅ Branch-specific WHERE clause
$sql .= " WHERE id = ? AND branch_id = ?";
$types .= "ii";
$variables_to_bind[] = $id;
$variables_to_bind[] = $branch_id;

// 5️⃣ Prepare & Execute
if ($conn === false) {
    json_response("error", "Database connection error.");
}

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    json_response("error", "SQL Prepare failed: " . htmlspecialchars($conn->error));
}

// Bind parameters safely
$bind_params = [$types];
foreach ($variables_to_bind as $key => $value) {
    $bind_params[] = &$variables_to_bind[$key];
}

call_user_func_array([$stmt, 'bind_param'], $bind_params);

if (!$stmt->execute()) {
    json_response("error", "SQL Execute failed: " . htmlspecialchars($stmt->error));
}

// ✅ Success handling
if ($stmt->affected_rows > 0) {
    json_response("success", "Product updated successfully for this branch.");
} else {
    json_response("warning", "Update successful, but no changes detected or product not found in this branch.");
}

$stmt->close();
$conn->close();

// Clean up buffer
if (ob_get_level() > 0) {
    ob_end_clean();
}
?>