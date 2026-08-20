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

$error_msg   = '';
$success_msg = '';

// Fetch Purchase Order Header
$po_stmt = $conn->prepare("
    SELECT po.*, s.name AS supplier_name 
    FROM purchase_orders po 
    LEFT JOIN suppliers s ON po.supplier_id = s.id 
    WHERE po.id = ? AND po.pharmacy_id = ? AND po.branch_id = ? 
    LIMIT 1
");
$po_stmt->bind_param("iii", $po_id, $pharmacy_id, $branch_id);
$po_stmt->execute();
$po_data = $po_stmt->get_result()->fetch_assoc();
$po_stmt->close();

if (!$po_data) {
    header("Location: purchase_orders_list.php?error=po_not_found");
    exit();
}

// Handle Receiving Stock Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $received_items = $_POST['received_items'] ?? [];

    if (!empty($received_items)) {
        $conn->begin_transaction();
        try {
            $user_id = (int)($_SESSION['user_id'] ?? 0);
            $all_fully_received = true;

            // Prepare Queries
            $update_pi = $conn->prepare("
                UPDATE purchase_items 
                SET qty_received = qty_received + ?, batch_no = ?, expiry_date = ? 
                WHERE id = ? AND purchase_id = ?
            ");

            $update_stock = $conn->prepare("
                UPDATE store_items 
                SET quantity = quantity + ? 
                WHERE id = ? AND pharmacy_id = ? AND branch_id = ?
            ");

            foreach ($received_items as $item_id => $data) {
                $item_id      = (int)$item_id;
                $qty_received = (int)($data['qty_to_receive'] ?? 0);
                $batch_no     = trim($data['batch_no'] ?? '');
                $expiry_date  = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
                $product_id   = (int)($data['product_id'] ?? 0);
                $ordered_qty  = (int)($data['ordered_qty'] ?? 0);
                $prev_received= (int)($data['prev_received'] ?? 0);

                if ($qty_received > 0) {
                    // 1. Update purchase item row
                    $update_pi->bind_param("issii", $qty_received, $batch_no, $expiry_date, $item_id, $po_id);
                    $update_pi->execute();

                    // 2. Increment store items stock
                    $update_stock->bind_param("iiii", $qty_received, $product_id, $pharmacy_id, $branch_id);
                    $update_stock->execute();
                }

                // Check if total received matches total ordered
                if (($prev_received + $qty_received) < $ordered_qty) {
                    $all_fully_received = false;
                }
            }

            $update_pi->close();
            $update_stock->close();

            // Determine final PO status
            $new_status = $all_fully_received ? 'received' : 'partial';

            $status_stmt = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE id = ? AND pharmacy_id = ?");
            $status_stmt->bind_param("sii", $new_status, $po_id, $pharmacy_id);
            $status_stmt->execute();
            $status_stmt->close();

            $conn->commit();

            header("Location: purchase_orders_list.php?msg=" . urlencode("Stock received and inventory updated successfully for PO " . ($po_data['po_number'] ?: "#" . $po_id)));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error processing stock reception: " . $e->getMessage();
        }
    } else {
        $error_msg = "No items submitted for stock reception.";
    }
}

// Fetch Purchase Items
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
.form-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper po-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Stock Reception (Goods Received)</h3>
                    <p class="text-muted mb-0 small">
                        <strong>PO Number:</strong> <?= htmlspecialchars($po_data['po_number'] ?: '#' . $po_data['id']) ?> | 
                        <strong>Supplier:</strong> <?= htmlspecialchars($po_data['supplier_name'] ?: 'N/A') ?>
                    </p>
                </div>
                <div>
                    <a href="purchase_orders_list.php" class="btn btn-outline-secondary btn-sm fw-bold">
                        <i class="fas fa-arrow-left me-1"></i> Back to PO List
                    </a>
                </div>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                    <div><?= htmlspecialchars($error_msg) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="receive_po.php?id=<?= $po_id ?>" autocomplete="off">
                <div class="form-card p-4">
                    <div class="table-responsive mb-4">
                        <table class="table table-hover align-middle border">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-center">Ordered</th>
                                    <th class="text-center">Prev. Received</th>
                                    <th style="width: 150px;">Receive Qty</th>
                                    <th>Batch / Lot #</th>
                                    <th>Expiry Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php 
                                        $remaining = $item['quantity'] - $item['qty_received'];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong class="text-dark"><?= htmlspecialchars($item['item_name']) ?></strong>
                                            <div class="small text-muted"><?= htmlspecialchars($item['strength'] ?? '') ?></div>
                                            
                                            <!-- Hidden tracking inputs -->
                                            <input type="hidden" name="received_items[<?= $item['id'] ?>][product_id]" value="<?= $item['product_id'] ?>">
                                            <input type="hidden" name="received_items[<?= $item['id'] ?>][ordered_qty]" value="<?= $item['quantity'] ?>">
                                            <input type="hidden" name="received_items[<?= $item['id'] ?>][prev_received]" value="<?= $item['qty_received'] ?>">
                                        </td>
                                        <td class="text-center fw-bold"><?= $item['quantity'] ?></td>
                                        <td class="text-center text-muted"><?= $item['qty_received'] ?></td>
                                        <td>
                                            <input type="number" 
                                                   name="received_items[<?= $item['id'] ?>][qty_to_receive]" 
                                                   class="form-control fw-bold text-success" 
                                                   min="0" 
                                                   max="<?= max(0, $remaining) ?>" 
                                                   value="<?= max(0, $remaining) ?>" 
                                                   required>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="received_items[<?= $item['id'] ?>][batch_no]" 
                                                   class="form-control" 
                                                   placeholder="Batch #" 
                                                   value="<?= htmlspecialchars($item['batch_no'] ?? '') ?>">
                                        </td>
                                        <td>
                                            <input type="date" 
                                                   name="received_items[<?= $item['id'] ?>][expiry_date]" 
                                                   class="form-control" 
                                                   value="<?= htmlspecialchars($item['expiry_date'] ?? '') ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-success fw-bold px-4">
                            <i class="fas fa-check-circle me-2"></i> Confirm Stock Arrival & Update Inventory
                        </button>
                    </div>
                </div>
            </form>

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