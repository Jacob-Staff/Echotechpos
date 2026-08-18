<?php
session_start();
require_once "../../includes/conn.php";

// Authorization Check
if (!isset($_SESSION['pharmacy_id']) || !isset($_POST['type'])) {
    echo "unauthorized";
    exit;
}

$pharmacy_id = $_SESSION['pharmacy_id'];
$branch_id   = $_SESSION['branch_id'];
$type        = $_POST['type'];

$current_year = date('Y');
$current_month = date('m');

if ($type === 'month') {
    // Uses expense_date to target this specific month/year for this branch
    $stmt = $conn->prepare("DELETE FROM expenses WHERE pharmacy_id = ? AND branch_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
    $stmt->bind_param("iiii", $pharmacy_id, $branch_id, $current_month, $current_year);
} elseif ($type === 'year') {
    // Targets the entire current year for this branch
    $stmt = $conn->prepare("DELETE FROM expenses WHERE pharmacy_id = ? AND branch_id = ? AND YEAR(expense_date) = ?");
    $stmt->bind_param("iii", $pharmacy_id, $branch_id, $current_year);
} else {
    echo "invalid_type";
    exit;
}

if ($stmt->execute()) {
    echo "success";
} else {
    echo "error: " . $stmt->error;
}

$stmt->close();
$conn->close();