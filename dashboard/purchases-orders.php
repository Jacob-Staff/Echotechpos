<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";


// Set timezone
date_default_timezone_set('Africa/Lusaka');

// ✅ Multi-Tenant Security Check
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    die("Session expired. Please log in again.");
}

// Fetch suppliers (Filtered by Pharmacy)
$supplier_sql = "SELECT id, name FROM suppliers WHERE pharmacy_id = $pharmacy_id ORDER BY name ASC";
$supplier_result = mysqli_query($conn, $supplier_sql);

// Fetch products (Filtered by Pharmacy & Branch)
$product_sql = "SELECT id, item_name, strength, quantity FROM store_items 
                WHERE pharmacy_id = $pharmacy_id AND branch_id = $branch_id 
                ORDER BY item_name ASC";
$product_result = mysqli_query($conn, $product_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $items = $_POST['items'] ?? [];

    if ($supplier_id && !empty($items)) {
        $conn->begin_transaction();
        try {
            $po_date = date('Y-m-d H:i:s');
            // ✅ Added pharmacy_id and branch_id to the insert
            $po_sql = "INSERT INTO purchase_orders (pharmacy_id, branch_id, supplier_id, po_date, created_by) VALUES (?, ?, ?, ?, ?)";
            $stmt_po = $conn->prepare($po_sql);
            $stmt_po->bind_param("iiisi", $pharmacy_id, $branch_id, $supplier_id, $po_date, $_SESSION['user_id']);
            $stmt_po->execute();
            $po_id = $conn->insert_id;
            $stmt_po->close();

            // Note: Update table name to 'purchase_items' or 'purchases_items' based on your check
            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity) VALUES (?, ?, ?)");
            
            // ✅ Rule 1: Update stock ONLY for this specific branch/pharmacy
            $stmt_stock = $conn->prepare("UPDATE store_items SET quantity = quantity + ? WHERE id = ? AND branch_id = ? AND pharmacy_id = ?");

            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $qty = intval($item['quantity']);
                if ($qty <= 0) continue;

                $stmt_item->bind_param("iii", $po_id, $product_id, $qty);
                $stmt_item->execute();

                $stmt_stock->bind_param("iiii", $qty, $product_id, $branch_id, $pharmacy_id);
                $stmt_stock->execute();
            }

            $stmt_item->close();
            $stmt_stock->close();
            $conn->commit();
            $success_msg = "Purchase order added successfully!";
            
            // Refresh product list to show updated stock
            $product_result = mysqli_query($conn, $product_sql);
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed to add purchase order: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select a supplier and add at least one product.";
    }
}
?>
<style>
body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
.page-breadcrumb { background-color: #fff; padding: 1rem; border-radius: 0.75rem; margin-bottom: 2rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
.card { border-radius: 0.75rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
</style>
    
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col">
                    <h4 class="page-title text-primary mb-0">Add Purchase Order</h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 p-0">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Purchase Orders</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <?php if(!empty($success_msg)): ?>
                <div class="alert alert-success"><?= $success_msg ?></div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger"><?= $error_msg ?></div>
            <?php endif; ?>

            <div class="card p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label for="supplier_id" class="form-label">Supplier</label>
                        <select name="supplier_id" id="supplier_id" class="form-select" required>
                            <option value="">-- Select Supplier --</option>
                            <?php
                            mysqli_data_seek($supplier_result, 0);
                            while($row = mysqli_fetch_assoc($supplier_result)):
                            ?>
                                <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Quantity to Order</th>
                                <th><button type="button" class="btn btn-sm btn-success" id="add-row">Add Row</button></th>
                            </tr>
                        </thead>
                        <tbody id="products-body">
                            <tr>
                                <td>
                                    <select name="items[0][product_id]" class="form-select product-select" required>
                                        <option value="">-- Select Product --</option>
                                        <?php
                                        mysqli_data_seek($product_result, 0);
                                        while($row = mysqli_fetch_assoc($product_result)):
                                        ?>
                                            <option value="<?= $row['id'] ?>" data-stock="<?= $row['quantity'] ?>">
                                                <?= htmlspecialchars($row['item_name'].' ('.$row['strength'].')') ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </td>
                                <td class="current-stock">0</td>
                                <td><input type="number" name="items[0][quantity]" class="form-control" min="1" required></td>
                                <td><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
                            </tr>
                        </tbody>
                    </table>

                    <button type="submit" class="btn btn-primary">Save Purchase Order</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Update stock display when product is selected
$(document).on('change', '.product-select', function() {
    let stock = $(this).find(':selected').data('stock') || 0;
    $(this).closest('tr').find('.current-stock').text(stock);
});

let rowIndex = 1;
$('#add-row').click(function() {
    let newRow = `<tr>
        <td>
            <select name="items[${rowIndex}][product_id]" class="form-select product-select" required>
                <option value="">-- Select Product --</option>
                <?php
                mysqli_data_seek($product_result, 0);
                while($row = mysqli_fetch_assoc($product_result)):
                ?>
                    <option value="<?= $row['id'] ?>" data-stock="<?= $row['quantity'] ?>">
                        <?= htmlspecialchars($row['item_name'].' ('.$row['strength'].')') ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </td>
        <td class="current-stock">0</td>
        <td><input type="number" name="items[${rowIndex}][quantity]" class="form-control" min="1" required></td>
        <td><button type="button" class="btn btn-sm btn-danger remove-row">Remove</button></td>
    </tr>`;
    $('#products-body').append(newRow);
    rowIndex++;
});
$(document).on('click', '.remove-row', function() { $(this).closest('tr').remove(); });
</script>
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>