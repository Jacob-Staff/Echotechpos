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

// Multi-tenant Security Check
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Fetch Pharmacy & Branch Name for Header
$display_pharmacy_name = "Pharmacy";
$display_branch_name   = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_query->close();

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_query->bind_param("ii", $branch_id, $pharmacy_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_query->close();

$success_msg = '';
$error_msg   = '';

// Handle Purchase Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $items       = $_POST['items'] ?? [];

    if ($supplier_id && !empty($items)) {
        $conn->begin_transaction();
        try {
            $po_date = date('Y-m-d H:i:s');
            $user_id = (int)($_SESSION['user_id'] ?? 0);

            // Insert Purchase Order
            $po_stmt = $conn->prepare("INSERT INTO purchase_orders (pharmacy_id, branch_id, supplier_id, po_date, created_by) VALUES (?, ?, ?, ?, ?)");
            $po_stmt->bind_param("iiisi", $pharmacy_id, $branch_id, $supplier_id, $po_date, $user_id);
            $po_stmt->execute();
            $po_id = $po_stmt->insert_id;
            $po_stmt->close();

            // Insert Purchase Items & Update Inventory Stock
            $stmt_item  = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, pharmacy_id, branch_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_stock = $conn->prepare("UPDATE store_items SET quantity = quantity + ? WHERE id = ? AND branch_id = ? AND pharmacy_id = ?");

            foreach ($items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $qty        = (int)($item['quantity'] ?? 0);

                if ($product_id > 0 && $qty > 0) {
                    // Record purchase item
                    $stmt_item->bind_param("iiiii", $po_id, $product_id, $qty, $pharmacy_id, $branch_id);
                    $stmt_item->execute();

                    // Increment inventory stock
                    $stmt_stock->bind_param("iiii", $qty, $product_id, $branch_id, $pharmacy_id);
                    $stmt_stock->execute();
                }
            }

            $stmt_item->close();
            $stmt_stock->close();

            $conn->commit();
            $success_msg = "Purchase order registered and inventory stock updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed to process purchase order: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select a valid supplier and add at least one item with quantity.";
    }
}

// Fetch Suppliers (Filtered by Pharmacy)
$suppliers = [];
$supplier_stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE pharmacy_id = ? ORDER BY name ASC");
$supplier_stmt->bind_param("i", $pharmacy_id);
$supplier_stmt->execute();
$supp_res = $supplier_stmt->get_result();
while ($row = $supp_res->fetch_assoc()) {
    $suppliers[] = $row;
}
$supplier_stmt->close();

// Fetch Products (Filtered by Pharmacy & Branch)
$products = [];
$product_stmt = $conn->prepare("SELECT id, item_name, strength, quantity FROM store_items WHERE pharmacy_id = ? AND branch_id = ? ORDER BY item_name ASC");
$product_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$product_stmt->execute();
$prod_res = $product_stmt->get_result();
while ($row = $prod_res->fetch_assoc()) {
    $products[] = $row;
}
$product_stmt->close();

// Load Head Includes
require_once "../includes/head.php";
?>

<style>
:root {
    --primary-color: #0284c7;
    --primary-hover: #0369a1;
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
}

body {
    background-color: var(--bg-page) !important;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.po-wrapper {
    padding: 2rem 1.5rem;
    min-height: calc(100vh - 70px);
}

.form-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.03);
    overflow: hidden;
}

.form-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    padding: 1.25rem 2rem;
}

.form-body {
    padding: 2rem;
}

.btn-primary-custom {
    background: var(--primary-color);
    border: none;
    color: white;
    font-weight: 600;
    padding: 0.85rem 1.75rem;
    border-radius: 8px;
    transition: all 0.2s;
}

.btn-primary-custom:hover {
    background: var(--primary-hover);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper po-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <!-- Top Action / Title Bar -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Purchase Orders</h3>
                    <p class="text-muted mb-0 small">
                        <i class="fas fa-building text-primary me-1"></i>
                        <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong> &bull; <?= htmlspecialchars($display_branch_name) ?>
                    </p>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-check-circle fa-lg me-3"></i>
                    <div><?= htmlspecialchars($success_msg) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                    <div><?= htmlspecialchars($error_msg) ?></div>
                </div>
            <?php endif; ?>

            <!-- Main Form Card -->
            <div class="row justify-content-center">
                <div class="col-12 col-xl-10">
                    <div class="form-card">
                        
                        <div class="form-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                    <i class="fas fa-file-invoice fa-sm"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">Create New Purchase Order</h5>
                            </div>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary fw-bold px-3 py-2 rounded-pill border">
                                Date: <?= date('d M Y') ?>
                            </span>
                        </div>

                        <div class="form-body">
                            <form method="POST" action="purchase_orders.php" autocomplete="off">
                                <div class="mb-4">
                                    <label for="supplier_id" class="form-label fw-bold text-dark">Select Supplier <span class="text-danger">*</span></label>
                                    <select name="supplier_id" id="supplier_id" class="form-select" required>
                                        <option value="">-- Choose Supplier --</option>
                                        <?php foreach ($suppliers as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="table-responsive mb-4">
                                    <table class="table table-hover align-middle border">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 45%;">Product</th>
                                                <th style="width: 20%;">Current Stock</th>
                                                <th style="width: 25%;">Quantity to Order</th>
                                                <th style="width: 10%;" class="text-center">
                                                    <button type="button" class="btn btn-sm btn-success" id="add-row">
                                                        <i class="fas fa-plus me-1"></i> Add
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody id="products-body">
                                            <tr>
                                                <td>
                                                    <select name="items[0][product_id]" class="form-select product-select" required>
                                                        <option value="">-- Select Product --</option>
                                                        <?php foreach ($products as $p): ?>
                                                            <option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>">
                                                                <?= htmlspecialchars($p['item_name'] . ' (' . $p['strength'] . ')') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="current-stock fw-bold text-muted">0</td>
                                                <td>
                                                    <input type="number" name="items[0][quantity]" class="form-control" min="1" placeholder="Qty" required>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary-custom px-4">
                                        <i class="fas fa-save me-2"></i> Save Purchase Order
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>
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

<script>
$(document).ready(function(){
    // Display stock quantity upon selection
    $(document).on('change', '.product-select', function() {
        let stock = $(this).find(':selected').data('stock');
        $(this).closest('tr').find('.current-stock').text(stock !== undefined ? stock : 0);
    });

    // Add row dynamically
    let rowIndex = 1;
    $('#add-row').click(function() {
        let optionsHtml = `<option value="">-- Select Product --</option>`;
        <?php foreach ($products as $p): ?>
            optionsHtml += `<option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>"><?= htmlspecialchars(addslashes($p['item_name'] . ' (' . $p['strength'] . ')')) ?></option>`;
        <?php endforeach; ?>

        let newRow = `<tr>
            <td>
                <select name="items[${rowIndex}][product_id]" class="form-select product-select" required>
                    ${optionsHtml}
                </select>
            </td>
            <td class="current-stock fw-bold text-muted">0</td>
            <td>
                <input type="number" name="items[${rowIndex}][quantity]" class="form-control" min="1" placeholder="Qty" required>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
        
        $('#products-body').append(newRow);
        rowIndex++;
    });

    // Remove row dynamically
    $(document).on('click', '.remove-row', function() {
        if ($('#products-body tr').length > 1) {
            $(this).closest('tr').remove();
        } else {
            alert('At least one product item is required.');
        }
    });
});
</script>
</body>
</html>
