<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_role   = $_SESSION['role'] ?? 'Staff';

if (!$pharmacy_id || !$branch_id || !$user_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$is_admin = in_array(strtolower($user_role), ['admin', 'manager', 'supervisor', 'superadmin']);
$message = '';

// Filter variable for the page
$status_filter = $_GET['filter_status'] ?? 'all';

// =========================================================================
// ACTION HANDLERS
// =========================================================================

// 1. INITIATE TRANSFER REQUEST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'initiate_transfer') {
    $to_branch_id = (int)($_POST['to_branch_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];

    if ($to_branch_id === $branch_id) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error!</strong> Cannot transfer stock to the same branch.</div>';
    } elseif (empty($product_ids) || count($product_ids) === 0) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error!</strong> Select at least one item to transfer.</div>';
    } else {
        $conn->begin_transaction();
        try {
            // Validate every selected product before creating the transfer.
            $validated_items = [];
            $seen_products = [];

            foreach ($product_ids as $index => $raw_p_id) {
                $p_id = (int)$raw_p_id;
                $qty  = (int)($quantities[$index] ?? 0);

                if ($p_id <= 0 || $qty <= 0) {
                    continue;
                }

                // Do not allow the same product to be transferred twice in one request.
                if (isset($seen_products[$p_id])) {
                    throw new Exception("The same product cannot be added more than once to a transfer.");
                }
                $seen_products[$p_id] = true;

                $stock_chk = $conn->prepare(
                    "SELECT id, item_name, strength, quantity
                     FROM store_items
                     WHERE id = ? AND branch_id = ? AND pharmacy_id = ? AND is_active = 1
                     LIMIT 1"
                );
                $stock_chk->bind_param("iii", $p_id, $branch_id, $pharmacy_id);
                $stock_chk->execute();
                $product = $stock_chk->get_result()->fetch_assoc();
                $stock_chk->close();

                if (!$product) {
                    throw new Exception("One of the selected products is no longer available in this branch.");
                }

                if ($qty > (int)$product['quantity']) {
                    throw new Exception(
                        "Insufficient stock for " . $product['item_name'] .
                        ". Available: " . (int)$product['quantity'] .
                        ", requested: " . $qty . "."
                    );
                }

                $validated_items[] = ['id' => $p_id, 'qty' => $qty];
            }

            if (empty($validated_items)) {
                throw new Exception("Select at least one valid product with a quantity greater than zero.");
            }
            $transfer_code = 'TRF-' . strtoupper(dechex(time())) . '-' . rand(100, 999);
            
            $stmt = $conn->prepare("INSERT INTO stock_transfers (pharmacy_id, from_branch_id, to_branch_id, transfer_code, status, requested_by, notes) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
            $stmt->bind_param("iiisis", $pharmacy_id, $branch_id, $to_branch_id, $transfer_code, $user_id, $notes);
            $stmt->execute();
            $transfer_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare("INSERT INTO stock_transfer_items (transfer_id, from_product_id, quantity) VALUES (?, ?, ?)");
            
            foreach ($validated_items as $transfer_item) {
                $p_id = $transfer_item['id'];
                $qty  = $transfer_item['qty'];

                $item_stmt->bind_param("iii", $transfer_id, $p_id, $qty);
                $item_stmt->execute();
            }
            $item_stmt->close();

            $conn->commit();
            $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Success!</strong> Transfer request <code>' . htmlspecialchars($transfer_code) . '</code> submitted for approval.</div>';
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// 2. APPROVE / REJECT TRANSFER
if (isset($_GET['action']) && in_array($_GET['action'], ['approve', 'reject']) && $is_admin) {
    $transfer_id = (int)($_GET['id'] ?? 0);
    $action = $_GET['action'];

    $t_stmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ? AND pharmacy_id = ? AND status = 'pending'");
    $t_stmt->bind_param("ii", $transfer_id, $pharmacy_id);
    $t_stmt->execute();
    $transfer = $t_stmt->get_result()->fetch_assoc();
    $t_stmt->close();

    if ($transfer) {
        if ($action === 'approve') {
            $conn->begin_transaction();
            try {
                $items_res = mysqli_query($conn, "SELECT from_product_id, quantity FROM stock_transfer_items WHERE transfer_id = '$transfer_id'");
                while ($item = mysqli_fetch_assoc($items_res)) {
                    $p_id = $item['from_product_id'];
                    $qty  = $item['quantity'];

                    $deduct = $conn->prepare(
                        "UPDATE store_items
                         SET quantity = quantity - ?
                         WHERE id = ? AND pharmacy_id = ? AND branch_id = ? AND quantity >= ?"
                    );
                    $deduct->bind_param("iiiii", $qty, $p_id, $pharmacy_id, $transfer['from_branch_id'], $qty);
                    $deduct->execute();

                    if ($deduct->affected_rows === 0) {
                        throw new Exception("Insufficient stock available during final approval execution.");
                    }
                    $deduct->close();
                }

                $upd = $conn->prepare("UPDATE stock_transfers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $upd->bind_param("ii", $user_id, $transfer_id);
                $upd->execute();
                $upd->close();

                $conn->commit();
                $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Approved!</strong> Stock has been deducted from source branch and is now in transit.</div>';
            } catch (Exception $e) {
                $conn->rollback();
                $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } elseif ($action === 'reject') {
            $upd = $conn->prepare("UPDATE stock_transfers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $upd->bind_param("ii", $user_id, $transfer_id);
            $upd->execute();
            $upd->close();
            $message = '<div class="alert alert-warning border-0 shadow-sm">Transfer request was rejected.</div>';
        }
    }
}

// 3. RECONCILE / RECEIVE TRANSFER
if (isset($_GET['action']) && $_GET['action'] === 'receive') {
    $transfer_id = (int)($_GET['id'] ?? 0);

    $t_stmt = $conn->prepare("SELECT * FROM stock_transfers WHERE id = ? AND pharmacy_id = ? AND to_branch_id = ? AND status = 'approved'");
    $t_stmt->bind_param("iii", $transfer_id, $pharmacy_id, $branch_id);
    $t_stmt->execute();
    $transfer = $t_stmt->get_result()->fetch_assoc();
    $t_stmt->close();

    if ($transfer) {
        $conn->begin_transaction();
        try {
            $items_res = mysqli_query($conn, "SELECT sti.*, si.item_name, si.price, si.tax_rate, si.barcode, si.cost, si.category, si.is_service, si.description, si.strength, si.expiry_date, si.manufacturer FROM stock_transfer_items sti JOIN store_items si ON sti.from_product_id = si.id WHERE sti.transfer_id = '$transfer_id'");

            while ($item = mysqli_fetch_assoc($items_res)) {
                $item_name = $item['item_name'];
                $qty       = $item['quantity'];

                $chk_target = $conn->prepare("SELECT id FROM store_items WHERE pharmacy_id = ? AND branch_id = ? AND item_name = ? LIMIT 1");
                $chk_target->bind_param("iis", $pharmacy_id, $branch_id, $item_name);
                $chk_target->execute();
                $existing = $chk_target->get_result()->fetch_assoc();
                $chk_target->close();

                if ($existing) {
                    $target_product_id = $existing['id'];
                    $add = $conn->prepare("UPDATE store_items SET quantity = quantity + ? WHERE id = ?");
                    $add->bind_param("ii", $qty, $target_product_id);
                    $add->execute();
                    $add->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO store_items (pharmacy_id, branch_id, item_name, price, tax_rate, barcode, cost, category, is_service, description, quantity, strength, is_active, expiry_date, manufacturer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
                    $ins->bind_param(
                        "iisddsdsiisiss",
                        $pharmacy_id,
                        $branch_id,
                        $item_name,
                        $item['price'],
                        $item['tax_rate'],
                        $item['barcode'],
                        $item['cost'],
                        $item['category'],
                        $item['is_service'],
                        $item['description'],
                        $qty,
                        $item['strength'],
                        $item['expiry_date'],
                        $item['manufacturer']
                    );
                    $ins->execute();
                    $target_product_id = $ins->insert_id;
                    $ins->close();
                }

                $upd_item = $conn->prepare("UPDATE stock_transfer_items SET to_product_id = ? WHERE id = ?");
                $upd_item->bind_param("ii", $target_product_id, $item['id']);
                $upd_item->execute();
                $upd_item->close();
            }

            $upd_t = $conn->prepare("UPDATE stock_transfers SET status = 'received', received_by = ?, received_at = NOW() WHERE id = ?");
            $upd_t->bind_param("ii", $user_id, $transfer_id);
            $upd_t->execute();
            $upd_t->close();

            $conn->commit();
            $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Success!</strong> Stock successfully received and reconciled into current branch inventory.</div>';
        } catch (Exception $e) {
            $conn->rollback();
            $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// =========================================================================
// FETCH DATA FOR DISPLAY
// =========================================================================

// Destination Branches
$branches_res = mysqli_query($conn, "SELECT id, branch_name FROM branches WHERE pharmacy_id = '$pharmacy_id' AND is_active = 1 AND id != '$branch_id'");

// Inventory Items for Active Branch
$inventory_res = mysqli_query($conn, "SELECT id, item_name, quantity, strength FROM store_items WHERE pharmacy_id = '$pharmacy_id' AND branch_id = '$branch_id' AND is_active = 1 AND quantity > 0 ORDER BY item_name ASC");

// Build Query Filter
$where_clause = " WHERE st.pharmacy_id = '$pharmacy_id' AND (st.from_branch_id = '$branch_id' OR st.to_branch_id = '$branch_id')";
if (in_array($status_filter, ['pending', 'approved', 'received', 'rejected'])) {
    $where_clause .= " AND st.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}

$transfers_res = mysqli_query($conn, "
    SELECT st.*, 
           fb.branch_name AS from_branch, 
           tb.branch_name AS to_branch,
           u1.full_name AS requester,
           u2.full_name AS approver,
           u3.full_name AS receiver
    FROM stock_transfers st
    JOIN branches fb ON st.from_branch_id = fb.id
    JOIN branches tb ON st.to_branch_id = tb.id
    LEFT JOIN users u1 ON st.requested_by = u1.id
    LEFT JOIN users u2 ON st.approved_by = u2.id
    LEFT JOIN users u3 ON st.received_by = u3.id
    $where_clause
    ORDER BY st.id DESC
");

require_once "../includes/head.php";
?>

<!-- Select2 CSS for Live Search -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<style>
    .card-transfer {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: .72rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 6px 10px;
        border-radius: 20px;
        white-space: nowrap;
    }
    .status-pending { background: #fff7df; color: #8a6500; }
    .status-approved { background: #eaf3ff; color: #075aa5; }
    .status-received { background: #eaf8ef; color: #1d6b3d; }
    .status-rejected { background: #fdf0f0; color: #9f3030; }
    label {
        font-size: .78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
        text-transform: uppercase;
    }
    .select2-container { width: 100% !important; }
    .transfer-table thead th {
        font-size: .74rem;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #64748b;
        white-space: nowrap;
    }
    .transfer-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 800;
        color: #0f172a;
    }
    .route-arrow {
        color: #94a3b8;
        margin: 0 5px;
    }
    .record-count {
        font-size: .75rem;
        color: #64748b;
    }
    .empty-state {
        padding: 45px 20px;
        text-align: center;
        color: #64748b;
    }
    .empty-state i {
        font-size: 2rem;
        color: #cbd5e1;
        margin-bottom: 10px;
    }
    .modal-content {
        border: 0;
        border-radius: 14px;
        overflow: hidden;
    }
    .transfer-item-row td { vertical-align: middle; }
    .stock-hint {
        display: block;
        font-size: .7rem;
        color: #64748b;
        margin-top: 4px;
    }
    .duplicate-warning {
        color: #b45309;
        font-size: .75rem;
        display: none;
    }
    @media (max-width: 767px) {
        .transfer-table th:nth-child(3),
        .transfer-table td:nth-child(3) { display: none; }
        .page-wrapper .container-fluid { padding-left: 12px; padding-right: 12px; }
    }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="container-fluid pt-4">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i> Inter-Branch Stock Transfer</h3>
                    <p class="text-muted small mb-0">Initiate, approve, and reconcile stock movement across locations.</p>
                </div>
                <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newTransferModal">
                    <i class="fas fa-plus me-1"></i> New Transfer Request
                </button>
            </div>

            <?= $message ?>

            <div class="card card-transfer shadow-sm">
                <!-- Filter Bar and Live Search -->
                <div class="card-header bg-white py-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <div>
                                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-list me-2 text-secondary"></i> Transfer Records</h5>
                                <span class="record-count" id="recordCount">Showing all records</span>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" id="recordSearch" class="form-control bg-light border-start-0" placeholder="Live search code, route, or requester...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <form method="get" id="filterForm">
                                <select name="filter_status" class="form-select" onchange="document.getElementById('filterForm').submit()">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>In Transit (Approved)</option>
                                    <option value="received" <?= $status_filter === 'received' ? 'selected' : '' ?>>Received</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 transfer-table" id="transfersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Transfer Code</th>
                                    <th>Route</th>
                                    <th>Requested By</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($transfers_res) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($transfers_res)): ?>
                                        <tr>
                                            <td><strong class="text-dark"><?= htmlspecialchars($row['transfer_code']) ?></strong></td>
                                            <td>
                                                <small class="text-muted">From:</small> <strong><?= htmlspecialchars($row['from_branch']) ?></strong><br>
                                                <small class="text-muted">To:</small> <strong><?= htmlspecialchars($row['to_branch']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($row['requester'] ?? 'N/A') ?></td>
                                            <td>
                                                <span class="status-badge status-<?= htmlspecialchars($row['status']) ?>">
                                                    <?php
                                                        $status_icon = [
                                                            'pending' => 'fa-clock',
                                                            'approved' => 'fa-truck',
                                                            'received' => 'fa-check-circle',
                                                            'rejected' => 'fa-times-circle'
                                                        ][$row['status']] ?? 'fa-circle';
                                                    ?>
                                                    <i class="fas <?= $status_icon ?>"></i>
                                                    <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                                </span>
                                            </td>
                                            <td><small><?= date('d M Y, H:i', strtotime($row['created_at'])) ?></small></td>
                                            <td class="text-end">
                                                <?php if ($row['status'] === 'pending' && $is_admin): ?>
                                                    <a href="?action=approve&id=<?= $row['id'] ?>" class="btn btn-sm btn-success me-1" onclick="return confirm('Approve this transfer and deduct stock from source branch?')">
                                                        <i class="fas fa-check me-1"></i> Approve
                                                    </a>
                                                    <a href="?action=reject&id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this stock transfer request?')">
                                                        <i class="fas fa-times me-1"></i> Reject
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($row['status'] === 'approved' && $row['to_branch_id'] == $branch_id): ?>
                                                    <a href="?action=receive&id=<?= $row['id'] ?>" class="btn btn-sm btn-primary" onclick="return confirm('Confirm receipt of stock into branch inventory?')">
                                                        <i class="fas fa-boxes me-1"></i> Receive Stock
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr id="noResultsRow">
                                        <td colspan="6" class="text-center py-4 text-muted">No stock transfer records found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; ?>
</div>

<!-- Modal: Initiate Transfer -->
<div class="modal fade" id="newTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" id="transferForm">
            <input type="hidden" name="action" value="initiate_transfer">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-shipping-fast me-2 text-primary"></i> Initiate Stock Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label>Destination Branch <span class="text-danger">*</span></label>
                            <select class="form-select" name="to_branch_id" required>
                                <option value="">-- Select Target Branch --</option>
                                <?php while ($b = mysqli_fetch_assoc($branches_res)): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Transfer Notes / Reason</label>
                            <input type="text" class="form-control" name="notes" placeholder="e.g. Stock balancing request">
                        </div>
                    </div>

                    <h6 class="fw-bold mt-4 mb-2">Transfer Items</h6>
                    <table class="table table-bordered align-middle" id="transferItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Select Product</th>
                                <th width="150">Quantity</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select class="form-select product-select" name="product_ids[]" required>
                                        <option value="">-- Search & Select Product --</option>
                                        <?php 
                                        if (mysqli_num_rows($inventory_res) > 0) {
                                            mysqli_data_seek($inventory_res, 0);
                                            while ($item = mysqli_fetch_assoc($inventory_res)): 
                                            ?>
                                                <option value="<?= $item['id'] ?>">
                                                    <?= htmlspecialchars($item['item_name']) ?> <?= !empty($item['strength']) ? '('.htmlspecialchars($item['strength']).')' : '' ?> - [Stock: <?= $item['quantity'] ?>]
                                                </option>
                                            <?php 
                                            endwhile; 
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="quantities[]" class="form-control quantity-input" min="1" value="1" required>
                                    <span class="stock-hint">Select a product</span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-row"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" id="addRowBtn">
                        <i class="fas fa-plus me-1"></i> Add Another Item
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">Submit Transfer Request</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS for Live Search inside Modal -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function () {
    const modal = $('#newTransferModal');

    function initSelect2(element) {
        $(element).select2({
            theme: 'bootstrap-5',
            dropdownParent: modal,
            placeholder: '-- Search & Select Product --',
            allowClear: true,
            width: '100%'
        });
    }

    function refreshRowStock(row) {
        const select = row.find('.product-select');
        const qty = row.find('.quantity-input');
        const hint = row.find('.stock-hint');
        const option = select.find('option:selected');
        const stock = parseInt(option.data('stock'), 10) || 0;

        if (select.val()) {
            hint.text('Available stock: ' + stock);
            qty.attr('max', stock);

            if (parseInt(qty.val(), 10) > stock) {
                qty.val(stock > 0 ? stock : 1);
            }
        } else {
            hint.text('Select a product');
            qty.removeAttr('max');
        }
    }

    function selectedProductIds() {
        const ids = [];
        $('.product-select').each(function () {
            const value = $(this).val();
            if (value) ids.push(String(value));
        });
        return ids;
    }

    function updateDuplicateWarnings() {
        const counts = {};
        $('.product-select').each(function () {
            const value = $(this).val();
            if (value) counts[value] = (counts[value] || 0) + 1;
        });

        $('.product-select').each(function () {
            const row = $(this).closest('tr');
            const value = $(this).val();

            row.find('.duplicate-warning').remove();

            if (value && counts[value] > 1) {
                row.find('td:first').append(
                    '<span class="duplicate-warning d-block">This product is already selected.</span>'
                );
            }
        });
    }

    initSelect2('.product-select');

    $(document).on('change', '.product-select', function () {
        refreshRowStock($(this).closest('tr'));
        updateDuplicateWarnings();
    });

    $(document).on('input', '.quantity-input', function () {
        const max = parseInt($(this).attr('max'), 10);

        if (Number.isFinite(max) && max > 0 && parseInt(this.value, 10) > max) {
            this.value = max;
        }

        if (parseInt(this.value, 10) < 1 || !this.value) {
            this.value = 1;
        }
    });

    $('#addRowBtn').click(function () {
        const firstRow = $('#transferItemsTable tbody tr:first');

        firstRow.find('.product-select').select2('destroy');

        const row = firstRow.clone(false);
        row.find('select').val('');
        row.find('input.quantity-input').val('1').removeAttr('max');
        row.find('.stock-hint').text('Select a product');
        row.find('.duplicate-warning').remove();

        $('#transferItemsTable tbody').append(row);

        $('.product-select').each(function () {
            initSelect2(this);
        });

        updateDuplicateWarnings();
    });

    $(document).on('click', '.remove-row', function () {
        const rows = $('#transferItemsTable tbody tr');

        if (rows.length > 1) {
            $(this).closest('tr').remove();
        } else {
            const row = $(this).closest('tr');
            row.find('.product-select').val(null).trigger('change');
            row.find('.quantity-input').val(1);
        }

        updateDuplicateWarnings();
    });

    $('#transferForm').on('submit', function (event) {
        event.preventDefault();

        const destination = $('select[name="to_branch_id"]').val();
        const ids = selectedProductIds();
        let valid = true;
        let errorMessage = '';

        if (!destination) {
            valid = false;
            errorMessage = 'Please select a destination branch.';
        }

        if (valid && ids.length === 0) {
            valid = false;
            errorMessage = 'Please select at least one product.';
        }

        if (valid && new Set(ids).size !== ids.length) {
            valid = false;
            errorMessage = 'The same product cannot be added more than once.';
            updateDuplicateWarnings();
        }

        if (valid) {
            $('.transfer-item-row, #transferItemsTable tbody tr').each(function () {
                const row = $(this);
                const product = row.find('.product-select').val();
                const qty = parseInt(row.find('.quantity-input').val(), 10) || 0;
                const max = parseInt(row.find('.quantity-input').attr('max'), 10);

                if (product && qty < 1) {
                    valid = false;
                    errorMessage = 'Every selected item must have a quantity of at least 1.';
                    return false;
                }

                if (product && Number.isFinite(max) && qty > max) {
                    valid = false;
                    errorMessage = 'A transfer quantity exceeds the available stock.';
                    return false;
                }
            });
        }

        if (!valid) {
            showTransferNotice(errorMessage, 'danger');
            return;
        }

        const button = $(this).find('button[type="submit"]');
        button.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Submitting...');

        this.submit();
    });

    function showTransferNotice(message, type) {
        const existing = $('#transferClientNotice');
        if (existing.length) existing.remove();

        $('#transferForm .modal-body').prepend(
            '<div id="transferClientNotice" class="alert alert-' + type +
            ' py-2 mb-3"><i class="fas fa-info-circle me-1"></i>' +
            $('<div>').text(message).html() + '</div>'
        );
    }

    $('#recordSearch').on('input', function () {
        const value = $(this).val().trim().toLowerCase();
        let visibleRows = 0;
        const rows = $('#transfersTable tbody tr').not('#noResultsRow, #noSearchMatch');

        rows.each(function () {
            const match = !value || $(this).text().toLowerCase().indexOf(value) !== -1;
            $(this).toggle(match);
            if (match) visibleRows++;
        });

        $('#noSearchMatch').remove();

        if (rows.length > 0 && visibleRows === 0) {
            $('#transfersTable tbody').append(
                '<tr id="noSearchMatch"><td colspan="6" class="text-center py-4 text-muted">' +
                '<i class="fas fa-search me-1"></i>No matching transfer records found.</td></tr>'
            );
        }

        $('#recordCount').text(
            value
                ? 'Showing ' + visibleRows + ' matching record' + (visibleRows === 1 ? '' : 's')
                : 'Showing ' + rows.length + ' record' + (rows.length === 1 ? '' : 's')
        );
    });

    // Confirm destructive/state-changing actions and prevent double-clicks.
    $(document).on('click', 'a[href*="action=approve"], a[href*="action=reject"], a[href*="action=receive"]', function (event) {
        const link = $(this);

        if (link.data('confirmed')) return;

        let question = 'Continue with this stock transfer action?';

        if (link.attr('href').indexOf('action=approve') !== -1) {
            question = 'Approve this transfer? Stock will be deducted from the source branch.';
        } else if (link.attr('href').indexOf('action=reject') !== -1) {
            question = 'Reject this stock transfer request?';
        } else if (link.attr('href').indexOf('action=receive') !== -1) {
            question = 'Confirm that the stock has physically arrived at this branch?';
        }

        if (!window.confirm(question)) {
            event.preventDefault();
            return false;
        }

        link.data('confirmed', true);
        link.addClass('disabled').css('pointer-events', 'none');
    });

    // Initial stock hints.
    $('.product-select').each(function () {
        refreshRowStock($(this).closest('tr'));
    });

    $('#recordSearch').trigger('input');
});
</script>
</body>
</html>
