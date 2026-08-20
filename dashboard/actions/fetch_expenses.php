<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../includes/conn.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    echo '<tr><td colspan="5" class="text-center text-danger py-3">Session expired. Please log in again.</td></tr>';
    exit;
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];

$sql = "SELECT id, name, amount, category, expense_date FROM expenses WHERE pharmacy_id = $pharmacy_id AND branch_id = $branch_id ORDER BY expense_date DESC, id DESC";
$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $amount = (float)$row['amount'];
        echo '<tr>';
        echo '<td class="fw-bold text-dark">' . htmlspecialchars($row['name']) . '</td>';
        echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['category']) . '</span></td>';
        echo '<td>' . date('d M Y', strtotime($row['expense_date'])) . '</td>';
        echo '<td class="text-end fw-bold text-danger amt" data-amt="' . $amount . '">K' . number_format($amount, 2) . '</td>';
        echo '<td class="text-center">';
        echo '<button type="button" class="btn btn-outline-danger btn-sm px-2 delete-expense" data-id="' . $row['id'] . '"><i class="fas fa-trash-alt"></i></button>';
        echo '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="5" class="text-center py-4 text-muted">No expenses recorded yet.</td></tr>';
}
