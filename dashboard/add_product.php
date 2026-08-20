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

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$pharmacy_display_name = "Echo Prime Pharmacy";
$branch_display_name   = "Primary Branch";

$p_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$p_stmt->bind_param("i", $pharmacy_id);
$p_stmt->execute();
$p_res = $p_stmt->get_result();
if ($p_row = $p_res->fetch_assoc()) { 
    $pharmacy_display_name = $p_row['name']; 
}
$p_stmt->close();

$b_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
$b_stmt->bind_param("i", $branch_id);
$b_stmt->execute();
$b_res = $b_stmt->get_result();
if ($b_row = $b_res->fetch_assoc()) { 
    $branch_display_name = $b_row['branch_name']; 
}
$b_stmt->close();

$message = '';
$item_name = $price = $cost = $strength = $barcode = $description = '';
$category = 'Medicine';
$quantity = 0;
$expiry_date_display = '';

$categories = ['Medicine', 'Cosmetics', 'Agrovet', 'Supplements'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $raw_name = trim($_POST['item_name'] ?? '');
    $strength = trim($_POST['strength'] ?? '');
    
    // Combine item name and strength for standard display
    $item_name = trim($raw_name . " " . $strength); 
    
    $price = floatval($_POST['price'] ?? 0); 
    $cost = floatval($_POST['cost'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $posted_category = trim($_POST['category'] ?? 'Medicine');
    $category = in_array($posted_category, $categories) ? $posted_category : 'Medicine';
    $quantity = intval($_POST['quantity'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    $expiry_date_display = trim($_POST['expiry_date'] ?? '');
    $expiry_date_sql = null;
    $date_validation_error = false;

    if (!empty($expiry_date_display)) {
        $date_obj = DateTime::createFromFormat('d/m/Y', $expiry_date_display);
        if ($date_obj && $date_obj->format('d/m/Y') === $expiry_date_display) {
            $expiry_date_sql = $date_obj->format('Y-m-d');
        } else {
            $message = '<div class="alert alert-warning border-0 shadow-sm"><strong>Format Error!</strong> Use DD/MM/YYYY.</div>';
            $date_validation_error = true;
        }
    }

    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_service = isset($_POST['is_service']) ? 1 : 0;

    if (empty($raw_name) || empty($strength) || empty($price)) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Required Fields!</strong> Name, Strength, and Price are mandatory.</div>';
    } elseif (!$date_validation_error) {
        
        $sql = "INSERT INTO store_items (
                    pharmacy_id, branch_id, item_name, price, cost, 
                    strength, barcode, category, expiry_date, 
                    is_active, is_service, quantity, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisddssssiiis", 
            $pharmacy_id, 
            $branch_id, 
            $item_name, 
            $price, 
            $cost, 
            $strength, 
            $barcode, 
            $category, 
            $expiry_date_sql, 
            $is_active, 
            $is_service, 
            $quantity, 
            $description
        );

        if ($stmt->execute()) {
            $message = '<div class="alert alert-success border-0 shadow-sm"><strong>Success!</strong> '.htmlspecialchars($item_name).' added.</div>';
            $item_name = $price = $cost = $strength = $description = $barcode = $expiry_date_display = '';
            $quantity = 0;
        } else {
            $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Error:</strong> ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

require_once "../includes/head.php";
?>

<style>
    .card-form { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; }
    .card-form .form-control, .card-form .form-select { 
        padding: 10px 14px; 
    }
    .nav-tabs .nav-link { color: #64748b; border: none; font-weight: 600; padding: 12px 20px; cursor: pointer; }
    .nav-tabs .nav-link.active { background-color: transparent; color: #0d6efd; border-bottom: 3px solid #0d6efd; }
    .btn-save { font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    label { font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 5px; text-transform: uppercase; }
    .required-star { color: #dc3545; }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="container-fluid pt-4">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card card-form shadow-sm">
                        <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1 text-dark fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i> Add New Product / Stock Item</h4>
                                <p class="text-muted small mb-0">Pharmacy: <strong><?= htmlspecialchars($pharmacy_display_name) ?></strong> | Branch: <strong><?= htmlspecialchars($branch_display_name) ?></strong></p>
                            </div>
                            <a href="inventory.php" class="btn btn-outline-secondary btn-sm fw-bold">
                                <i class="fas fa-arrow-left me-1"></i> Back to Inventory
                            </a>
                        </div>

                        <ul class="nav nav-tabs px-3 mt-2" id="productTabs">
                            <li class="nav-item"><a class="nav-link active" id="details-link" onclick="switchTab('details')">General Info</a></li>
                            <li class="nav-item"><a class="nav-link" id="price-link" onclick="switchTab('price')">Pricing & Cost</a></li>
                            <li class="nav-item"><a class="nav-link" id="notes-link" onclick="switchTab('notes')">Description</a></li>
                        </ul>

                        <form method="post" id="productForm">
                            <div class="card-body p-4">
                                <?= $message ?>

                                <div class="tab-pane-custom" id="details-tab">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label>Product Name <span class="required-star">*</span></label>
                                            <input type="text" class="form-control" name="item_name" value="<?= htmlspecialchars($item_name) ?>" placeholder="e.g. Paracetamol" required>
                                            
                                            <label class="mt-3">Unit / Strength <span class="required-star">*</span></label>
                                            <input type="text" class="form-control" name="strength" value="<?= htmlspecialchars($strength) ?>" placeholder="e.g. 500mg, 100ml, Box of 30" required>
                                            
                                            <label class="mt-3">Barcode</label>
                                            <input type="text" class="form-control" name="barcode" value="<?= htmlspecialchars($barcode) ?>" placeholder="Scan or enter barcode">
                                        </div>
                                        <div class="col-md-6">
                                            <label>Category</label>
                                            <select class="form-select" name="category">
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat ?>" <?= ($category == $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <label class="mt-3">Initial Stock Quantity</label>
                                            <input type="number" class="form-control" name="quantity" value="<?= $quantity ?>" min="0">

                                            <label class="mt-3">Expiry Date (DD/MM/YYYY)</label>
                                            <input type="text" class="form-control" name="expiry_date" placeholder="DD/MM/YYYY" value="<?= htmlspecialchars($expiry_date_display) ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane-custom" id="price-tab" style="display:none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label>Cost Price (ZMW)</label>
                                            <input type="number" step="0.01" class="form-control" name="cost" id="cost" value="<?= $cost ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label>Markup (%)</label>
                                            <input type="number" class="form-control" id="markup" placeholder="25">
                                        </div>
                                        <div class="col-md-4">
                                            <label>Selling Price (ZMW) <span class="required-star">*</span></label>
                                            <input type="number" step="0.01" class="form-control" name="price" id="selling_price" value="<?= $price ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane-custom" id="notes-tab" style="display:none;">
                                    <label>Notes / Product Description</label>
                                    <textarea class="form-control" name="description" rows="5" placeholder="Optional details..."><?= htmlspecialchars($description) ?></textarea>
                                </div>
                            </div>

                            <div class="card-footer bg-light p-4 d-flex justify-content-between align-items-center">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="isActiveSwitch" checked>
                                    <label class="form-check-label text-dark fw-bold mb-0 ms-1" for="isActiveSwitch">Active Product</label>
                                </div>
                                <button type="submit" class="btn btn-success btn-save px-5 py-2">
                                    <i class="fas fa-save me-1"></i> Save Product
                                </button>
                            </div>
                        </form>
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
    function switchTab(tabId){
        document.querySelectorAll('.tab-pane-custom').forEach(t => t.style.display = 'none');
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        document.getElementById(tabId + '-tab').style.display = 'block';
        document.getElementById(tabId + '-link').classList.add('active');
    }

    const costI = document.getElementById('cost');
    const markI = document.getElementById('markup');
    const sellI = document.getElementById('selling_price');

    [costI, markI].forEach(el => {
        el.addEventListener('input', () => {
            const c = parseFloat(costI.value) || 0;
            const m = parseFloat(markI.value) || 0;
            if(c > 0) sellI.value = (c + (c * (m/100))).toFixed(2);
        });
    });
</script>

</body>
</html>
