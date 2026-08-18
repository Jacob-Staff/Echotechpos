<?php
session_start();
require "../includes/head.php";
require "../includes/conn.php";
require "../includes/auth.php";

$id = (int)($_GET['id'] ?? 0);
$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

// 1. Fetch Invoice Header & Branch Info (with security checks)
$sql = "SELECT s.*, u.username as issuer, p.name as pharm_name, b.* FROM sales s
        JOIN pharmacies p ON s.pharmacy_id = p.id
        JOIN branches b ON s.branch_id = b.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = $id AND s.pharmacy_id = $p_id AND s.branch_id = $b_id LIMIT 1";

$res = mysqli_query($conn, $sql);
$inv = mysqli_fetch_assoc($res);

if (!$inv) {
    die("<div class='container mt-5'><div class='alert alert-danger'>Invoice not found or access denied.</div></div>");
}

// FIX: Handle different possible column names for address and phone
$display_addr = $inv['address'] ?? $inv['location'] ?? 'Zambia';
$display_phone = $inv['phone'] ?? $inv['contact'] ?? 'N/A';

// 2. Fetch Invoice Items
$items_sql = "SELECT si.*, st.item_name 
              FROM sales_items si 
              JOIN store_items st ON si.product_id = st.id 
              WHERE si.sale_id = $id AND si.pharmacy_id = $p_id";
$items_res = mysqli_query($conn, $items_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice #<?php echo $inv['invoice']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="dist/css/style.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
        .page-wrapper { padding-top: 20px; }
        
        /* The Actual Receipt Card */
        .invoice-card { 
            background: #fff; 
            border-radius: 8px; 
            box-shadow: 0 0 20px rgba(0,0,0,0.05); 
            max-width: 850px; 
            margin: 0 auto; 
            padding: 40px;
            position: relative;
        }
        .invoice-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: #22a7f0; }
        
        .table-invoice thead th { background-color: #f8f9fa; font-size: 11px; text-transform: uppercase; padding: 12px; }
        .total-section { background: #fdfdfd; padding: 20px; border-radius: 4px; border: 1px solid #eee; }

        @media print {
            .no-print, .left-sidebar, .topbar, .header { display: none !important; }
            body { background: white !important; }
            .page-wrapper { margin: 0 !important; padding: 0 !important; }
            .invoice-card { box-shadow: none; border: none; padding: 0; margin: 0; max-width: 100%; }
        }
    </style>
</head>

<body>
<div id="main-wrapper" data-layout="vertical" data-sidebartype="full">

    <?php require "../includes/header.php"; ?>
    <?php require "../includes/aside.php"; ?>

    <div class="page-wrapper">
        <div class="container-fluid">
            
            <div class="no-print d-flex justify-content-between align-items-center mb-4">
                <a href="today_transactions.php" class="btn btn-outline-dark btn-sm">
                    <i class="mdi mdi-arrow-left"></i> Back to Reports
                </a>
                <button onclick="window.print()" class="btn btn-primary btn-sm px-4 shadow-sm">
                    <i class="mdi mdi-printer me-1"></i> Print / Save PDF
                </button>
            </div>

            <div class="invoice-card">
                <div class="row mb-5">
                    <div class="col-7">
                        <h3 class="fw-bold text-dark mb-1"><?php echo strtoupper($inv['pharm_name']); ?></h3>
                        <p class="text-muted small mb-0">
                            <i class="mdi mdi-map-marker"></i> <?php echo $display_addr; ?><br>
                            <i class="mdi mdi-phone"></i> <?php echo $display_phone; ?>
                        </p>
                    </div>
                    <div class="col-5 text-end">
                        <h2 class="text-muted opacity-50 fw-bold mb-0">INVOICE</h2>
                        <div class="fw-bold">#<?php echo $inv['invoice']; ?></div>
                        <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($inv['created_at'])); ?></small>
                    </div>
                </div>

                <div class="row mb-4 py-3 border-top border-bottom">
                    <div class="col-4">
                        <small class="text-muted text-uppercase d-block fw-bold">Customer</small>
                        <span>Walk-in Client</span>
                    </div>
                    <div class="col-4">
                        <small class="text-muted text-uppercase d-block fw-bold">Method</small>
                        <span class="badge bg-light text-dark border"><?php echo strtoupper($inv['payment_method']); ?></span>
                    </div>
                    <div class="col-4 text-end">
                        <small class="text-muted text-uppercase d-block fw-bold">Status</small>
                        <span class="text-success fw-bold">PAID</span>
                    </div>
                </div>

                <table class="table table-invoice mb-4">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($item = mysqli_fetch_assoc($items_res)): 
                            $line = $item['quantity'] * $item['unit_price'];
                        ?>
                        <tr>
                            <td class="fw-bold"><?php echo $item['item_name']; ?></td>
                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                            <td class="text-end">K<?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="text-end fw-bold">K<?php echo number_format($line, 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div class="row justify-content-end">
                    <div class="col-md-5">
                        <div class="total-section">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Subtotal</span>
                                <span>K<?php echo number_format($inv['total_amount'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2" style="font-size: 1.2rem; color: #22a7f0;">
                                <span>TOTAL</span>
                                <span>K<?php echo number_format($inv['total_amount'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 pt-4 text-center border-top">
                    <p class="mb-1 fw-bold">Thank you for choosing <?php echo $inv['pharm_name']; ?>!</p>
                    <p class="text-muted small">Please retain this receipt for your records.</p>
                    <div class="mt-3 small text-muted">
                        Issuer: <?php echo $inv['issuer'] ?? 'Pharmacist'; ?> | Branch: <?php echo $inv['branch_name']; ?>
                    </div>
                    <p class="mt-4 text-muted" style="font-size: 10px;">NOTE: NO REFUNDS ON MEDICINES ONCE THEY LEAVE THE PREMISES.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/libs/jquery/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>