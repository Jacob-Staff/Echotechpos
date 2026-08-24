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
    header("Location: ../index.php?error=session_expired");
    exit();
}

// Fetch Pharmacy & Branch Name
$display_pharmacy_name = "Pharmacy";
$display_branch_name   = "Main Branch";

$pharm_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_stmt->bind_param("i", $pharmacy_id);
$pharm_stmt->execute();
$pharm_res = $pharm_stmt->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_stmt->close();

$branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_stmt->bind_param("ii", $branch_id, $pharmacy_id);
$branch_stmt->execute();
$branch_res = $branch_stmt->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_stmt->close();

$success_msg = '';
$error_msg   = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
    $expected_date = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
    $status        = $_POST['status'] ?? 'ordered';
    $items         = $_POST['items'] ?? [];

    $allowed_statuses = ['ordered', 'draft'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'ordered';
    }

    if ($supplier_id && !empty($items)) {
        $conn->begin_transaction();
        try {
            $po_date   = date('Y-m-d H:i:s');
            $user_id   = (int)($_SESSION['user_id'] ?? 0);
            $po_number = 'PO-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            // Calculate total cost
            $total_cost = 0.00;
            foreach ($items as $item) {
                $qty        = (int)($item['quantity'] ?? 0);
                $unit_price = (float)($item['unit_price'] ?? 0);
                if ($qty > 0) {
                    $total_cost += ($qty * $unit_price);
                }
            }

            // Insert Purchase Order Record (Does NOT alter store inventory)
            $po_stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, pharmacy_id, branch_id, supplier_id, po_date, expected_date, status, total_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $po_stmt->bind_param("siiisssdi", $po_number, $pharmacy_id, $branch_id, $supplier_id, $po_date, $expected_date, $status, $total_cost, $user_id);
            $po_stmt->execute();
            $po_id = $po_stmt->insert_id;
            $po_stmt->close();

            // Insert Items
            $item_stmt = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price, pharmacy_id, branch_id) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $qty        = (int)($item['quantity'] ?? 0);
                $unit_price = (float)($item['unit_price'] ?? 0);

                if ($product_id <= 0 || $qty <= 0) {
                    continue;
                }

                if ($unit_price < 0) {
                    throw new Exception("Unit price cannot be negative.");
                }

                $verify_stmt = $conn->prepare(
                    "SELECT id
                     FROM store_items
                     WHERE id = ?
                       AND pharmacy_id = ?
                       AND branch_id = ?
                     LIMIT 1"
                );

                if (!$verify_stmt) {
                    throw new Exception("Could not validate the selected product.");
                }

                $verify_stmt->bind_param("iii", $product_id, $pharmacy_id, $branch_id);
                $verify_stmt->execute();
                $verify_res = $verify_stmt->get_result();
                $product_exists = $verify_res && $verify_res->num_rows > 0;
                $verify_stmt->close();

                if (!$product_exists) {
                    throw new Exception("One of the selected products is invalid for this branch.");
                }

                $item_stmt->bind_param(
                    "iiidii",
                    $po_id,
                    $product_id,
                    $qty,
                    $unit_price,
                    $pharmacy_id,
                    $branch_id
                );

                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to add a purchase-order item.");
                }
            }
            $item_stmt->close();

            $conn->commit();
            
            // Redirect to PO List with success message
            header("Location: purchase_orders_list.php?msg=" . urlencode("Purchase Order {$po_number} created successfully as '{$status}'."));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed to create Purchase Order: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select a supplier and add at least one valid product line.";
    }
}

// Fetch Suppliers
$suppliers = [];
$supp_stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE pharmacy_id = ? ORDER BY name ASC");
$supp_stmt->bind_param("i", $pharmacy_id);
$supp_stmt->execute();
$supp_res = $supp_stmt->get_result();
while ($row = $supp_res->fetch_assoc()) {
    $suppliers[] = $row;
}
$supp_stmt->close();

// Fetch Store Products
$products = [];
$prod_stmt = $conn->prepare("SELECT id, item_name, strength, quantity, cost FROM store_items WHERE pharmacy_id = ? AND branch_id = ? ORDER BY item_name ASC");
$prod_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$prod_stmt->execute();
$prod_res = $prod_stmt->get_result();
while ($row = $prod_res->fetch_assoc()) {
    $products[] = $row;
}
$prod_stmt->close();

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
}

.form-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    padding: 1.25rem 2rem;
    border-radius: 16px 16px 0 0;
}

.form-body {
    padding: 2rem;
}

.btn-primary-custom {
    background: var(--primary-color);
    border: none;
    color: white;
    font-weight: 600;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
}

