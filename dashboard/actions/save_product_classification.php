<?php
/**
 * EchoTech POS - Save Online Product Classification
 * Updates the main category plus the online group for one product.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

date_default_timezone_set('Africa/Lusaka');

require_once '../../includes/conn.php';
require_once '../../includes/auth.php';

function json_out(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$product_id  = (int)($_POST['product_id'] ?? 0);
$category    = trim((string)($_POST['category'] ?? ''));
$online_group = trim((string)($_POST['online_group'] ?? ''));

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    http_response_code(401);
    json_out(false, 'Your session has expired. Please log in again.');
}

if ($product_id <= 0) {
    http_response_code(422);
    json_out(false, 'Invalid product.');
}

if ($category === '') {
    http_response_code(422);
    json_out(false, 'Please select a category.');
}

/*
 * The online_group column is intentionally separate from category so the
 * existing POS category value remains useful while the online store gains
 * its second-level grouping.
 */
$columnCheck = $conn->query("SHOW COLUMNS FROM store_items LIKE 'online_group'");
if (!$columnCheck || $columnCheck->num_rows === 0) {
    http_response_code(500);
    json_out(false, 'The database is missing the online_group column. Run the supplied SQL migration first.');
}

/* Verify the product belongs to the current pharmacy and branch. */
$check = $conn->prepare("SELECT id, item_name FROM store_items WHERE id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1");
if (!$check) {
    http_response_code(500);
    json_out(false, 'Unable to verify the product.');
}
$check->bind_param('iii', $product_id, $pharmacy_id, $branch_id);
$check->execute();
$product = $check->get_result()->fetch_assoc();
$check->close();

if (!$product) {
    http_response_code(404);
    json_out(false, 'Product not found in the current branch.');
}

/* Save the classification. */
$update = $conn->prepare("UPDATE store_items SET category = ?, online_group = ? WHERE id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1");
if (!$update) {
    http_response_code(500);
    json_out(false, 'Unable to prepare the classification update.');
}
$update->bind_param('ssiii', $category, $online_group, $product_id, $pharmacy_id, $branch_id);

if (!$update->execute()) {
    $error = $update->error;
    $update->close();
    error_log('save_product_classification update failed: ' . $error);
    http_response_code(500);
    json_out(false, 'The product classification could not be saved.');
}
$update->close();

json_out(true, 'Product classification saved successfully.', [
    'product_id' => $product_id,
    'category' => $category,
    'online_group' => $online_group
]);
