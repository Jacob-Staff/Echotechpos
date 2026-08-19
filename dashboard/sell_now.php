<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

$pharmacy_id = intval($_SESSION['pharmacy_id']);
$branch_id   = intval($_SESSION['branch_id']);
$issued_by   = htmlspecialchars($_SESSION['sessionUsername'] ?? 'Staff');

// Fetch pharmacy info
$p_query = mysqli_prepare($conn, "SELECT name, address, phone FROM pharmacies WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($p_query, "i", $pharmacy_id);
mysqli_stmt_execute($p_query);
$p_res = mysqli_stmt_get_result($p_query);
$pharm = mysqli_fetch_assoc($p_res) ?: ['name' => 'Echo Prime Pharmacy', 'address' => 'Zambia', 'phone' => ''];

// Fetch branch info
$b_query = mysqli_prepare($conn, "SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($b_query, "i", $branch_id);
mysqli_stmt_execute($b_query);
$b_res = mysqli_stmt_get_result($b_query);
$branch = mysqli_fetch_assoc($b_res) ?: ['branch_name' => 'Main Branch'];

require_once "../includes/head.php";
?>

<style>
:root { 
    --neon-green: #00ffae; 
    --dark-bg: #0f0f0f; 
    --panel-bg: #1a1a1a; 
    --row-hover: #252525; 
}

/* Page Wrapper Adjustments */
.pos-wrapper {
    background-color: var(--dark-bg) !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
}

/* Container Layout */
.pos-container { 
    display: flex;
    flex-direction: column;
    gap: 1.25rem; 
}

@media (min-width: 992px) {
    .pos-container {
        display: grid;
        grid-template-columns: 1fr 380px;
        height: calc(100vh - 110px);
    }
}

/* Search Dropdown Setup */
.search-section { position: relative; }
#product_search { 
    background: #1a1a1a; 
    border: 1px solid #333; 
    border-radius: 12px; 
    padding-left: 48px;
    color: #fff;
}
#product_search:focus { 
    background: #222; 
    border-color: var(--neon-green); 
    box-shadow: 0 0 12px rgba(0, 255, 174, 0.2); 
}
.search-icon-fixed { position: absolute; left: 16px; top: 16px; z-index: 10; color: var(--neon-green); font-size: 1.2rem; }

.product-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1050;
    background: #1e1e1e;
    border: 1px solid #333;
    border-radius: 10px;
    max-height: 320px;
    overflow-y: auto;
    display: none;
    padding: 0;
    margin-top: 5px;
    list-style: none;
    box-shadow: 0 10px 25px rgba(0,0,0,0.75);
}

/* High contrast search results text styling */
.product-item {
    padding: 12px 16px;
    border-bottom: 1px solid #2d2d2d;
    background-color: #1a1a1a;
    color: #ffffff;
    cursor: pointer;
    transition: background 0.2s;
}
.product-item:hover {
    background-color: #262626;
}
.product-title {
    color: #00ffae;
    font-weight: 700;
    font-size: 1rem;
}
.product-meta {
    color: #cccccc !important; /* Bright high-contrast text */
    font-size: 0.85rem;
}
.barcode-value {
    color: #ffffff;
    font-weight: 600;
    background: #2a2a2a;
    padding: 1px 6px;
    border-radius: 4px;
    border: 1px solid #444;
}

/* Cart Table Styling */
.cart-table-container { 
    background-color: var(--panel-bg); 
    border-radius: 15px; 
    overflow-y: auto; 
    border: 1px solid #282828;
    min-height: 250px;
    max-height: 50vh;
    height: 100%;
}

@media (min-width: 992px) {
    .cart-table-container {
        max-height: 100%;
    }
}