.btn-primary-custom:hover {
    background: var(--primary-hover);
    color: white;
}
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
                    <h3 class="fw-bold text-dark mb-1">New Purchase Order</h3>
                    <p class="text-muted mb-0 small">
                        <i class="fas fa-building text-primary me-1"></i>
                        <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong> &bull; <?= htmlspecialchars($display_branch_name) ?>
                    </p>
                </div>
                <div>
                    <a href="purchase_orders_list.php" class="btn btn-outline-secondary btn-sm fw-bold">
                        <i class="fas fa-arrow-left me-1"></i> Back to Orders List
                    </a>
                </div>
            </div>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                    <div><?= htmlspecialchars($error_msg) ?></div>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-12 col-xl-10">
                    <div class="form-card">
                        
                        <div class="form-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                    <i class="fas fa-file-invoice fa-sm"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">Create Supplier Order</h5>
                            </div>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary fw-bold px-3 py-2 rounded-pill border">
                                Date: <?= date('d M Y') ?>
                            </span>
                        </div>

                        <div class="form-body">
                            <form method="POST" action="purchase_orders.php" autocomplete="off">
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-5">
                                        <label for="supplier_id" class="form-label fw-bold text-dark">Supplier <span class="text-danger">*</span></label>
                                        <select name="supplier_id" id="supplier_id" class="form-select" required>
                                            <option value="">-- Select Supplier --</option>
                                            <?php foreach ($suppliers as $s): ?>
                                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="expected_date" class="form-label fw-bold text-dark">Expected Delivery Date</label>
                                        <input type="date" name="expected_date" id="expected_date" class="form-control">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="status" class="form-label fw-bold text-dark">Initial Status</label>
                                        <select name="status" id="status" class="form-select">
                                            <option value="ordered" selected>Ordered / Sent</option>
                                            <option value="draft">Draft</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="table-responsive mb-4">
                                    <table class="table table-hover align-middle border">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 35%;">Product</th>
                                                <th style="width: 15%;">Stock</th>
                                                <th style="width: 20%;">Est. Unit Price (ZMW)</th>
                                                <th style="width: 20%;">Qty to Order</th>
                                                <th style="width: 10%;" class="text-center">
                                                    <button type="button" class="btn btn-sm btn-success" id="add-row">
                                                        <i class="fas fa-plus"></i>
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
                                                            <option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>" data-cost="<?= $p['cost'] ?>">
                                                                <?= htmlspecialchars($p['item_name'] . ' (' . $p['strength'] . ')') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="current-stock text-muted fw-bold">0</td>
                                                <td>
                                                    <input type="number" step="0.01" name="items[0][unit_price]" class="form-control unit-price" placeholder="0.00">
                                                </td>
                                                <td>
                                                    <input type="number" name="items[0][quantity]" class="form-control" min="1" placeholder="Qty" required>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary remove-row">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary-custom">
                                        <i class="fas fa-paper-plane me-2"></i> Submit Purchase Order
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
    $(document).on('change', '.product-select', function() {
        let selected = $(this).find(':selected');
        let stock = selected.data('stock') || 0;
        let cost = selected.data('cost') || 0.00;
        
        let row = $(this).closest('tr');
        row.find('.current-stock').text(stock);
        row.find('.unit-price').val(cost);
    });

    const productOptions = <?= json_encode(
        array_map(
            static function ($p) {
                return [
                    'id' => (int)$p['id'],
                    'name' => (string)$p['item_name'],
                    'strength' => (string)($p['strength'] ?? ''),
                    'quantity' => (int)$p['quantity'],
                    'cost' => (float)$p['cost']
                ];
            },
            $products
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    let rowIndex = 1;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildProductOptions() {
        let html = '<option value="">-- Select Product --</option>';

        productOptions.forEach(function (product) {
            const label = product.name +
                (product.strength ? ' (' + product.strength + ')' : '');

            html += '<option value="' + product.id + '"' +
                ' data-stock="' + product.quantity + '"' +
                ' data-cost="' + product.cost.toFixed(2) + '">' +
                escapeHtml(label) +
                '</option>';
        });

        return html;
    }

    $('#add-row').click(function() {
        const optionsHtml = buildProductOptions();

        const newRow = `<tr>
            <td>
                <select name="items[${rowIndex}][product_id]" class="form-select product-select" required>
                    ${optionsHtml}
                </select>
            </td>
            <td class="current-stock text-muted fw-bold">0</td>
            <td>
                <input type="number" step="0.01" min="0"
                       name="items[${rowIndex}][unit_price]"
                       class="form-control unit-price"
                       placeholder="0.00">
            </td>
            <td>
                <input type="number" name="items[${rowIndex}][quantity]"
                       class="form-control" min="1" placeholder="Qty" required>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-secondary remove-row" title="Remove row">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>`;

        $('#products-body').append(newRow);
        rowIndex++;
    });

    $(document).on('click', '.remove-row', function() {
        if ($('#products-body tr').length > 1) {
            $(this).closest('tr').remove();
        }
    });

    // Prevent the same product from being added twice to one PO.
    $(document).on('change', '.product-select', function () {
        const current = this.value;

        if (!current) {
            return;
        }

        let duplicate = false;

        $('.product-select').not(this).each(function () {
            if (this.value === current) {
                duplicate = true;
                return false;
            }
        });

        if (duplicate) {
            alert('This product is already added to this purchase order.');
            this.value = '';
            $(this).closest('tr').find('.current-stock').text('0');
            $(this).closest('tr').find('.unit-price').val('');
        }
    });
});
</script>
</body>
</html>
