<?php
session_start();
require "../includes/conn.php";

// 1. Capture Data from URL
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$order_ref = isset($_GET['ref']) ? $_GET['ref'] : 'Unknown';
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 10;

// 2. Fetch Branch Details for Branding
$stmt = $conn->prepare("SELECT b.*, p.name AS pharmacy_name FROM branches b JOIN pharmacies p ON b.pharmacy_id = p.id WHERE b.id = ?");
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();

// 3. CLEAR CART (Crucial so they don't order twice)
unset($_SESSION['cart']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status | Echo Prime</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root { --echo-teal: #003339; --echo-green: #00b386; }
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .status-card { max-width: 500px; margin: 80px auto; background: white; border-radius: 15px; overflow: hidden; border: none; }
        .status-header { background: var(--echo-teal); color: white; padding: 40px 20px; text-align: center; }
        .status-icon { font-size: 60px; color: var(--echo-green); }
        .ref-box { background: #f1f1f1; padding: 10px; border-radius: 8px; font-family: monospace; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="card status-card shadow-lg">
        <div class="status-header">
            <div class="status-icon">
                <i class="mdi mdi-check-circle"></i>
            </div>
            <h2 class="fw-bold">Order Received!</h2>
            <p class="mb-0 opacity-75">Thank you for choosing <?php echo $branch['pharmacy_name'] ?? 'Echo Prime'; ?></p>
        </div>
        
        <div class="card-body p-4 text-center">
            <p class="text-muted">Your order has been logged into our system. Our pharmacist at <strong><?php echo $branch['branch_name'] ?? 'Lusaka Branch'; ?></strong> is currently reviewing it.</p>
            
            <div class="my-4">
                <small class="text-uppercase fw-bold text-muted d-block mb-1">Order Reference</small>
                <div class="ref-box text-primary fs-5"><?php echo htmlspecialchars($order_ref); ?></div>
            </div>

            <?php if($status == 'pending'): ?>
                <div class="alert alert-info border-0">
                    <i class="mdi mdi-information-outline me-2"></i>
                    <strong>Action Required:</strong> Please complete your Mobile Money prompt on your phone to finalize the delivery.
                </div>
            <?php endif; ?>

            <hr>
            
            <div class="d-grid gap-2">
                <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-dark py-3 fw-bold rounded-pill">
                    <i class="mdi mdi-arrow-left me-2"></i> Back to Store
                </a>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $branch['phone']); ?>" class="btn btn-outline-success py-2 fw-bold rounded-pill">
                    <i class="mdi mdi-whatsapp me-2"></i> Chat with Pharmacist
                </a>
            </div>
        </div>
        
        <div class="card-footer bg-light text-center py-3 border-0">
            <small class="text-muted">&copy; <?php echo date('Y'); ?> Echo Prime Ltd. Delivering Health Safely.</small>
        </div>
    </div>
</div>

</body>
</html>