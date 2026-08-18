<?php
session_start();
require_once "../../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

header('Content-Type: application/json');

try {
    // Delete layby items first (if you have a separate table for products per layby)
    $stmt_items = $conn->prepare("DELETE li FROM layby_items li 
                                  JOIN laybys l ON li.layby_id = l.id
                                  WHERE l.pharmacy_id = ? AND l.branch_id = ? AND l.balance_due = 0");
    $stmt_items->bind_param("ii", $pharmacy_id, $branch_id);
    $stmt_items->execute();

    // Delete fully paid laybys
    $stmt_laybys = $conn->prepare("DELETE FROM laybys 
                                   WHERE pharmacy_id = ? AND branch_id = ? AND balance_due = 0");
    $stmt_laybys->bind_param("ii", $pharmacy_id, $branch_id);
    $stmt_laybys->execute();

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}