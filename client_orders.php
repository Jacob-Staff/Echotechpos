<?php
require "api/store_header.php"; 
require "includes/conn.php";

if (!isset($_SESSION['client_id'])) {
    header("Location: login_client.php");
    exit();
}

$client_id = $_SESSION['client_id'];

// Fetch orders from the new clients_orders table
$query = "SELECT * FROM clients_orders WHERE client_id = '$client_id' ORDER BY order_date DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Orders | Echo Prime Pharmacy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root { --echo-teal: #003339; --echo-green: #00b386; }
        body { background: #f4f7f6; font-family: 'IBM Plex Sans', sans-serif; }
        .order-card { background: white; border-radius: 15px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .table thead th { background: #f8f9fa; color: #888; font-size: 11px; text-transform: uppercase; border: none; padding: 15px; }
        .table tbody td { vertical-align: middle; padding: 15px; border-bottom: 1px solid #eee; font-size: 14px; }
        
        /* Status Badge Logic */
        .badge-status { padding: 5px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
        .bg-pending { background: #fff4e5; color: #ff9800; }
        .bg-completed { background: #e6fcf5; color: #087f5b; }
        .bg-processing { background: #e7f5ff; color: #1971c2; }
        .bg-cancelled { background: #fff5f5; color: #fa5252; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row align-items-center mb-4">
        <div class="col-6">
            <h4 class="fw-bold mb-0">Order History</h4>
            <p class="text-muted small">Manage your prescriptions and purchases</p>
        </div>
        <div class="col-6 text-end">
            <a href="online_store.php" class="btn btn-sm btn-outline-dark rounded-pill px-3">Continue Shopping</a>
        </div>
    </div>

    <div class="order-card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Order Ref</th>
                        <th>Date</th>
                        <th>Payment</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold text-primary">#<?php echo $row['order_number']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                            <td><small class="text-muted"><?php echo $row['payment_method']; ?></small></td>
                            <td class="fw-bold">ZMW <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>
                                <span class="badge-status bg-<?php echo strtolower($row['status']); ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-light btn-sm border fw-bold rounded-pill px-3" 
                                        onclick="showDetails('<?php echo $row['order_number']; ?>')">
                                    View details
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="mdi mdi-package-variant-closed d-block fs-1"></i>
                                <p>No orders found in your account.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold">Order Breakdown</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-3">
                    <p class="small text-muted">Detailed view for order history is being synced...</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if(file_exists("includes/footer.php")) require "includes/footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function showDetails(orderNum) {
        var myModal = new bootstrap.Modal(document.getElementById('orderModal'));
        document.getElementById('modalBody').innerHTML = '<div class="p-3 text-center">Fetching details for <strong>' + orderNum + '</strong>...</div>';
        myModal.show();
    }
</script>
</body>
</html>