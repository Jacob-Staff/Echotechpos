<?php
session_start();
require_once "../../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

// Get status from frontend (Active or Completed)
$status = $_GET['status'] ?? 'Active';

// Map status to database condition
// Assuming balance_due = 0 means Completed
if ($status === 'Completed') {
    $status_condition = "balance_due <= 0";
} else {
    $status_condition = "balance_due > 0";
}

$sql = "SELECT id, customer_name, total_amount, deposit, balance_due, due_date 
        FROM laybys 
        WHERE pharmacy_id = ? AND branch_id = ? AND $status_condition
        ORDER BY id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $pharmacy_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>".htmlspecialchars($row['customer_name'])."</td>
                <td>K".number_format($row['total_amount'], 2)."</td>
                <td>K".number_format($row['deposit'], 2)."</td>
                <td>K".number_format($row['balance_due'], 2)."</td>
                <td>".$row['due_date']."</td>
                <td>
                    <a href='view_layby.php?id=".$row['id']."' class='btn btn-xs btn-info'>
                        <i class='fas fa-eye'></i> View/Pay
                    </a>
                </td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-muted'>No records found for this status</td></tr>";
}
?>