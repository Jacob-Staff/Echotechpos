<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

$pharmacy_id = intval($_SESSION['pharmacy_id']);
$branch_id = intval($_SESSION['branch_id']);
// Pulling the user's name from session (using the key 'sessionUsername' found in your aside.php)
$issued_by = htmlspecialchars($_SESSION['sessionUsername'] ?? 'Staff');

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
?>

<style>
:root { --neon-green: #00ffae; --dark-bg: #0f0f0f; --panel-bg: #1a1a1a; --row-hover: #252525; }

/* Enhanced Layout */
.page-wrapper { 
    background-color: var(--dark-bg) !important;
    margin-left: 250px !important; padding-top: 64px !important; height: 100vh !important; overflow: hidden !important; }
    .main-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; padding: 1.5rem; height: calc(100vh - 80px); }

/* Modern Search Bar */
.search-section { position: relative; }
#product_search { 
    background: rgba(255,255,255,0.05); 
    border: 1px solid #333; 
    border-radius: 15px; 
    transition: all 0.3s;
    padding-left: 50px;
}
#product_search:focus { 
    background: #1e1e1e; 
    border-color: var(--neon-green); 
    box-shadow: 0 0 15px rgba(0, 255, 174, 0.2); 
}
/* CHANGED: Icon styling for barcode */
.search-icon-fixed { position: absolute; left: 15px; top: 18px; z-index: 10; color: var(--neon-green); font-size: 1.2rem; }

