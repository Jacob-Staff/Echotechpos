<?php
session_start();
require "../../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM help_inquiries 
    WHERE pharmacy_id = ? AND branch_id = ? AND status = 'Pending'
");
$stmt->bind_param("ii", $pharmacy_id, $branch_id);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

echo $total;
?>