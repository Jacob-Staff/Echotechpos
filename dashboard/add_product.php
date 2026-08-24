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

    if (empty($raw_name) || empty($strength) || $price <= 0) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Required Fields!</strong> Product name, unit/strength, and a selling price greater than zero are required.</div>';
    } elseif ($cost < 0 || $quantity < 0) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><strong>Invalid Values!</strong> Cost and quantity cannot be negative.</div>';
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
    .card-form {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    .card-form .form-control,
    .card-form .form-select {
        padding: 11px 14px;
        border-color: #dbe2ea;
        border-radius: 8px;
    }
    .card-form .form-control:focus,
    .card-form .form-select:focus {
        border-color: #86b7fe;
        box-shadow: 0 0 0 .2rem rgba(13,110,253,.10);
    }
    .nav-tabs {
        border-bottom: 1px solid #e5e7eb;
    }
    .nav-tabs .nav-link {
        color: #64748b;
        border: none;
        font-weight: 700;
        padding: 13px 20px;
        cursor: pointer;
        transition: .2s ease;
    }
    .nav-tabs .nav-link:hover { color: #0d6efd; }
    .nav-tabs .nav-link.active {
        background: transparent;
        color: #0d6efd;
        border-bottom: 3px solid #0d6efd;
    }
    .btn-save {
        font-weight: 700;
        letter-spacing: .2px;
        min-width: 165px;
    }
    label {
        font-size: .78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
        text-transform: uppercase;
    }
    .required-star { color: #dc3545; }
    .field-hint {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: .72rem;
    }
    .price-preview {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        margin-top: 14px;
    }
    .price-preview strong { color: #0f172a; }
    .tab-pane-custom { animation: tabIn .18s ease-out; }
    @keyframes tabIn {
        from { opacity: .35; transform: translateY(3px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .saving-btn { opacity: .8; pointer-events: none; }
    @media (max-width: 575.98px) {
        .card-form .p-4 { padding: 1rem !important; }
        .nav-tabs .nav-link { padding: 11px 12px; font-size: .82rem; }
        .btn-save { width: 100%; }
        .card-footer { flex-direction: column; align-items: stretch !important; gap: 15px; }
    }
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
                                <div class="mt-2 d-flex flex-wrap gap-2">
                                    <span class="badge bg-light text-secondary border">
                                        <i class="fas fa-box me-1"></i> New Stock Item
                                    </span>
                                    <span class="badge bg-light text-secondary border">
                                        <i class="fas fa-location-dot me-1"></i> Current Branch
                                    </span>
                                </div>
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
                                            <input type="text" class="form-control" name="item_name" id="item_name" value="<?= htmlspecialchars($item_name) ?>" placeholder="e.g. Paracetamol" maxlength="150" required>
                                            <span class="field-hint">Enter the main product name only.</span>
                                            
                                            <label class="mt-3">Unit / Strength <span class="required-star">*</span></label>
                                            <input type="text" class="form-control" name="strength" id="strength" value="<?= htmlspecialchars($strength) ?>" placeholder="e.g. 500mg, 100ml, Box of 30" maxlength="100" required>
                                            <span class="field-hint">This is combined with the product name for the stock display.</span>
                                            
                                            <label class="mt-3">Barcode</label>
                                            <input type="text" class="form-control" name="barcode" id="barcode" value="<?= htmlspecialchars($barcode) ?>" placeholder="Scan or enter barcode" maxlength="100" autocomplete="off">
                                            <span class="field-hint">You can scan directly into this field.</span>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Category</label>
                                            <select class="form-select" name="category">
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat ?>" <?= ($category == $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <label class="mt-3">Initial Stock Quantity</label>
                                            <input type="number" class="form-control" name="quantity" id="quantity" value="<?= $quantity ?>" min="0" step="1" inputmode="numeric">
                                            <span class="field-hint">Opening stock quantity for this branch.</span>

                                            <label class="mt-3">Expiry Date (DD/MM/YYYY)</label>
                                            <input type="text" class="form-control" name="expiry_date" id="expiry_date" placeholder="DD/MM/YYYY" value="<?= htmlspecialchars($expiry_date_display) ?>" maxlength="10" inputmode="numeric">
                                            <span class="field-hint">Leave blank for items that do not have an expiry date.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane-custom" id="price-tab" style="display:none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label>Cost Price (ZMW)</label>
                                            <input type="number" step="0.01" min="0" class="form-control" name="cost" id="cost" value="<?= $cost ?>" inputmode="decimal">
                                        </div>
                                        <div class="col-md-4">
                                            <label>Markup (%)</label>
                                            <input type="number" step="0.01" min="0" class="form-control" id="markup" placeholder="25" inputmode="decimal">
                                            <span class="field-hint">Optional. Automatically calculates selling price.</span>
                                        </div>
                                        <div class="col-md-4">
                                            <label>Selling Price (ZMW) <span class="required-star">*</span></label>
                                            <input type="number" step="0.01" min="0.01" class="form-control" name="price" id="selling_price" value="<?= $price ?>" inputmode="decimal" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="tab-pane-custom" id="notes-tab" style="display:none;">
                                    <label>Notes / Product Description</label>
                                    <textarea class="form-control" name="description" rows="5" placeholder="Optional details..."><?= htmlspecialchars($description) ?></textarea>
                                </div>
                            </div>

                            <div class="card-footer bg-light p-4 d-flex justify-content-between align-items-center">
                                <div class="d-flex flex-wrap gap-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="isActiveSwitch" checked>
                                        <label class="form-check-label text-dark fw-bold mb-0 ms-1" for="isActiveSwitch">Active Product</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_service" id="isServiceSwitch">
                                        <label class="form-check-label text-dark fw-bold mb-0 ms-1" for="isServiceSwitch">Service Item</label>
                                    </div>
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
(function () {
    'use strict';

    const form = document.getElementById('productForm');
    const costInput = document.getElementById('cost');
    const markupInput = document.getElementById('markup');
    const sellingInput = document.getElementById('selling_price');
    const expiryInput = document.getElementById('expiry_date');
    const profitPreview = document.getElementById('profitPreview');
    const marginPreview = document.getElementById('marginPreview');

    window.switchTab = function (tabId) {
        document.querySelectorAll('.tab-pane-custom').forEach(function (pane) {
            pane.style.display = 'none';
        });

        document.querySelectorAll('#productTabs .nav-link').forEach(function (link) {
            link.classList.remove('active');
        });

        const pane = document.getElementById(tabId + '-tab');
        const link = document.getElementById(tabId + '-link');

        if (pane) pane.style.display = 'block';
        if (link) link.classList.add('active');
    };

    function numberValue(input) {
        const value = parseFloat(input.value);
        return Number.isFinite(value) ? value : 0;
    }

    function updatePricePreview() {
        const cost = numberValue(costInput);
        const selling = numberValue(sellingInput);

        if (cost > 0 && selling > 0) {
            const profit = selling - cost;
            const margin = (profit / selling) * 100;

            profitPreview.textContent = 'ZMW ' + profit.toFixed(2);
            marginPreview.textContent = margin.toFixed(2) + '%';
        } else {
            profitPreview.textContent = 'ZMW 0.00';
            marginPreview.textContent = '0.00%';
        }
    }

    function calculateSellingPrice() {
        const cost = numberValue(costInput);
        const markup = numberValue(markupInput);

        if (cost > 0 && markup >= 0 && document.activeElement === markupInput) {
            sellingInput.value = (cost + (cost * markup / 100)).toFixed(2);
        }

        updatePricePreview();
    }

    costInput.addEventListener('input', calculateSellingPrice);
    markupInput.addEventListener('input', calculateSellingPrice);
    sellingInput.addEventListener('input', updatePricePreview);

    // Friendly DD/MM/YYYY input. Slashes are inserted automatically.
    expiryInput.addEventListener('input', function () {
        let value = this.value.replace(/[^0-9]/g, '').slice(0, 8);

        if (value.length > 4) {
            value = value.slice(0, 2) + '/' + value.slice(2, 4) + '/' + value.slice(4);
        } else if (value.length > 2) {
            value = value.slice(0, 2) + '/' + value.slice(2);
        }

        this.value = value;
    });

    function validateExpiry() {
        const value = expiryInput.value.trim();

        if (!value) {
            expiryInput.setCustomValidity('');
            return true;
        }

        const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);

        if (!match) {
            expiryInput.setCustomValidity('Use DD/MM/YYYY.');
            return false;
        }

        const day = Number(match[1]);
        const month = Number(match[2]);
        const year = Number(match[3]);
        const date = new Date(year, month - 1, day);

        const valid =
            date.getFullYear() === year &&
            date.getMonth() === month - 1 &&
            date.getDate() === day;

        expiryInput.setCustomValidity(valid ? '' : 'Enter a valid expiry date.');
        return valid;
    }

    expiryInput.addEventListener('input', validateExpiry);
    expiryInput.addEventListener('blur', validateExpiry);

    // Enter on a field should not accidentally submit while the user is working.
    form.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
            const tag = event.target.tagName.toLowerCase();
            if (tag !== 'button') {
                event.preventDefault();
            }
        }
    });

    form.addEventListener('submit', function (event) {
        validateExpiry();

        const price = numberValue(sellingInput);
        const quantity = numberValue(document.getElementById('quantity'));
        const cost = numberValue(costInput);

        if (!form.checkValidity() || price <= 0 || cost < 0 || quantity < 0) {
            event.preventDefault();

            // Find the first invalid field and switch to its tab.
            const invalid = form.querySelector(':invalid');

            if (invalid) {
                const pane = invalid.closest('.tab-pane-custom');

                if (pane) {
                    switchTab(pane.id.replace('-tab', ''));
                }

                invalid.focus();
                invalid.reportValidity();
            }

            return;
        }

        const submitButton = form.querySelector('.btn-save');

        if (submitButton) {
            submitButton.classList.add('saving-btn');
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<i class="fas fa-spinner fa-spin me-1"></i> Saving Product...';
        }
    });

    // Initial state.
    switchTab('details');
    updatePricePreview();

})();
</script>

</body>
</html>