/* Cart Styling */
.cart-table-container { 
    background-color: var(--panel-bg); 
    border-radius: 15px; 
    padding: 0; 
    overflow-y: auto; 
    border: 1px solid #222;
    position: relative;
}
.cart-table { width: 100%; border-collapse: collapse; }
.cart-table thead th { 
    position: sticky; top: 0; background: #222; 
    padding: 15px; text-align: left; font-size: 11px; 
    color: #888; text-transform: uppercase; z-index: 5;
}
.cart-row { border-bottom: 1px solid #222; transition: 0.2s; animation: slideIn 0.3s ease-out; }
.cart-row:hover { background: var(--row-hover); }
.cart-row td { padding: 15px; color: #eee; }

@keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }

/* Right Panel Refinements */
.right-panel { background: #151515; border-radius: 15px; padding: 1.5rem; border: 1px solid #222; }
.total-section { 
    background: linear-gradient(145deg, #1e1e1e, #111); 
    border-radius: 12px; padding: 1.5rem; 
    border: 1px solid #333; margin-bottom: 1.5rem;
    box-shadow: inset 0 0 10px rgba(0,0,0,0.5);
}

/* Payment Buttons */
.meth-btn { 
    height: 60px; background: #222; border: 1px solid #333; 
    color: #aaa; font-weight: bold; border-radius: 10px; 
    transition: 0.3s;
}
.meth-btn:hover { border-color: var(--neon-green); color: #fff; }
.meth-btn.active { background: var(--neon-green) !important; color: #000 !important; border-color: #fff; transform: scale(1.05); }

/* Finalize Button */
.btn-payment-main { 
    height: 90px; border-radius: 15px; border: none; 
    font-weight: 900; font-size: 1.1rem; text-transform: uppercase;
    background: #333; color: #666; transition: 0.4s;
}
.btn-payment-main.active-ready { background: var(--neon-green); color: #000; cursor: pointer; }

#empty-cart-msg { text-align: center; margin-top: 100px; color: #444; }
#empty-cart-msg i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.3; }

/* Receipt Styling */
#receipt-content { font-family: 'Courier New', monospace; color: #000; padding: 5px; }
@media print {
    .modal-backdrop, .modal-footer, .btn-close { display: none !important; }
}
</style>

<div class="main-grid">
    <div class="d-flex flex-column gap-3">
        <div class="search-section">
            <i class="fas fa-barcode search-icon-fixed"></i>
            <input type="text" class="form-control form-control-lg text-white" id="product_search" placeholder="Scan or Search medicines... (Press F2)" autofocus autocomplete="off">
            <ul id="product_results" class="product-search-results"></ul>
        </div>

        <div class="cart-table-container">
            <div id="empty-cart-msg">
                <i class="fas fa-shopping-basket"></i>
                <h5>Cart is Empty</h5>
                <p>Add products to start a sale</p>
            </div>
            <table class="cart-table" id="pos_table" style="display:none;">
                <thead>
                    <tr>
                        <th width="45%">Product</th>
                        <th width="15%">Price</th>
                        <th width="20%" class="text-center">Qty</th>
                        <th width="15%">Total</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody id="cart_body"></tbody>
            </table>
        </div>
    </div>

    <div class="right-panel d-flex flex-column">
        <div class="total-section">
            <div class="d-flex justify-content-between mb-2">
                <span style="color: #666;">Items Count</span>
                <span id="txt_count" class="text-white fw-bold">0</span>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span style="color: #666;">VAT (16%)</span>
                <span id="txt_vat" class="text-white fw-bold">K0.00</span>
            </div>
            <hr style="border-color: #333;">
            <div class="d-flex justify-content-between align-items-center">
                <span class="h5 mb-0 text-white">TOTAL DUE</span>
                <span class="h2 mb-0 text-success fw-bold">K<span id="txt_total">0.00</span></span>
            </div>
        </div>

        <div class="mb-4">
            <label class="small text-muted mb-3 fw-bold letter-spacing-1">SELECT PAYMENT METHOD</label>
            <div class="row g-2">
                <div class="col-4"><button class="btn w-100 meth-btn" onclick="setMethod('Cash', this)"><i class="fas fa-money-bill-wave d-block mb-1"></i>CASH</button></div>
                <div class="col-4"><button class="btn w-100 meth-btn" onclick="setMethod('Card', this)"><i class="fas fa-credit-card d-block mb-1"></i>CARD</button></div>
                <div class="col-4"><button class="btn w-100 meth-btn" onclick="setMethod('Mobile Money', this)"><i class="fas fa-mobile-alt d-block mb-1"></i>MOBILE</button></div>
            </div>
        </div>

        <div class="mt-auto">
            <button class="btn-payment-main w-100 shadow-lg" id="finalize_btn" onclick="processSale()" disabled>
                <span id="method_label">SELECT PAYMENT</span>
            </button>
            <div class="text-center mt-2">
                <small class="text-muted">Shortcut: Press <b>F8</b> to finish</small>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="receiptModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-body" id="receipt-content">
                <div class="text-center border-bottom pb-2 mb-2">
                    <h5 class="fw-bold mb-0"><?= strtoupper($pharm['name']) ?></h5>
                    <small><?= $branch['branch_name'] ?></small><br>
                    <small><?= $pharm['phone'] ?></small>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>#<span id="rec_invoice"></span></span>
                    <span id="rec_date"></span>
                </div>
                <div class="small mb-2 text-muted">
                    <span>Issued By: <?= $issued_by ?></span>
                </div>

                <table class="w-100 mb-2" style="font-size: 12px; border-bottom: 1px dashed #ccc;">
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
                <div class="d-flex justify-content-between fw-bold h5">
                    <span>TOTAL</span>
                    <span>K<span id="rec_total"></span></span>
                </div>
                
                <div class="text-center mt-3 pt-2 border-top">
                    <small>Thank you for choosing us!</small>
                </div>
            </div>
            <div class="modal-footer p-2 border-0">
                <button type="button" class="btn btn-dark btn-sm flex-grow-1" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success btn-sm flex-grow-1" onclick="window.print()">Print (Enter)</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/libs/jquery/dist/jquery.min.js"></script>
<script>
let cart = [];
let selectedMethod = null;

// Keyboard Shortcuts
$(document).on('keydown', function(e) {
    if(e.key === 'F2') { e.preventDefault(); $('#product_search').focus(); }
    if(e.key === 'F8') { e.preventDefault(); processSale(); }
});

$('#product_search').on('input', function() {
    let q = $(this).val();
    if(q.length < 2) return $('#product_results').hide();
    $.post('fetch_products.php', { query: q, p_id: <?= $pharmacy_id ?>, b_id: <?= $branch_id ?> }, function(data){
        $('#product_results').html(data).show();
    });
});

$(document).on('click', '.product-item', function() {
    const item = {
        id: $(this).data('id'),
        name: $(this).data('name'),
        price: parseFloat($(this).data('price')),
        stock: parseInt($(this).data('stock'))
    };

    if(item.stock <= 0) return alert('Out of stock!');

    let existing = cart.find(i => i.id === item.id);
    if(existing) {
        if(existing.qty < item.stock) existing.qty++;
    } else {
        cart.push({...item, qty:1});
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

    let html = '', total=0, count=0;
    cart.forEach((i, idx)=>{
        let rowTotal = i.price*i.qty; 
        total += rowTotal;
        count += i.qty;
        html += `<tr class="cart-row">
            <td><div class="fw-bold">${i.name}</div><small class="text-muted">Stock: ${i.stock}</small></td>
            <td>K${i.price.toFixed(2)}</td>
            <td>
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <button class="btn btn-xs btn-outline-secondary py-0" onclick="updateQty(${idx}, -1)">-</button>
                    <span class="fw-bold">${i.qty}</span>
                    <button class="btn btn-xs btn-outline-secondary py-0" onclick="updateQty(${idx}, 1)">+</button>
                </div>
            </td>
            <td class="text-success fw-bold">K${rowTotal.toFixed(2)}</td>
            <td class="text-end"><button class="btn btn-link text-danger p-0" onclick="removeItem(${idx})"><i class="fas fa-times"></i></button></td>
        </tr>`;
    });

    // CALCULATE VAT (16%)
    let subtotal = total / 1.16;
    let vatAmount = total - subtotal;

    $('#cart_body').html(html);
    $('#txt_total').text(total.toFixed(2));
    $('#txt_vat').text('K' + vatAmount.toFixed(2));
    $('#txt_count').text(count);
    checkReady();
}

function updateQty(idx, change){
    if(change>0 && cart[idx].qty >= cart[idx].stock) return;
    cart[idx].qty += change;
    if(cart[idx].qty<1) removeItem(idx);
    renderCart();
}

function removeItem(idx){ cart.splice(idx,1); renderCart(); }

function setMethod(m, btn){
    selectedMethod = m;
    $('.meth-btn').removeClass('active'); 
    $(btn).addClass('active');
    checkReady();
}

function checkReady(){
    const btn = $('#finalize_btn');
    if(cart.length > 0 && selectedMethod){
        btn.prop('disabled', false).addClass('active-ready btn-pulse')
           .html(`<i class="fas fa-check-double me-2"></i> COMPLETE ${selectedMethod.toUpperCase()}`);
    } else {
        btn.prop('disabled', true).removeClass('active-ready btn-pulse').text('SELECT PAYMENT');
    }
}

function processSale(){
    if(cart.length===0 || !selectedMethod) return;
    const total = $('#txt_total').text();
    $('#finalize_btn').prop('disabled', true).text('PROCESSING...');

    $.ajax({
        url: 'process_sale.php',
        method: 'POST',
        data: { cart: JSON.stringify(cart), payment_method: selectedMethod, total_amount: total, pharmacy_id: <?= $pharmacy_id ?>, branch_id: <?= $branch_id ?> },
        dataType: 'json',
        success: function(res){
            if(res.status==='success'){
                $('#rec_invoice').text(res.invoice);
                $('#rec_date').text(new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString());
                $('#rec_total').text(total);
                
                // Receipt VAT Calculation
                let subTotalVal = parseFloat(total) / 1.16;
                let vatVal = parseFloat(total) - subTotalVal;
                $('#rec_subtotal').text(subTotalVal.toFixed(2));
                $('#rec_vat').text(vatVal.toFixed(2));

                let itemsHtml = '';
                cart.forEach(i => {
                    itemsHtml += `<tr><td>${i.name} x${i.qty}</td><td class='text-end'>K${(i.price*i.qty).toFixed(2)}</td></tr>`;
                });
                $('#rec_items').html(itemsHtml);
                
                new bootstrap.Modal(document.getElementById('receiptModal')).show();

                cart=[]; renderCart(); selectedMethod=null;
                $('.meth-btn').removeClass('active');
            } else {
                alert('Error: '+res.message);
                checkReady();
            }
        },
        error:function(){ alert('Connection error.'); checkReady(); }
    });
}
</script>

<?php
$content = ob_get_clean();
require "../includes/header.php"; 
?>
