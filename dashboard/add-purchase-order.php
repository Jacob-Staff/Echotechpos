<?php 
session_start();
require "../includes/conn.php";
require "../includes/auth.php"; 

// Set timezone
date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Fetch suppliers (Global or filtered by pharmacy if needed)
$supplier_sql = "SELECT id, name FROM suppliers ORDER BY name ASC";
$supplier_result = mysqli_query($conn, $supplier_sql);

// Fetch ONLY products belonging to this specific branch
$product_sql = "SELECT id, item_name, strength, quantity FROM store_items WHERE branch_id = $branch_id ORDER BY item_name ASC";
$product_result = mysqli_query($conn, $product_sql);
$products_array = [];
while($row = mysqli_fetch_assoc($product_result)) {
    $products_array[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $items = $_POST['items'] ?? [];

    if ($supplier_id && !empty($items)) {
        $conn->begin_transaction();
        try {
            $po_date = date('Y-m-d H:i:s');
            // Added branch_id/pharmacy_id to PO record
            $po_sql = "INSERT INTO purchase_orders (pharmacy_id, branch_id, supplier_id, order_date, created_by) VALUES (?, ?, ?, ?, ?)";
            $stmt_po = $conn->prepare($po_sql);
            $stmt_po->bind_param("iiisi", $pharmacy_id, $branch_id, $supplier_id, $po_date, $_SESSION['user_id']);
            $stmt_po->execute();
            $po_id = $conn->insert_id;

            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity) VALUES (?, ?, ?)");
            // Critical: Stock update is branch-specific
            $stmt_stock = $conn->prepare("UPDATE store_items SET quantity = quantity + ? WHERE id = ? AND branch_id = ?");

            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $qty = intval($item['quantity']);
                if ($qty <= 0) continue;

                $stmt_item->bind_param("iii", $po_id, $product_id, $qty);
                $stmt_item->execute();

                $stmt_stock->bind_param("iii", $qty, $product_id, $branch_id);
                $stmt_stock->execute();
            }

            $conn->commit();
            $success_msg = "Stock successfully updated and PO saved!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Purchase Order | Jayson POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0d0d0d; color: #fff; font-family: 'Poppins', sans-serif; }
        .card { background-color: #1a1a1a; border: 1px solid #333; border-radius: 12px; }
        .form-control, .form-select { background-color: #262626; border: 1px solid #444; color: #fff; }
        .table { color: #fff; border-color: #333; }
        .table-light { background-color: #333; color: #00ffae; border: none; }
        label { color: #00ffae; font-size: 0.8rem; text-transform: uppercase; font-weight: bold; }
        .btn-neon { background-color: #00ffae; color: #000; font-weight: 700; border: none; }
        .btn-neon:hover { background-color: #00e699; }
    </style>
</head>
<body>
<div class="container-fluid pt-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="text-white"><i class="fas fa-truck-loading me-2"></i> Create Purchase Order</h4>
                    <a href="pharmacy_stock.php" class="btn btn-outline-danger btn-sm">Cancel</a>
                </div>

                <?php if(!empty($success_msg)): ?>
                    <div class="alert alert-success bg-success text-white border-0"><?= $success_msg ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label>Select Supplier</label>
                            <select name="supplier_id" class="form-select mt-1" required>
                                <option value="">-- Choose Supplier --</option>
                                <?php mysqli_data_seek($supplier_result, 0); 
                                while($row = mysqli_fetch_assoc($supplier_result)): ?>
                                    <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <table class="table">
                        <thead class="table-light">
                            <tr>
                                <th width="50%">Product</th>
                                <th>On Hand</th>
                                <th>Adding Qty</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="products-body">
                            <tr>
                                <td>
                                    <select name="items[0][product_id]" class="form-select prod-select" required>
                                        <option value="">-- Select Product --</option>
                                        <?php foreach($products_array as $p): ?>
                                            <option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>">
                                                <?= htmlspecialchars($p['item_name'].' ('.$p['strength'].')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="current-stock-val">0</td>
                                <td><input type="number" name="items[0][quantity]" class="form-control" min="1" required></td>
                                <td><button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-trash"></i></button></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-dark border-secondary" id="add-row">
                            <i class="fas fa-plus me-1"></i> Add Item
                        </button>
                        <button type="submit" class="btn btn-neon px-5">
                            Save & Update Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
let rowIndex = 1;

// HTML for new rows (re-uses the PHP products array)
const productOptions = `<?php foreach($products_array as $p): ?>
    <option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>">
        <?= htmlspecialchars($p['item_name'].' ('.$p['strength'].')') ?>
    </option>
<?php endforeach; ?>`;

$('#add-row').click(function() {
    let newRow = `<tr>
        <td>
            <select name="items[${rowIndex}][product_id]" class="form-select prod-select" required>
                <option value="">-- Select Product --</option>
                ${productOptions}
            </select>
        </td>
        <td class="current-stock-val">0</td>
        <td><input type="number" name="items[${rowIndex}][quantity]" class="form-control" min="1" required></td>
        <td><button type="button" class="btn btn-sm btn-danger remove-row"><i class="fas fa-trash"></i></button></td>
    </tr>`;
    $('#products-body').append(newRow);
    rowIndex++;
});

// Update Current Stock display on selection
$(document).on('change', '.prod-select', function() {
    const stock = $(this).find(':selected').data('stock') || 0;
    $(this).closest('tr').find('.current-stock-val').text(stock);
});

$(document).on('click', '.remove-row', function() {
    $(this).closest('tr').remove();
});
</script>
</body>
</html>