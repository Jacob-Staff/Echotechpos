<?php
session_start();
require_once "../../includes/conn.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

$status = $_GET['status'] ?? 'Active';

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
        $paid = $row['total_amount'] - $row['balance_due'];
        echo "<tr>
                <td class='text-start ps-3 fw-bold'>".htmlspecialchars($row['customer_name'])."</td>
                <td>K".number_format($row['total_amount'], 2)."</td>
                <td class='text-success fw-bold'>K".number_format($paid, 2)."</td>
                <td class='text-danger fw-bold'>K".number_format($row['balance_due'], 2)."</td>
                <td>".htmlspecialchars($row['due_date'])."</td>
                <td class='pe-3'>
                    <a href='view_layby.php?id=".$row['id']."' class='btn btn-sm btn-info text-white fw-bold'>
                        <i class='fas fa-eye me-1'></i> View/Pay
                    </a>
                </td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-muted py-4'>No records found for this status</td></tr>";
}
