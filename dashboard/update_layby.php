<?php
require_once '../includes/conn.php';

if (!isset($_GET['id'])) {
    die("Missing layby ID");
}

$id = intval($_GET['id']);

// Fetch layby
$sql = "SELECT * FROM laybys WHERE id = $id";
$result = mysqli_query($conn, $sql);
$layby = mysqli_fetch_assoc($result);

if (!$layby) {
    die("Layby not found");
}

// Fetch payments
$payments = mysqli_query($conn, "SELECT * FROM layby_payments WHERE layby_id = $id ORDER BY payment_date ASC");
?>

<h2>Layby Details</h2>
<p><b>Customer:</b> <?= htmlspecialchars($layby['customer_name']) ?> (<?= htmlspecialchars($layby['customer_phone']) ?>)</p>
<p><b>Total Amount:</b> $<?= number_format($layby['total_amount'], 2) ?></p>
<p><b>Deposit:</b> $<?= number_format($layby['deposit'], 2) ?></p>
<p><b>Balance Due:</b> $<?= number_format($layby['balance_due'], 2) ?></p>
<p><b>Status:</b> <?= htmlspecialchars($layby['status']) ?></p>
<p><b>Due Date:</b> <?= htmlspecialchars($layby['due_date']) ?></p>

<h3>Payment History</h3>
<table border="1" cellpadding="5" cellspacing="0">
    <tr>
        <th>Date</th>
        <th>Amount</th>
        <th>Method</th>
        <th>Notes</th>
    </tr>
    <?php while ($p = mysqli_fetch_assoc($payments)): ?>
        <tr>
            <td><?= $p['payment_date'] ?></td>
            <td>$<?= number_format($p['payment_amount'], 2) ?></td>
            <td><?= ucfirst($p['method']) ?></td>
            <td><?= htmlspecialchars($p['notes']) ?></td>
        </tr>
    <?php endwhile; ?>
</table>
