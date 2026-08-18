<?php
session_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

// Fetch expenses for this pharmacy & branch
$stmt = $conn->prepare("SELECT id, name, amount, expense_date, category, recorded_by, created_at FROM expenses WHERE pharmacy_id = ? AND branch_id = ? ORDER BY created_at DESC");
$stmt->bind_param("ii", $pharmacy_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses List</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<h2>Expenses List</h2>

<table border="1" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Amount</th>
            <th>Expense Date</th>
            <th>Category</th>
            <th>Recorded By</th>
            <th>Created At</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['id']; ?></td>
            <td><?php echo htmlspecialchars($row['name']); ?></td>
            <td><?php echo number_format($row['amount'],2); ?></td>
            <td><?php echo $row['expense_date']; ?></td>
            <td><?php echo htmlspecialchars($row['category']); ?></td>
            <td><?php echo $row['recorded_by']; ?></td>
            <td><?php echo $row['created_at']; ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>

<?php
$stmt->close();
?>