.cart-table { width: 100%; border-collapse: collapse; }
.cart-table thead th { 
    position: sticky; top: 0; background: #222; 
    padding: 12px 14px; text-align: left; font-size: 11px; 
    color: #aaa; text-transform: uppercase; z-index: 5;
}
.cart-row { border-bottom: 1px solid #282828; transition: 0.2s; }
.cart-row:hover { background: var(--row-hover); }
.cart-row td { padding: 12px 14px; color: #eee; vertical-align: middle; }

/* Checkout Side Panel */
.right-panel { 
    background: #151515; 
    border-radius: 15px; 
    padding: 1.25rem; 
    border: 1px solid #282828; 
}
.total-section { 
    background: linear-gradient(145deg, #1e1e1e, #111); 
    border-radius: 12px; padding: 1.25rem; 
    border: 1px solid #333; margin-bottom: 1.25rem;
}

/* Payment Method Buttons */
.meth-btn { 
    height: 55px; background: #222; border: 1px solid #333; 
    color: #aaa; font-weight: bold; border-radius: 10px; 
    transition: 0.2s;
}
.meth-btn:hover { border-color: var(--neon-green); color: #fff; }
.meth-btn.active { background: var(--neon-green) !important; color: #000 !important; border-color: #fff; }

.btn-payment-main { 
    height: 65px; border-radius: 12px; border: none; 
    font-weight: 800; font-size: 1.05rem; text-transform: uppercase;
    background: #2a2a2a; color: #666; transition: 0.3s;
}
.btn-payment-main.active-ready { background: var(--neon-green); color: #000; cursor: pointer; }

#empty-cart-msg { text-align: center; margin-top: 60px; color: #666; padding: 20px; }
#empty-cart-msg i { font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.4; }
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper pos-wrapper">
        <div class="container-fluid p-0">
            
            <div class="pos-container">
                <!-- Left Section: Search & Cart -->
                <div class="d-flex flex-column gap-3">
                    <div class="search-section">
                        <i class="fas fa-barcode search-icon-fixed"></i>
                        <input type="text" class="form-control form-control-lg" id="product_search" placeholder="Scan barcode or search medicine... (Press F2)" autofocus autocomplete="off">
                        <ul id="product_results" class="product-search-results"></ul>
                    </div>

                    <div class="cart-table-container">
                        <div id="empty-cart-msg">
                            <i class="fas fa-shopping-basket"></i>
                            <h5>Cart is Empty</h5>
                            <p class="small text-muted mb-0">Scan or search products to begin transaction</p>
                        </div>
                        <table class="cart-table" id="pos_table" style="display:none;">
                            <thead>
                                <tr>
                                    <th width="35%">Product</th>
                                    <th width="20%">Price</th>
                                    <th width="20%" class="text-center">Qty</th>
                                    <th width="20%">Total</th>
                                    <th width="5%"></th>
                                </tr>
                            </thead>
                            <tbody id="cart_body"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Right Section: Totals & Checkout -->
                <div class="right-panel d-flex flex-column justify-content-between">
                    <div>
                        <div class="total-section">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Items Count</span>
                                <span id="txt_count" class="text-white fw-bold">0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">VAT (16%)</span>
                                <span id="txt_vat" class="text-white fw-bold">K0.00</span>
                            </div>
                            <hr style="border-color: #333;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="h6 mb-0 text-white">TOTAL DUE</span>
                                <span class="h3 mb-0 text-success fw-bold">K<span id="txt_total">0.00</span></span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small text-muted mb-2 fw-bold">SELECT PAYMENT METHOD</label>
                            <div class="row g-2">
                                <div class="col-4"><button type="button" class="btn w-100 meth-btn" onclick="setMethod('Cash', this)"><i class="fas fa-money-bill-wave d-block mb-1"></i>CASH</button></div>
                                <div class="col-4"><button type="button" class="btn w-100 meth-btn" onclick="setMethod('Card', this)"><i class="fas fa-credit-card d-block mb-1"></i>CARD</button></div>
                                <div class="col-4"><button type="button" class="btn w-100 meth-btn" onclick="setMethod('Mobile Money', this)"><i class="fas fa-mobile-alt d-block mb-1"></i>MOBILE</button></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn-payment-main w-100 shadow-lg" id="finalize_btn" onclick="processSale()" disabled>
                            <span id="method_label">SELECT PAYMENT</span>
                        </button>
                        <div class="text-center mt-2">
                            <small class="text-muted">Shortcut: Press <b>F8</b> to complete</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Receipt Print Modal -->
<div class="modal fade" id="receiptModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-body text-dark" id="receipt-content" style="font-family: 'Courier New', monospace; font-size: 13px;">
                <div class="text-center border-bottom pb-2 mb-2">
                    <h5 class="fw-bold mb-0"><?= strtoupper($pharm['name']) ?></h5>
                    <small><?= $branch['branch_name'] ?></small><br>
                    <small><?= $pharm['phone'] ?></small>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>Invoice: #<span id="rec_invoice"></span></span>
                    <span id="rec_date"></span>
                </div>
                <div class="small mb-2 text-muted">
                    <span>Issued By: <?= $issued_by ?></span>
                </div>

                <table class="w-100 mb-2" style="border-bottom: 1px dashed #ccc;">
                    <tbody id="rec_items"></tbody>
                </table>

                <div class="d-flex justify-content-between small">
                    <span>Subtotal</span>
                    <span>K<span id="rec_subtotal"></span></span>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>VAT (16%)</span>
                    <span>K<span id="rec_vat"></span></span>
                </div>
                <div class="d-flex justify-content-between fw-bold h5 mt-1">
                    <span>TOTAL</span>
                    <span>K<span id="rec_total"></span></span>
                </div>
                
                <div class="text-center mt-3 pt-2 border-top">
                    <small>Thank you for your business!</small>
                </div>
            </div>
            <div class="modal-footer p-2 border-0">
                <button type="button" class="btn btn-dark btn-sm flex-grow-1" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success btn-sm flex-grow-1" onclick="window.print()">Print</button>
            </div>
        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
let cart = [];
let selectedMethod = null;

// Hotkeys
$(document).on('keydown', function(e) {
    if(e.key === 'F2') { e.preventDefault(); $('#product_search').focus(); }
    if(e.key === 'F8') { e.preventDefault(); processSale(); }
});

// POS Live Search
$('#product_search').on('input', function() {
    let q = $(this).val();
    if(q.length < 1) {
        $('#product_results').hide();
        return;
    }
    $.post('pos_search.php', { query: q }, function(data){
        $('#product_results').html(data).show();
    });
});

// Hide search dropdown on click outside
$(document).on('click', function(e) {
    if (!$(e.target).closest('.search-section').length) {
        $('#product_results').hide();
    }
});

// Add Item to Cart
$(document).on('click', '.product-item', function() {
    const item = {
        id: $(this).data('id'),
        name: $(this).data('name'),
        price: parseFloat($(this).data('price')),
        stock: parseInt($(this).data('stock'))
    };

    if(item.stock <= 0) {
        alert('Item out of stock!');
        return;
    }

    let existing = cart.find(i => i.id === item.id);
    if(existing) {
        if(existing.qty < item.stock) {
            existing.qty++;
        } else {
            alert('Maximum available stock reached!');
        }
    } else {
        cart.push({...item, qty: 1});
    }

    renderCart();
    $('#product_results').hide();
    $('#product_search').val('').focus();
});

function renderCart() {
    if(cart.length === 0) {
        $('#pos_table').hide();
        $('#empty-cart-msg').show();
    } else {
        $('#pos_table').show();
        $('#empty-cart-msg').hide();
    }

    let html = '', total = 0, count = 0;
    cart.forEach((i, idx) => {
        let rowTotal = i.price * i.qty; 
        total += rowTotal;
        count += i.qty;
        html += `<tr class="cart-row">
            <td><div class="fw-bold">${i.name}</div><small class="text-muted">Stock: ${i.stock}</small></td>
            <td>K${i.price.toFixed(2)}</td>
            <td class="text-center">
                <div class="d-flex align-items-center justify-content-center gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 text-white" onclick="updateQty(${idx}, -1)">-</button>
                    <span class="fw-bold px-1">${i.qty}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 text-white" onclick="updateQty(${idx}, 1)">+</button>
                </div>
            </td>
            <td class="text-success fw-bold">K${rowTotal.toFixed(2)}</td>
            <td class="text-end"><button type="button" class="btn btn-link text-danger p-0" onclick="removeItem(${idx})"><i class="fas fa-times"></i></button></td>
        </tr>`;
    });

    let subtotal = total / 1.16;
    let vatAmount = total - subtotal;

    $('#cart_body').html(html);
    $('#txt_total').text(total.toFixed(2));
    $('#txt_vat').text('K' + vatAmount.toFixed(2));
    $('#txt_count').text(count);
    checkReady();
}

function updateQty(idx, change) {
    if(change > 0 && cart[idx].qty >= cart[idx].stock) {
        alert('Stock limit reached.');
        return;
    }
    cart[idx].qty += change;
    if(cart[idx].qty < 1) removeItem(idx);
    renderCart();
}

function removeItem(idx) { 
    cart.splice(idx, 1); 
    renderCart(); 
}

function setMethod(m, btn) {
    selectedMethod = m;
    $('.meth-btn').removeClass('active'); 
    $(btn).addClass('active');
    checkReady();
}

function checkReady() {
    const btn = $('#finalize_btn');
    if(cart.length > 0 && selectedMethod) {
        btn.prop('disabled', false).addClass('active-ready')
           .html(`<i class="fas fa-check-double me-2"></i> COMPLETE ${selectedMethod.toUpperCase()}`);
    } else {
        btn.prop('disabled', true).removeClass('active-ready').text('SELECT PAYMENT');
    }
}

function processSale() {
    if(cart.length === 0 || !selectedMethod) return;
    const total = $('#txt_total').text();
    $('#finalize_btn').prop('disabled', true).text('PROCESSING...');

    $.ajax({
        url: 'process_sale.php',
        method: 'POST',
        data: { 
            cart: JSON.stringify(cart), 
            payment_method: selectedMethod, 
            total_amount: total, 
            pharmacy_id: <?= $pharmacy_id ?>, 
            branch_id: <?= $branch_id ?> 
        },
        dataType: 'json',
        success: function(res) {
            if(res.status === 'success') {
                $('#rec_invoice').text(res.invoice);
                $('#rec_date').text(new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString());
                $('#rec_total').text(total);
                
                let subTotalVal = parseFloat(total) / 1.16;
                let vatVal = parseFloat(total) - subTotalVal;
                $('#rec_subtotal').text(subTotalVal.toFixed(2));
                $('#rec_vat').text(vatVal.toFixed(2));

                let itemsHtml = '';
                cart.forEach(i => {
                    itemsHtml += `<tr><td>${i.name} x${i.qty}</td><td class='text-end'>K${(i.price * i.qty).toFixed(2)}</td></tr>`;
                });
                $('#rec_items').html(itemsHtml);
                
                new bootstrap.Modal(document.getElementById('receiptModal')).show();

                cart = []; 
                renderCart(); 
                selectedMethod = null;
                $('.meth-btn').removeClass('active');
            } else {
                alert('Error: ' + res.message);
                checkReady();
            }
        },
        error: function() { 
            alert('Connection error.'); 
            checkReady(); 
        }
    });
}
</script>
</body>
</html>
