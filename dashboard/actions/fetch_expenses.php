<?php
session_start();
require_once "../../includes/conn.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

$query = "SELECT * FROM expenses WHERE pharmacy_id = ? AND branch_id = ? ORDER BY expense_date DESC, id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $pharmacy_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td><span class='badge bg-light text-dark'>" . htmlspecialchars($row['category']) . "</span></td>";
        echo "<td>" . date('d M Y', strtotime($row['expense_date'])) . "</td>";
        echo "<td class='text-end fw-bold amt-val' data-amt='{$row['amount']}'>K" . number_format($row['amount'], 2) . "</td>";
        echo "<td class='text-center'>
                <button class='btn btn-sm btn-outline-danger delete-expense' data-id='{$row['id']}'>
                    <i class='fas fa-trash'></i>
                </button>
              </td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='5' class='text-center text-muted'>No expenses recorded for this branch.</td></tr>";
}