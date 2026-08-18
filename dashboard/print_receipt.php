<?php
// CRITICAL: Ensure no whitespace before this tag
session_start();
require_once '../includes/conn.php';

if (!isset($_GET['id'])) {
    die("Invalid Sale ID.");
}

$sale_id = intval($_GET['id']);
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id = $_SESSION['branch_id'] ?? 0;

// 1. Fetch Sale Header & Branch Info (Filtered by pharmacy_id for security)
$sql = "SELECT s.*, b.branch_name, b.address, b.phone, u.username, p.name as corp_name
        FROM sales s 
        JOIN branches b ON s.branch_id = b.id 
        JOIN users u ON s.user_id = u.id 
        JOIN pharmacies p ON s.pharmacy_id = p.id
        WHERE s.id = ? AND s.pharmacy_id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $sale_id, $pharmacy_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) { 
    die("Sale record not found or access denied."); 
}

// 2. Fetch Sale Items
$item_sql = "SELECT si.*, st.item_name
             FROM sale_items si 
             JOIN store_items st ON si.item_id = st.id 
             WHERE si.sale_id = ?";
$item_stmt = $conn->prepare($item_sql);
$item_stmt->bind_param("i", $sale_id);
$item_stmt->execute();
$items = $item_stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo htmlspecialchars($sale['invoice']); ?></title>
    <style>
        body { 
            font-family: 'Courier New', Courier, monospace; 
            font-size: 13px; 
            color: #000; 
            width: 80mm; 
            margin: 0 auto; 
            padding: 10px; 
        }
        .text-center { text-align: center; display: block; width: 100%; }
        .bold { font-weight: bold; }
        .header { border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; text-align: center;}
        .table { width: 100%; border-collapse: collapse; margin-top: 10px;}
        .table th { border-bottom: 1px solid #000; text-align: left; padding: 5px 0;}
        .table td { padding: 5px 0; vertical-align: top;}
        .footer { border-top: 1px dashed #000; margin-top: 15px; padding-top: 10px; text-align: center; font-size: 11px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px;">Print Receipt</button>
        <button onclick="window.close()" style="padding: 10px 20px;">Close Window</button>
    </div>

    <div class="header">
        <span class="bold" style="font-size: 16px;"><?php echo strtoupper($sale['corp_name']); ?></span><br>
        <span style="font-size: 14px;"><?php echo htmlspecialchars($sale['branch_name']); ?></span><br>
        <?php echo htmlspecialchars($sale['address']); ?><br>
        Tel: <?php echo htmlspecialchars($sale['phone']); ?><br>
        <span class="bold">INV: <?php echo htmlspecialchars($sale['invoice']); ?></span>
    </div>

    <div style="margin-bottom: 10px;">
        Date: <?php echo date('d/M/Y H:i', strtotime($sale['created_at'])); ?><br>
        Staff: <?php echo htmlspecialchars($sale['username']); ?><br>
        Payment: <?php echo htmlspecialchars($sale['payment_method']); ?>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 50%;">Item</th>
                <th style="width: 15%;">Qty</th>
                <th style="width: 35%; text-align:right">Total (K)</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $items->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                <td><?php echo $row['quantity']; ?></td>
                <td style="text-align:right"><?php echo number_format($row['unit_price'] * $row['quantity'], 2); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div style="text-align: right; margin-top: 15px; border-top: 1px solid #000; padding-top: 5px;">
        <span class="bold" style="font-size: 16px;">TOTAL: K<?php echo number_format($sale['total_amount'], 2); ?></span>
    </div>

    <div class="footer">
        <p>Thank you for choosing us!<br>
        Medicines sold are not returnable.<br>
        *** Get well soon ***</p>
    </div>
</body>
</html>