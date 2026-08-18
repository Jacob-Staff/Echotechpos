<?php 
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require "../includes/conn.php";
require "../includes/auth.php"; 

/** * ECHO PRIME LTD - SECURITY & SESSION CHECK */
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// 1. Get Product ID from URL
if(!isset($_GET['id'])){
    header("Location: pharmacy_stock.php");
    exit;
}
$product_id = intval($_GET['id']);

// --- UI Display Names ---
$pharmacy_display_name = "Echo Prime Pharmacy";
$p_res = mysqli_query($conn, "SELECT name FROM pharmacies WHERE id = '$pharmacy_id' LIMIT 1");
if($p_row = mysqli_fetch_assoc($p_res)) { $pharmacy_display_name = $p_row['name']; }

// 2. Handle the Update Logic (POST)
$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    $item_name = trim($_POST['item_name']);
    $price     = floatval($_POST['price']); 
    $cost      = floatval($_POST['cost'] ?? 0);
    $strength  = trim($_POST['strength']);
    $category  = trim($_POST['category']);
    $quantity  = intval($_POST['quantity']);
    $tax_rate  = floatval($_POST['tax_rate'] ?? 16.00);
    $expiry    = $_POST['expiry_date']; // Format: YYYY-MM-DD from HTML5 input

    $sql_update = "UPDATE store_items SET 
                    item_name = ?, price = ?, cost = ?, strength= ?, 
                    category = ?, quantity = ?, expiry_date = ?, tax_rate = ?
                   WHERE id = ? AND branch_id = ?";
    
    $stmt_up = $conn->prepare($sql_update);
    $stmt_up->bind_param("sddssisdii", 
        $item_name, $price, $cost, $strength, 
        $category, $quantity, $expiry, $tax_rate, 
        $product_id, $branch_id
    );

    if ($stmt_up->execute()) {
        $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Success!</strong> Product updated successfully.</div>';
    } else {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error:</strong> ' . $stmt_up->error . '</div>';
    }
    $stmt_up->close();
}

// 3. Fetch Current Data for Display
$sql = "SELECT * FROM store_items WHERE id = ? AND branch_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $product_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows == 0){
    die("<div class='alert alert-danger'>Product not found or unauthorized access.</div>");
}
$product = $result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Update Product | <?php echo htmlspecialchars($pharmacy_display_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="dist/css/style.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0f0f0f; color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .page-wrapper { background-color: #0f0f0f; min-height: 100vh; }
        .card-form { background-color: #1a1a1a !important; border: 1px solid #333; border-radius: 15px; }
        .form-control, .form-select { background-color: #262626; border: 1px solid #444; color: #fff; padding: 12px; }
        .form-control:focus { background-color: #2d2d2d; border-color: #00ffae; color: #fff; box-shadow: 0 0 10px rgba(0, 255, 174, 0.2); }
        .btn-save { background-color: #00ffae; border: none; color: #000; font-weight: 800; text-transform: uppercase; }
        label { font-size: 0.85rem; color: #00ffae; margin-bottom: 5px; text-transform: uppercase; }
        .tax-review { background-color: #002b1e; border: 1px dashed #00ffae; padding: 15px; border-radius: 10px; color: #00ffae; }
    </style>
</head>

<body>
    <div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5" data-sidebartype="full">
        <?php require "../includes/header.php"; ?>
        <?php require "../includes/aside.php"; ?>

        <div class="page-wrapper">
            <div class="container-fluid pt-5">
                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="card card-form shadow-lg">
                            <div class="p-4 border-bottom border-secondary d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0 text-white"><i class="fas fa-edit me-2" style="color:#00ffae;"></i> Edit Item #<?php echo $product['id']; ?></h3>
                                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($product['item_name']); ?></p>
                                </div>
                                <a href="pharmacy_stock.php" class="btn btn-outline-light btn-sm">Back to Stock</a>
                            </div>

                            <form method="post">
                                <div class="card-body p-4">
                                    <?php echo $message; ?>

                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <label>Product Name</label>
                                            <input type="text" class="form-control" name="item_name" value="<?php echo htmlspecialchars($product['item_name']); ?>" required>
                                            
                                            <label class="mt-4">Unit / Strength</label>
                                            <input type="text" class="form-control" name="strength" value="<?php echo htmlspecialchars($product['strength']); ?>" required>
                                            
                                            <label class="mt-4">Category</label>
                                            <select class="form-select" name="category">
                                                <?php 
                                                $cats = ['Medicine', 'Cosmetics', 'Agrovet', 'Supplements'];
                                                foreach($cats as $c) {
                                                    $sel = ($product['category'] == $c) ? 'selected' : '';
                                                    echo "<option value='$c' $sel>$c</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="row">
                                                <div class="col-6">
                                                    <label>Selling Price (K)</label>
                                                    <input type="number" step="0.01" class="form-control" name="price" id="u_price" value="<?php echo $product['price']; ?>" required>
                                                </div>
                                                <div class="col-6">
                                                    <label>Tax Rate (%)</label>
                                                    <input type="number" class="form-control" name="tax_rate" id="u_tax" value="<?php echo $product['tax_rate']; ?>">
                                                </div>
                                            </div>

                                            <div class="tax-review mt-3 mb-3">
                                                <div class="row text-center">
                                                    <div class="col-6 border-end border-secondary">
                                                        <small>TAX</small><br><strong id="tax_disp">K 0.00</strong>
                                                    </div>
                                                    <div class="col-6">
                                                        <small>TOTAL INCL.</small><br><strong id="total_disp">K 0.00</strong>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-6">
                                                    <label>Cost Price (K)</label>
                                                    <input type="number" step="0.01" class="form-control" name="cost" value="<?php echo $product['cost']; ?>">
                                                </div>
                                                <div class="col-6">
                                                    <label>Stock Quantity</label>
                                                    <input type="number" class="form-control" name="quantity" value="<?php echo $product['quantity']; ?>" required>
                                                </div>
                                            </div>

                                            <label class="mt-4">Expiry Date</label>
                                            <input type="date" class="form-control" name="expiry_date" value="<?php echo $product['expiry_date']; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="card-footer bg-transparent border-top border-secondary p-4 text-end">
                                    <button type="submit" name="update_product" class="btn btn-save px-5 py-2">Update Inventory</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="footer text-center mt-5 text-muted">© 2026 Echo Prime Ltd.</footer>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calc() {
            let p = parseFloat($('#u_price').val()) || 0;
            let t = parseFloat($('#u_tax').val()) || 0;
            let taxAmt = p * (t/100);
            $('#tax_disp').text('K ' + taxAmt.toFixed(2));
            $('#total_disp').text('K ' + (p + taxAmt).toFixed(2));
        }
        $('#u_price, #u_tax').on('input', calc);
        calc(); // Init on load
    </script>
</body>
</html>