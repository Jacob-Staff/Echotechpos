<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

// Fetch items for product search/selection
$items_sql = "SELECT id, item_name, price, quantity FROM store_items WHERE pharmacy_id = $pharmacy_id AND branch_id = $branch_id AND is_active = 1 ORDER BY item_name ASC";
$items_res = mysqli_query($conn, $items_sql);

// Fetch Active Lay-bys
$laybys_sql = "SELECT * FROM laybys WHERE pharmacy_id = $pharmacy_id AND branch_id = $branch_id AND balance_due > 0 ORDER BY id DESC";
$laybys_res = mysqli_query($conn, $laybys_sql);

// Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $pharmacy_id, $branch_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Nova Lsk';

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

require_once "../includes/head.php";
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
.layby-page-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1.25rem;
    color: #212529;
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    margin-bottom: 1.5rem;
}

.card-custom-header {
    background-color: #f8fafc;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
}

.table-custom {
    color: #334155;
    margin-bottom: 0;
}

.table-custom thead th {
    background-color: #f1f5f9;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
}

.table-custom tbody td {
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    padding: 12px;
    font-size: 0.9rem;
}

.product-search-list {
    max-height: 200px;
    overflow-y: auto;
    position: absolute;
    width: 100%;
    z-index: 1050;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    display: none;
}

.product-search-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
}

.product-search-item:hover {
    background-color: #f8fafc;
}

.qty-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper layby-page-wrapper">
        <div class="container-fluid p-0">

            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Lay-by Sales & Agreements</h3>
                    <span class="text-secondary small">
                        <i class="fas fa-store me-1"></i> Branch: <b class="text-dark"><?php echo e($display_bran); ?></b> | <b class="text-dark"><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
            </div>

            <div class="row g-4">
                <!-- Cart & Search Section -->
                <div class="col-12 col-lg-8">
                    <div class="card card-custom">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-search me-2 text-primary"></i>Search Products</h5>
                        </div>
                        <div class="card-body p-3 position-relative">
                            <input type="text" id="search_product" class="form-control form-control-lg" placeholder="Type product name or barcode..." autocomplete="off">
                            <div id="product_results" class="product-search-list"></div>
                        </div>
                    </div>

                    <div class="card card-custom">
                        <div class="card-custom-header d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-shopping-cart me-2 text-primary"></i>Lay-by Cart</h5>
                            <button class="btn btn-outline-danger btn-sm fw-bold" onclick="clearCart()"><i class="fas fa-trash me-1"></i> Clear Cart</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-custom align-middle">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Price</th>
                                            <th class="text-center" style="width: 140px;">Qty</th>
                                            <th class="text-end">Subtotal</th>
                                            <th class="text-center" style="width: 50px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cart_body">
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">No products added yet. Use the search box above.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer bg-white text-end py-3 pe-4">
                            <span class="fs-6 fw-bold text-muted me-2">CART GRAND TOTAL:</span>
                            <span class="fs-4 fw-bold text-success" id="cart_total">K0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Agreement Details Form -->
                <div class="col-12 col-lg-4">
                    <div class="card card-custom">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-file-contract me-2 text-primary"></i>Agreement Details</h5>
                        </div>
                        <div class="card-body p-3">
                            <form id="layby_form">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Customer Name *</label>
                                    <input type="text" name="customer_name" class="form-control" required placeholder="Enter full name">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Phone Number *</label>
                                    <input type="text" name="customer_phone" class="form-control" required placeholder="097...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Initial Deposit (K) *</label>
                                    <input type="number" id="deposit_input" name="deposit" class="form-control" step="0.01" min="0" required placeholder="0.00">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Final Settlement Due Date *</label>
                                    <input type="date" name="due_date" class="form-control" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                </div>

                                <div class="p-3 bg-light rounded text-center mb-3 border">
                                    <span class="text-muted small fw-bold d-block text-uppercase">Remaining Balance Due</span>
                                    <span class="fs-3 fw-bold text-danger" id="balance_due_display">K0.00</span>
                                </div>

                                <button type="submit" id="submit_layby_btn" class="btn btn-success fw-bold w-100 py-2 fs-6">
                                    <i class="fas fa-check-circle me-1"></i> Create Agreement
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Active Lay-bys Table -->
            <div class="card card-custom mt-4">
                <div class="card-custom-header d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2 text-primary"></i>Active Lay-by Agreements</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom align-middle text-center">
                            <thead>
                                <tr>
                                    <th class="text-start ps-3">Customer</th>
                                    <th>Total Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Due Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($laybys_res) > 0): ?>
                                    <?php while ($lb = mysqli_fetch_assoc($laybys_res)): ?>
                                        <tr>
                                            <td class="text-start ps-3 fw-bold text-dark">
                                                <?php echo e($lb['customer_name']); ?><br>
                                                <small class="text-muted"><?php echo e($lb['customer_phone']); ?></small>
                                            </td>
                                            <td>K<?php echo number_format($lb['total_amount'], 2); ?></td>
                                            <td class="text-success fw-bold">K<?php echo number_format($lb['deposit'], 2); ?></td>
                                            <td class="text-danger fw-bold">K<?php echo number_format($lb['balance_due'], 2); ?></td>
                                            <td><?php echo date('d M Y', strtotime($lb['due_date'])); ?></td>
                                            <td>
                                                <a href="view_layby.php?id=<?php echo $lb['id']; ?>" class="btn btn-outline-primary btn-sm px-3 fw-bold">
                                                    <i class="fas fa-eye me-1"></i> View / Pay
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No active lay-by agreements found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let cart = [];
const products = [
    <?php 
    mysqli_data_seek($items_res, 0);
    while($row = mysqli_fetch_assoc($items_res)) {
        echo "{ id: {$row['id']}, name: " . json_encode($row['item_name']) . ", price: {$row['price']}, qty: {$row['quantity']} },";
    }
    ?>
];

