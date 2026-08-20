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
    header("Location: ../index?error=session_expired");
    exit();
}

// Filter Status
$status_filter = $_GET['status'] ?? 'all';

$where_clause = "po.pharmacy_id = ? AND po.branch_id = ?";
$params = [$pharmacy_id, $branch_id];
$types = "ii";

if (!empty($status_filter) && $status_filter !== 'all') {
    $where_clause .= " AND po.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql = "SELECT po.id, po.po_number, po.po_date, po.expected_date, po.status, po.total_cost,
               s.name AS supplier_name, u.full_name AS creator_name,
               (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = po.id) AS item_count
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u ON po.created_by = u.id
        WHERE {$where_clause}
        ORDER BY po.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

require_once "../includes/head.php";
?>

<style>
:root {
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
}

body { background-color: var(--bg-page) !important; font-family: 'Inter', system-ui, sans-serif; }
.po-list-wrapper { padding: 2rem 1.5rem; }
.card-table { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
.badge-draft { background-color: #f3f4f6; color: #4b5563; }
.badge-ordered { background-color: #e0f2fe; color: #0369a1; }
.badge-partial { background-color: #fef3c7; color: #b45309; }
.badge-received { background-color: #dcfce7; color: #15803d; }
.badge-cancelled { background-color: #fee2e2; color: #b91c1c; }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper po-list-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <!-- Title & Top Actions -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Purchase Orders Overview</h3>
                    <p class="text-muted mb-0 small">Manage supplier orders and monitor incoming stock deliveries.</p>
                </div>
                <div>
                    <a href="purchase_orders.php" class="btn btn-primary fw-bold">
                        <i class="fas fa-plus me-1"></i> Create Purchase Order
                    </a>
                </div>
            </div>

            <!-- Toast alert for creation -->
            <?php if (!empty($_GET['msg'])): ?>
                <div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="fas fa-check-circle fa-lg me-3"></i>
                    <div><?= htmlspecialchars($_GET['msg']) ?></div>
                </div>
            <?php endif; ?>

            <!-- Status Filters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-bold text-muted small me-2">Filter Status:</span>
                    <a href="purchase_orders_list.php?status=all" class="btn btn-sm <?= $status_filter === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>">All</a>
                    <a href="purchase_orders_list.php?status=ordered" class="btn btn-sm <?= $status_filter === 'ordered' ? 'btn-info text-white' : 'btn-outline-info' ?>">Ordered</a>
                    <a href="purchase_orders_list.php?status=partial" class="btn btn-sm <?= $status_filter === 'partial' ? 'btn-warning text-dark' : 'btn-outline-warning' ?>">Partial</a>
                    <a href="purchase_orders_list.php?status=received" class="btn btn-sm <?= $status_filter === 'received' ? 'btn-success' : 'btn-outline-success' ?>">Received</a>
                    <a href="purchase_orders_list.php?status=draft" class="btn btn-sm <?= $status_filter === 'draft' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Draft</a>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="card-table overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>PO Ref #</th>
                                <th>Supplier</th>
                                <th>Order Date</th>
                                <th>Expected Date</th>
                                <th>Items</th>
                                <th>Total Cost</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php 
                                        $badge_class = 'badge-draft';
                                        switch($row['status']) {
                                            case 'ordered': $badge_class = 'badge-ordered'; break;
                                            case 'partial': $badge_class = 'badge-partial'; break;
                                            case 'received': $badge_class = 'badge-received'; break;
                                            case 'cancelled': $badge_class = 'badge-cancelled'; break;
                                        }
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-primary">
                                            <?= htmlspecialchars($row['po_number'] ?: ('PO-#' . $row['id'])) ?>
                                        </td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($row['supplier_name'] ?: 'Unknown') ?></td>
                                        <td class="small text-muted"><?= date('d M Y, H:i', strtotime($row['po_date'])) ?></td>
                                        <td class="small text-muted"><?= $row['expected_date'] ? date('d M Y', strtotime($row['expected_date'])) : '-' ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= $row['item_count'] ?> items</span></td>
                                        <td class="fw-bold">ZMW <?= number_format($row['total_cost'], 2) ?></td>
                                        <td>
                                            <span class="badge px-3 py-1 rounded-pill <?= $badge_class ?> fw-bold text-uppercase" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars($row['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($row['status'] === 'ordered' || $row['status'] === 'partial'): ?>
                                                <a href="receive_po.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-success fw-bold me-1">
                                                    <i class="fas fa-boxes me-1"></i> Receive Stock
                                                </a>
                                            <?php endif; ?>
                                            <a href="view_po.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-file-invoice fa-2x mb-3 d-block text-secondary"></i>
                                        No purchase orders found matching this criteria.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
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