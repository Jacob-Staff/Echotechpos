<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$pharmacy_display_name = "Echo Prime Pharmacy";
$branch_display_name = "Primary Branch";

$p_res = mysqli_query($conn, "SELECT name FROM pharmacies WHERE id = '$pharmacy_id' LIMIT 1");
if($p_row = mysqli_fetch_assoc($p_res)) { $pharmacy_display_name = $p_row['name']; }

$b_res = mysqli_query($conn, "SELECT branch_name FROM branches WHERE id = '$branch_id' LIMIT 1");
if($b_row = mysqli_fetch_assoc($b_res)) { $branch_display_name = $b_row['branch_name']; }

$message = '';
$item_name = $price = $cost = $strength = $barcode = $description = '';
$category = 'Medicine';
$quantity = 0;
$expiry_date_display = '';

$categories = ['Medicine', 'Cosmetics', 'Agrovet', 'Supplements'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // We grab both name and strength
    $raw_name = trim($_POST['item_name']);
    $strength = trim($_POST['strength']);
    
    // FIX: Combine them so the Pharmacy Stock table shows "Item 250mg" instead of N/A
    $item_name = $raw_name . " " . $strength; 
    
    $price = floatval($_POST['price']); 
    $cost = floatval($_POST['cost'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $posted_category = trim($_POST['category'] ?? 'Medicine');
    $category = in_array($posted_category, $categories) ? $posted_category : 'Medicine';
    $quantity = intval($_POST['quantity'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    $expiry_date_display = trim($_POST['expiry_date'] ?? '');
    $expiry_date_sql = '0000-00-00';
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
        
        // Use $item_name (combined) and $strength (separate) to satisfy both table needs
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
?>

    <style>
        body { background-color: #0f0f0f; color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
       .page-wrapper { background-color: #0f0f0f; min-height: 100vh; }
        .card-form { background-color: #1a1a1a !important; border: 1px solid #333; border-radius: 15px; }
        /* Change this: .form-control, .form-select */
/* To this: */
.card-form .form-control, .card-form .form-select { 
    background-color: #262626; 
    border: 1px solid #444; 
    color: #fff; 
    padding: 12px; 
}

.card-form .form-control:focus { 
    background-color: #2d2d2d; 
    border-color: #00ffae; 
    color: #fff; 
    box-shadow: 0 0 10px rgba(0, 255, 174, 0.2); 
}
        .form-control:focus { background-color: #2d2d2d; border-color: #00ffae; color: #fff; box-shadow: 0 0 10px rgba(0, 255, 174, 0.2); }
        .nav-tabs .nav-link { color: #888; border: none; font-weight: 600; padding: 15px 25px; cursor: pointer; }
        .nav-tabs .nav-link.active { background-color: transparent; color: #00ffae; border-bottom: 3px solid #00ffae; }
        .btn-save { background-color: #00ffae; border: none; color: #000; font-weight: 800; text-transform: uppercase; }
        label { font-size: 0.85rem; color: #00ffae; margin-bottom: 5px; text-transform: uppercase; }
        .required-star { color: #ff4d4d; }
    </style>
</head>

            <div class="container-fluid pt-5">
                <div class="row justify-content-center">
                    <div class="col-lg-9">
                        <div class="card card-form shadow-lg">
                            <div class="p-4 border-bottom border-secondary">
                                <h3 class="mb-0 text-white"><i class="fas fa-plus-circle me-2" style="color:#00ffae;"></i> Add New Item</h3>
                                <p class="text-muted small mb-0">Branch: <strong><?php echo htmlspecialchars($branch_display_name); ?></strong></p>
                            </div>

                            <ul class="nav nav-tabs px-3" id="productTabs">
                                <li class="nav-item"><a class="nav-link active" id="details-link" onclick="switchTab('details')">General Info</a></li>
                                <li class="nav-item"><a class="nav-link" id="price-link" onclick="switchTab('price')">Pricing</a></li>
                                <li class="nav-item"><a class="nav-link" id="notes-link" onclick="switchTab('notes')">Description</a></li>
                            </ul>

                            <form method="post" id="productForm">
                                <div class="card-body p-4">
                                    <?php echo $message; ?>

                                    <div class="tab-pane-custom" id="details-tab">
                                        <div class="row g-4">
                                            <div class="col-md-6">
                                                <label>Product Name <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" name="item_name" value="<?php echo htmlspecialchars($item_name); ?>" required>
                                                
                                                <label class="mt-4">Unit / Strength <span class="required-star">*</span></label>
                                                <input type="text" class="form-control" name="strength" value="<?php echo htmlspecialchars($strength); ?>" placeholder="e.g. 100ml, Box of 30" required>
                                                
                                                <label class="mt-4">Barcode</label>
                                                <input type="text" class="form-control" name="barcode" value="<?php echo htmlspecialchars($barcode); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Category</label>
                                                <select class="form-select" name="category">
                                                    <?php foreach ($categories as $cat): ?>
                                                        <option value="<?php echo $cat; ?>" <?php echo ($category == $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <label class="mt-4">Current Stock</label>
                                                <input type="number" class="form-control" name="quantity" value="<?php echo $quantity; ?>">

                                                <label class="mt-4">Expiry Date (DD/MM/YYYY)</label>
                                                <input type="text" class="form-control" name="expiry_date" placeholder="DD/MM/YYYY" value="<?php echo htmlspecialchars($expiry_date_display); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane-custom" id="price-tab" style="display:none;">
                                        <div class="row g-4">
                                            <div class="col-md-4">
                                                <label>Cost Price (K)</label>
                                                <input type="number" step="0.01" class="form-control" name="cost" id="cost" value="<?php echo $cost; ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label>Markup (%)</label>
                                                <input type="number" class="form-control" id="markup" placeholder="25">
                                            </div>
                                            <div class="col-md-4">
                                                <label>Selling Price (K) <span class="required-star">*</span></label>
                                                <input type="number" step="0.01" class="form-control" name="price" id="selling_price" value="<?php echo $price; ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tab-pane-custom" id="notes-tab" style="display:none;">
                                        <label>Notes / Description</label>
                                        <textarea class="form-control" name="description" rows="5"><?php echo htmlspecialchars($description); ?></textarea>
                                    </div>
                                </div>

                                <div class="card-footer bg-transparent border-top border-secondary p-4 d-flex justify-content-between">
                                    <div class="d-flex gap-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_active" checked>
                                            <label class="form-check-label text-white">Active</label>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-save px-5">Save Product</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>