<?php
session_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

// Ensure ID exists
if (!isset($_GET['id'])) {
    header("Location: purchases-orders.php");
    exit();
}

$order_id = intval($_GET['id']);

// Fetch main purchase order
$order_sql = "
    SELECT po.*, s.name AS supplier_name, s.contact AS supplier_contact, s.address AS supplier_address 
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ?
";
$stmt = $conn->prepare($order_sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();
$order = $order_result->fetch_assoc();

if (!$order) {
    echo "<div class='alert alert-danger text-center mt-5'>Purchase Order not found.</div>";
    exit();
}

// Fetch items
$items_sql = "
    SELECT poi.*, si.name AS item_name 
    FROM purchase_order_items poi
    JOIN store_items si ON poi.product_id = si.id
    WHERE poi.purchase_order_id = ?
";
$stmt_items = $conn->prepare($items_sql);
$stmt_items->bind_param("i", $order_id);
$stmt_items->execute();
$items_result = $stmt_items->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>View Purchase Order - <?php echo htmlspecialchars($order['invoice_no']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font/css/materialdesignicons.min.css" rel="stylesheet">
<style>
body {
    font-family: 'Segoe UI', sans-serif;
    background-color: #f9fafb;
}
.container {
    max-width: 900px;
    margin-top: 40px;
}
.card {
    border-radius: 15px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}
.table th {
    background-color: #198754;
    color: white;
    text-align: center;
}
.table td {
    text-align: center;
    vertical-align: middle;
}
.header-bar {
    background-color: #198754;
    color: #fff;
    border-radius: 10px 10px 0 0;
    padding: 15px 25px;
}
.header-bar h4 {
    margin: 0;
    font-weight: 600;
}
.print-btn {
    float: right;
    color: white;
    background-color: #0d6efd;
    border: none;
    border-radius: 30px;
    padding: 8px 20px;
}
.print-btn:hover {
    background-color: #0b5ed7;
}
.footer-note {
    text-align: center;
    color: #666;
    font-size: 14px;
    margin-top: 30px;
}
<?php if ($order['status'] === 'Pending'): ?>
    <a href="receive_order.php?id=<?php echo $order['id']; ?>" 
       class="btn btn-warning text-white ms-2"
       onclick="return confirm('Confirm receiving this order? Stock will be updated automatically.');">
       <i class="mdi mdi-truck-check"></i> Receive Order
    </a>
<?php endif; ?>
</style>
</head>

<body>
<?php include "../includes/header.php"; ?>
<?php include "../includes/aside.php"; ?>

<div class="page-wrapper">
    <div class="container">
        <div class="card">
            <div class="header-bar d-flex justify-content-between align-items-center">
                <h4><i class="mdi mdi-file-document-outline me-2"></i> Purchase Order Details</h4>
                <button class="print-btn" onclick="window.print()">
                    <i class="mdi mdi-printer"></i> Print
                </button>
            </div>

            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h5 class="text-success fw-bold">Supplier Information</h5>
                        <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></p>
                        <p class="mb-1"><strong>Contact:</strong> <?php echo htmlspecialchars($order['supplier_contact'] ?? 'N/A'); ?></p>
                        <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($order['supplier_address'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <h5 class="text-success fw-bold">Order Information</h5>
                        <p class="mb-1"><strong>Invoice No:</strong> <?php echo htmlspecialchars($order['invoice_no']); ?></p>
                        <p class="mb-1"><strong>Status:</strong> 
                            <span class="badge bg-<?php echo ($order['status'] === 'Pending') ? 'warning' : 'success'; ?>">
                                <?php echo htmlspecialchars($order['status']); ?>
                            </span>
                        </p>
                        <p class="mb-1"><strong>Date:</strong> <?php echo date("d M Y", strtotime($order['created_at'] ?? $order['date'] ?? 'now')); ?></p>
                    </div>
                </div>

                <hr>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product Name</th>
                                <th>Quantity</th>
                                <th>Unit Cost (ZMW)</th>
                                <th>Subtotal (ZMW)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $i = 1;
                            $total = 0;
                            while ($item = $items_result->fetch_assoc()): 
                                $total += $item['subtotal'];
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo number_format($item['quantity'], 2); ?></td>
                                <td><?php echo number_format($item['unit_cost'], 2); ?></td>
                                <td><?php echo number_format($item['subtotal'], 2); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Grand Total (ZMW):</th>
                                <th><?php echo number_format($total, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="footer-note">
                    <p>Pharmacy POS — Purchase Order System | &copy; 2025 All Rights Reserved</p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
