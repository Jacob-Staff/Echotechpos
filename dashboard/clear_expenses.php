<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php'; // Ensure only logged-in users can run this

// ✅ Security Check: Ensure the user is authorized to delete financial data
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id'])) {
    echo "Error: Unauthorized access.";
    exit;
}

if (!isset($_POST['type'])) {
    echo "Error: Type not specified";
    exit;
}

$type = $_POST['type'];
$branch_id = $_SESSION['branch_id']; // Target ONLY the current branch
$current_year = date('Y');
$current_month = date('m');

try {
    if ($type === 'month') {
        // Only delete expenses for THIS branch in THIS month/year
        $stmt = $conn->prepare("DELETE FROM expenses WHERE branch_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
        $stmt->bind_param("iii", $branch_id, $current_month, $current_year);
        $stmt->execute();
        echo "Successfully cleared all expenses for " . date('F Y') . " (Branch #$branch_id).";

    } elseif ($type === 'year') {
        // Only delete expenses for THIS branch in THIS year
        $stmt = $conn->prepare("DELETE FROM expenses WHERE branch_id = ? AND YEAR(date) = ?");
        $stmt->bind_param("ii", $branch_id, $current_year);
        $stmt->execute();
        echo "Successfully cleared all expenses for $current_year (Branch #$branch_id).";

    } else {
        echo "Invalid clear type specified.";
    }
} catch (Exception $e) {
    echo "System Error: " . $e->getMessage();
}

$stmt->close();
$conn->close();
?>