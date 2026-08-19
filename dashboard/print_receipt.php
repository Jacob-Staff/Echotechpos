<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/conn.php';

$sale_id = intval($_GET['id'] ?? 0);
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;

if (!$sale_id) {
    die("Invalid Sale ID.");
}

// 1. Fetch Sale Header, Branch, and Pharmacy Info
$sql = "SELECT s.*, 
               b.branch_name, b.location, b.phone as branch_phone, 
               p.name as corp_name, p.phone as corp_phone
        FROM sales s 
        LEFT JOIN branches b ON s.branch_id = b.id 
        LEFT JOIN pharmacies p ON s.pharmacy_id = p.id
        WHERE s.id = ?" . ($pharmacy_id ? " AND s.pharmacy_id = $pharmacy_id" : "") . " LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) { 
    die("Sale record not found."); 
}

// 2. Fetch Sale Items from 'sales_items' joined with 'store_items'
$item_sql = "SELECT si.*, st.item_name
             FROM sales_items si 
             JOIN store_items st ON si.product_id = st.id 
             WHERE si.sale_id = ?";
$item_stmt = $conn->prepare($item_sql);
$item_stmt->bind_param("i", $sale_id);
$item_stmt->execute();
$items = $item_stmt->get_result();

$issued_by_name = $sale['issued_by'] ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo htmlspecialchars($sale['invoice']); ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body { 
            font-family: 'Courier New', Courier, monospace; 
            font-size: 12px; 
            color: #000; 
            background: #fff;
            width: 78mm; 
            margin: 0 auto; 
            padding: 8px;
            line-height: 1.3;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        
        .header { 
            border-bottom: 1px dashed #000; 
            padding-bottom: 8px; 
            margin-bottom: 8px; 
            text-align: center;
        }
        .corp-title { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .branch-title { font-size: 13px; margin-top: 2px; }

        .meta-info {
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
            margin-bottom: 8px;
            font-size: 11px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
        }

        .table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 8px;
        }
        .table th { 
            border-bottom: 1px solid #000; 
            text-align: left; 
            padding: 4px 0;
            font-size: 11px;
        }
        .table td { 
            padding: 4px 0; 
            vertical-align: top;
        }

        .totals {
            border-top: 1px dashed #000;
            padding-top: 6px;
            margin-top: 4px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .grand-total {
            font-size: 15px;
            font-weight: bold;
            border-top: 1px solid #000;
            border-bottom: 1px dashed #000;
            padding: 4px 0;
            margin-top: 4px;
        }

        .footer { 
            margin-top: 10px; 
            padding-top: 6px; 
            text-align: center; 
            font-size: 10px; 
        }

        .btn-group {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 12px;
        }
        .btn {
            padding: 6px 14px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-print { background: #10b981; color: #fff; }
        .btn-close { background: #6b7280; color: #fff; }

        @media print { 
            .no-print { display: none !important; } 
            body { width: 100%; padding: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print btn-group">
        <button onclick="window.print()" class="btn btn-print">Print Receipt</button>
        <button onclick="window.close()" class="btn btn-close">Close</button>
    </div>

    <div class="header">
        <div class="corp-title"><?php echo strtoupper(htmlspecialchars($sale['corp_name'] ?? 'PHARMANOVA')); ?></div>
        <div class="branch-title"><?php echo htmlspecialchars($sale['branch_name'] ?? 'Main Branch'); ?></div>
        <div><?php echo htmlspecialchars($sale['location'] ?? 'Lusaka, Zambia'); ?></div>
        <div>Tel: <?php echo htmlspecialchars($sale['branch_phone'] ?? $sale['corp_phone'] ?? 'N/A'); ?></div>
    </div>

    <div class="meta-info">
        <div class="meta-row">
            <span>Invoice:</span>
            <span class="bold"><?php echo htmlspecialchars($sale['invoice']); ?></span>
        </div>
        <div class="meta-row">
            <span>Date:</span>
            <span><?php echo date('d/m/Y H:i', strtotime($sale['created_at'])); ?></span>
        </div>
        <div class="meta-row">
            <span>Issued By:</span>
            <span><?php echo htmlspecialchars($issued_by_name); ?></span>
        </div>
        <div class="meta-row">
            <span>Payment Method:</span>
            <span><?php echo strtoupper(htmlspecialchars($sale['payment_method'] ?? 'Cash')); ?></span>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 50%;">Item</th>
                <th style="width: 15%; text-align: center;">Qty</th>
                <th style="width: 35%; text-align: right;">Total (K)</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $items->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                <td style="text-align: center;"><?php echo $row['quantity']; ?></td>
                <td style="text-align: right;"><?php echo number_format($row['unit_price'] * $row['quantity'], 2); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>K<?php echo number_format($sale['subtotal'], 2); ?></span>
        </div>
        <div class="total-row">
            <span>VAT (16%):</span>
            <span>K<?php echo number_format($sale['vat_amount'], 2); ?></span>
        </div>
        <div class="total-row grand-total">
            <span>TOTAL DUE:</span>
            <span>K<?php echo number_format($sale['total_amount'], 2); ?></span>
        </div>
    </div>

    <div class="footer">
        <p>Thank you for your business!</p>
        <p>Medicines sold are non-refundable.</p>
        <p>*** Get Well Soon ***</p>
    </div>

</body>
</html>