$('#search_product').on('input', function() {
    const val = $(this).val().toLowerCase().trim();
    const resultsContainer = $('#product_results');
    resultsContainer.empty().hide();

    if (val.length === 0) return;

    const matched = products.filter(p => p.name.toLowerCase().includes(val));
    if (matched.length > 0) {
        matched.forEach(p => {
            resultsContainer.append(`
                <div class="product-search-item" onclick="addToCart(${p.id}, '${p.name.replace(/'/g, "\\'")}', ${p.price})">
                    <strong>${p.name}</strong> - <span class="text-success fw-bold">K${parseFloat(p.price).toFixed(2)}</span>
                </div>
            `);
        });
        resultsContainer.show();
    }
});

$(document).on('click', function(e) {
    if (!$(e.target).closest('#search_product, #product_results').length) {
        $('#product_results').hide();
    }
});

function addToCart(id, name, price) {
    $('#search_product').val('');
    $('#product_results').hide();

    const existing = cart.find(item => item.id === id);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({ id, name, price: parseFloat(price), qty: 1 });
    }
    renderCart();
}

function updateQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (item) {
        item.qty += delta;
        if (item.qty <= 0) {
            cart = cart.filter(i => i.id !== id);
        }
    }
    renderCart();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    
    Swal.fire({
        title: 'Clear Cart?',
        text: 'Are you sure you want to remove all items from the cart?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, clear it!'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            renderCart();
        }
    });
}

function renderCart() {
    const tbody = $('#cart_body');
    tbody.empty();

    if (cart.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center py-4 text-muted">No products added yet. Use the search box above.</td></tr>');
        $('#cart_total').text('K0.00');
        calculateBalance();
        return;
    }

    let grandTotal = 0;

    cart.forEach(item => {
        const subtotal = item.price * item.qty;
        grandTotal += subtotal;

        tbody.append(`
            <tr>
                <td class="fw-bold text-dark">${item.name}</td>
                <td class="text-center">K${item.price.toFixed(2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm qty-btn me-1" onclick="updateQty(${item.id}, -1)">-</button>
                    <span class="fw-bold mx-1">${item.qty}</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm qty-btn ms-1" onclick="updateQty(${item.id}, 1)">+</button>
                </td>
                <td class="text-end fw-bold text-success">K${subtotal.toFixed(2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-link text-danger p-0" onclick="removeFromCart(${item.id})"><i class="fas fa-times"></i></button>
                </td>
            </tr>
        `);
    });

    $('#cart_total').text('K' + grandTotal.toFixed(2));
    calculateBalance();
}

function calculateBalance() {
    const total = cart.reduce((acc, item) => acc + (item.price * item.qty), 0);
    const deposit = parseFloat($('#deposit_input').val()) || 0;
    const balance = total - deposit;
    
    $('#balance_due_display').text('K' + (balance > 0 ? balance.toFixed(2) : '0.00'));
}

$('#deposit_input').on('input', calculateBalance);

$('#layby_form').on('submit', function(e) {
    e.preventDefault();

    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Cart is Empty',
            text: 'Please add at least one product to the cart before submitting.',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }

    const total = cart.reduce((acc, item) => acc + (item.price * item.qty), 0);
    const deposit = parseFloat($('#deposit_input').val()) || 0;

    if (deposit > total) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Deposit',
            text: 'Initial deposit cannot be greater than the grand total.',
            confirmButtonColor: '#dc3545'
        });
        return;
    }

    const btn = $('#submit_layby_btn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Creating...');

    const payload = $(this).serialize() + '&cart=' + JSON.stringify(cart) + '&total_amount=' + total;

    $.ajax({
        url: 'actions/create_layby.php', // Target the action handler file
        type: 'POST',
        data: payload,
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: res.message || 'Agreement Created Successfully!',
                    confirmButtonColor: '#198754',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Creation Failed',
                    text: res.message || 'Could not save agreement.',
                    confirmButtonColor: '#dc3545'
                });
                btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Create Agreement');
            }
        },
        error: function(xhr, status, error) {
            let msg = 'Could not process request.';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.message) msg = res.message;
            } catch(e) {
                msg = 'Server Error (' + xhr.status + ')';
            }

            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: msg,
                confirmButtonColor: '#dc3545'
            });
            btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Create Agreement');
        }
    });
});
</script>
</body>
</html>
