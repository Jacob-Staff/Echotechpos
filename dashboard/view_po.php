<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) {
    header("Location: purchase_orders_list.php?error=invalid_po");
    exit();
}

// Fetch Purchase Order & Supplier details
$po_stmt = $conn->prepare("
    SELECT po.*, s.name AS supplier_name, u.full_name AS creator_name, p.name AS pharmacy_name, b.branch_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN pharmacies p ON po.pharmacy_id = p.id
    LEFT JOIN branches b ON po.branch_id = b.id
    WHERE po.id = ? AND po.pharmacy_id = ? AND po.branch_id = ?
    LIMIT 1
");
$po_stmt->bind_param("iii", $po_id, $pharmacy_id, $branch_id);
$po_stmt->execute();
$po = $po_stmt->get_result()->fetch_assoc();
$po_stmt->close();

if (!$po) {
    header("Location: purchase_orders_list.php?error=po_not_found");
    exit();
}

// Fetch Line Items
$items_stmt = $conn->prepare("
    SELECT pi.*, si.item_name, si.strength 
    FROM purchase_items pi
    LEFT JOIN store_items si ON pi.product_id = si.id
    WHERE pi.purchase_id = ?
");
$items_stmt->bind_param("i", $po_id);
$items_stmt->execute();
$items_res = $items_stmt->get_result();
$items = [];
while ($row = $items_res->fetch_assoc()) {
    $items[] = $row;
}
$items_stmt->close();

require_once "../includes/head.php";
?>

<style>
:root {
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
}
body { background-color: var(--bg-page) !important; font-family: 'Inter', system-ui, sans-serif; }
.po-wrapper { padding: 2rem 1.5rem; }
.invoice-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }

@media print {
    body * { visibility: hidden; }
    #printable-po, #printable-po * { visibility: visible; }
    #printable-po { position: absolute; left: 0; top: 0; width: 100%; }
    .no-print { display: none !important; }
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper po-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <a href="purchase_orders_list.php" class="btn btn-outline-secondary btn-sm fw-bold">
                    <i class="fas fa-arrow-left me-1"></i> Back to Orders List
                </a>
                <div class="d-flex gap-2">
                    <button onclick="window.print();" class="btn btn-outline-primary fw-bold">
                        <i class="fas fa-print me-1"></i> Print PO
                    </button>
                    <?php if ($po['status'] === 'ordered' || $po['status'] === 'partial'): ?>
                        <a href="receive_po.php?id=<?= $po['id'] ?>" class="btn btn-success fw-bold">
                            <i class="fas fa-boxes me-1"></i> Receive Stock
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="invoice-card p-4 p-md-5" id="printable-po">
                
                <div class="row border-bottom pb-4 mb-4">
                    <div class="col-sm-6">
                        <h3 class="fw-bold text-primary mb-1"><?= htmlspecialchars(strtoupper($po['pharmacy_name'] ?: 'PHARMANOVA')) ?></h3>
                        <p class="text-muted mb-0"><?= htmlspecialchars($po['branch_name'] ?: 'Main Branch') ?></p>
                    </div>
                    <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                        <h4 class="fw-bold text-dark mb-1">PURCHASE ORDER</h4>
                        <div class="fw-bold text-secondary"><?= htmlspecialchars($po['po_number'] ?: '#' . $po['id']) ?></div>
                        <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1 rounded-pill mt-2 text-uppercase fw-bold">
                            Status: <?= htmlspecialchars($po['status']) ?>
                        </span>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-sm-6">
                        <h6 class="text-muted text-uppercase fw-bold small">Supplier Details:</h6>
                        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($po['supplier_name'] ?: 'Unknown Supplier') ?></h5>
                    </div>
                    <div class="col-sm-6 text-sm-end">
                        <p class="mb-1"><strong>Order Date:</strong> <?= date('d M Y, H:i', strtotime($po['po_date'])) ?></p>
                        <p class="mb-1"><strong>Expected Delivery:</strong> <?= $po['expected_date'] ? date('d M Y', strtotime($po['expected_date'])) : 'N/A' ?></p>
                        <p class="mb-0"><strong>Created By:</strong> <?= htmlspecialchars($po['creator_name'] ?: 'System') ?></p>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product Name</th>
                                <th class="text-center">Ordered Qty</th>
                                <th class="text-center">Received Qty</th>
                                <th class="text-end">Est. Unit Price (ZMW)</th>
                                <th class="text-end">Total Price (ZMW)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_total = 0;
                            foreach ($items as $index => $item): 
                                $line_total = $item['quantity'] * $item['unit_price'];
                                $grand_total += $line_total;
                            ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong class="text-dark"><?= htmlspecialchars($item['item_name']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars($item['strength'] ?? '') ?></div>
                                    </td>
                                    <td class="text-center fw-bold"><?= $item['quantity'] ?></td>
                                    <td class="text-center text-muted"><?= $item['qty_received'] ?></td>
                                    <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($line_total, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end fw-bold">Grand Total:</td>
                                <td class="text-end fw-bold text-primary fs-5">ZMW <?= number_format($grand_total, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            </div>

        </div>
    </div>

    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>