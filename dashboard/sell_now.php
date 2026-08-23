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
$issued_by   = htmlspecialchars($_SESSION['sessionUsername'] ?? $_SESSION['full_name'] ?? 'Staff');

// Fetch pharmacy info
$p_query = mysqli_prepare($conn, "SELECT name, address, phone FROM pharmacies WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($p_query, "i", $pharmacy_id);
mysqli_stmt_execute($p_query);
$p_res = mysqli_stmt_get_result($p_query);
$pharm = mysqli_fetch_assoc($p_res) ?: ['name' => 'Echo Prime Pharmacy', 'address' => 'Zambia', 'phone' => ''];

// Fetch branch info
$b_query = mysqli_prepare($conn, "SELECT branch_name, location, phone FROM branches WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($b_query, "i", $branch_id);
mysqli_stmt_execute($b_query);
$b_res = mysqli_stmt_get_result($b_query);
$branch = mysqli_fetch_assoc($b_res) ?: ['branch_name' => 'Main Branch', 'location' => 'Lusaka', 'phone' => ''];

require_once "../includes/head.php";
?>

<style>
:root { 
    --neon-green: #00ffae; 
    --dark-bg: #0f0f0f; 
    --panel-bg: #1a1a1a; 
    --row-hover: #252525; 
}

/* Page Layout */
.pos-wrapper {
    background-color: var(--dark-bg) !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
}

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

/* Search Box & Inputs */
.search-section { position: relative; }
#product_search { 
    background: #1e1e1e !important; 
    border: 1px solid #444 !important; 
    border-radius: 12px; 
    padding-left: 48px;
    color: #ffffff !important;
    font-weight: 600;
}
#product_search::placeholder {
    color: #888888 !important;
    opacity: 1;
}
#product_search:focus { 
    background: #222222 !important; 
    border-color: var(--neon-green) !important; 
    box-shadow: 0 0 12px rgba(0, 255, 174, 0.3) !important; 
}
.search-icon-fixed { 
    position: absolute; 
    left: 16px; 
    top: 16px; 
    z-index: 10; 
    color: var(--neon-green); 
    font-size: 1.2rem; 
}

/* Live Search Container */
.product-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1050;
    background: #181818;
    border: 1px solid #444;
    border-radius: 10px;
    max-height: 350px;
    overflow-y: auto;
    display: none;
    padding: 0;
    margin-top: 5px;
    list-style: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.9);
}

/* Cart Table Styling */
.cart-table-container { 
    background-color: var(--panel-bg); 
    border-radius: 15px; 
    overflow-y: auto; 
    border: 1px solid #333;
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
    color: #ffffff !important; text-transform: uppercase; z-index: 5;
    border-bottom: 1px solid #444;
}
.cart-row { border-bottom: 1px solid #282828; transition: 0.2s; }
.cart-row:hover { background: var(--row-hover); }
.cart-row td { padding: 12px 14px; color: #ffffff; vertical-align: middle; }

/* Right Payment Panel Elements */
.right-panel { 
    background: #151515; 
    border-radius: 15px; 
    padding: 1.25rem; 
    border: 1px solid #333; 
}
.total-section { 
    background: linear-gradient(145deg, #1e1e1e, #111); 
    border-radius: 12px; padding: 1.25rem; 
    border: 1px solid #333; margin-bottom: 1.25rem;
}
.label-contrast {
    color: #cccccc !important;
    font-weight: 500;
}

/* Payment Method Buttons */
.meth-btn { 
    height: 55px; background: #222; border: 1px solid #444; 
    color: #ffffff !important; font-weight: bold; border-radius: 10px; 
    transition: 0.2s;
}
.meth-btn:hover { border-color: var(--neon-green); color: #fff; }
.meth-btn.active { background: var(--neon-green) !important; color: #000 !important; border-color: #fff; }

.btn-payment-main { 
    height: 65px; border-radius: 12px; border: none; 
    font-weight: 800; font-size: 1.05rem; text-transform: uppercase;
    background: #2a2a2a; color: #888888; transition: 0.3s;
}
.btn-payment-main.active-ready { background: var(--neon-green); color: #000; cursor: pointer; }

#empty-cart-msg { text-align: center; margin-top: 60px; color: #aaaaaa; padding: 20px; }
#empty-cart-msg i { font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.6; }

/* Thermal Receipt Printable Area Styling */
#receipt-printable {
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    color: #000;
    line-height: 1.3;
}

.receipt-divider {
    border-top: 1px dashed #000;
    margin: 6px 0;
}

@media print {
    body * {
        visibility: hidden;
    }
    #receiptModal, #receiptModal * {
        visibility: visible;
    }
    #receiptModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        background: #fff !important;
    }
    .modal-dialog {
        max-width: 100% !important;
        margin: 0 !important;
    }
    .modal-content {
        border: none !important;
        box-shadow: none !important;
    }
    .modal-footer, .btn-close-modal {
        display: none !important;
    }
}
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
                            <h5 style="color: #ffffff;">Cart is Empty</h5>
                            <p class="small mb-0" style="color: #aaaaaa;">Scan or search products to begin transaction</p>
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
                                <span class="label-contrast">Items Count</span>
                                <span id="txt_count" class="text-white fw-bold">0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="label-contrast">VAT (16%)</span>
                                <span id="txt_vat" class="text-white fw-bold">K0.00</span>
                            </div>
                            <hr style="border-color: #444;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="h6 mb-0 text-white fw-bold">TOTAL DUE</span>
                                <span class="h3 mb-0 text-success fw-bold">K<span id="txt_total">0.00</span></span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small mb-2 fw-bold" style="color: #dddddd;">SELECT PAYMENT METHOD</label>
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
                            <small style="color: #aaaaaa;">Shortcut: Press <b style="color: #ffffff;">F8</b> to complete</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 bg-white">
            <div class="modal-body text-dark" id="receipt-printable">
                
                <!-- Pharmacy Header -->
                <div class="text-center">
                    <div class="fw-bold fs-6 text-uppercase"><?= htmlspecialchars($pharm['name']) ?></div>
                    <div class="small fw-bold"><?= htmlspecialchars($branch['branch_name']) ?></div>
                    <div class="small"><?= htmlspecialchars($branch['location'] ?? $pharm['address']) ?></div>
                    <?php if(!empty($pharm['phone'])): ?>
                        <div class="small">Tel: <?= htmlspecialchars($pharm['phone']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="receipt-divider"></div>

                <!-- Transaction Details -->
                <div class="small">
                    <div class="d-flex justify-content-between">
                        <span>Invoice:</span>
                        <span class="fw-bold" id="rec_invoice"></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Date:</span>
                        <span id="rec_date"></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Issued By:</span>
                        <span><?= htmlspecialchars($issued_by) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Method:</span>
                        <span class="fw-bold" id="rec_method">CASH</span>
                    </div>
                </div>

                <div class="receipt-divider"></div>

                <!-- Items Table -->
                <table class="w-100 small mb-1">
                    <thead>
                        <tr class="border-bottom">
                            <th class="text-start pb-1">Item</th>
                            <th class="text-center pb-1">Qty</th>
                            <th class="text-end pb-1">Total (K)</th>
                        </tr>
                    </thead>
                    <tbody id="rec_items"></tbody>
                </table>

                <div class="receipt-divider"></div>

                <!-- Totals Section -->
                <div class="small">
                    <div class="d-flex justify-content-between">
                        <span>Subtotal:</span>
                        <span>K<span id="rec_subtotal">0.00</span></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>VAT (16%):</span>
                        <span>K<span id="rec_vat">0.00</span></span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold fs-6 mt-1 pt-1 border-top border-dark">
                        <span>TOTAL:</span>
                        <span>K<span id="rec_total">0.00</span></span>
                    </div>
                </div>

                <div class="receipt-divider"></div>

                <!-- Receipt Footer -->
                <div class="text-center small mt-2">
                    <div>Thank you for your business!</div>
                    <div>Medicines sold are non-refundable.</div>
                    <div class="fw-bold mt-1">*** Get Well Soon ***</div>
                </div>

            </div>
            
            <div class="modal-footer p-2 border-0 bg-light btn-close-modal">
                <button type="button" class="btn btn-secondary btn-sm flex-grow-1" data-bs-dismiss="modal">Close</button>
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

$(document).on('keydown', function(e) {
    if(e.key === 'F2') { e.preventDefault(); $('#product_search').focus(); }
    if(e.key === 'F8') { e.preventDefault(); processSale(); }
});

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

$(document).on('click', function(e) {
    if (!$(e.target).closest('.search-section').length) {
        $('#product_results').hide();
    }
});

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
            <td><div class="fw-bold">${i.name}</div><small style="color:#aaaaaa;">Stock: ${i.stock}</small></td>
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

    $('#finalize_btn').prop('disabled', true).text('PROCESSING...');

    $.ajax({
        url: 'process_sale.php',
        method: 'POST',
        data: {
            /*
             * Only send product IDs and requested quantities.
             * The server determines pharmacy, branch, prices,
             * stock and totals from the authenticated session/database.
             */
            cart: JSON.stringify(
                cart.map(i => ({
                    id: parseInt(i.id, 10),
                    qty: parseInt(i.qty, 10)
                }))
            ),
            payment_method: selectedMethod
        },
        dataType: 'json',

        success: function(res) {

            if(res.status === 'success') {

                /*
                 * Receipt values come from the server-confirmed sale.
                 */
                $('#rec_invoice').text(res.invoice || '');

                $('#rec_date').text(
                    new Date().toLocaleDateString() + ' ' +
                    new Date().toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit'
                    })
                );

                $('#rec_method').text(
                    String(
                        res.payment_method ||
                        selectedMethod ||
                        ''
                    ).toUpperCase()
                );

                $('#rec_total').text(
                    Number(res.total || 0).toFixed(2)
                );

                $('#rec_subtotal').text(
                    Number(res.subtotal || 0).toFixed(2)
                );

                $('#rec_vat').text(
                    Number(res.vat || 0).toFixed(2)
                );


                /*
                 * Build receipt lines from the authoritative server
                 * response instead of the browser cart.
                 *
                 * .text() is used for product names so product data
                 * cannot inject HTML into the receipt.
                 */
                const items = Array.isArray(res.items)
                    ? res.items
                    : [];

                $('#rec_items').empty();

                items.forEach(function(item) {

                    const row = $('<tr></tr>');

                    $('<td></td>')
                        .addClass('py-1')
                        .text(item.name || '')
                        .appendTo(row);

                    $('<td></td>')
                        .addClass('text-center py-1')
                        .text(
                            'x' +
                            parseInt(
                                item.quantity || 0,
                                10
                            )
                        )
                        .appendTo(row);

                    $('<td></td>')
                        .addClass('text-end py-1')
                        .text(
                            'K' +
                            Number(
                                item.line_total || 0
                            ).toFixed(2)
                        )
                        .appendTo(row);

                    $('#rec_items').append(row);
                });


                /*
                 * Show thermal receipt modal.
                 */
                const receiptElement =
                    document.getElementById('receiptModal');

                const receiptModal =
                    bootstrap.Modal.getOrCreateInstance(
                        receiptElement
                    );

                receiptModal.show();


                /*
                 * Clear POS state ONLY after the server confirms
                 * that the transaction was committed.
                 */
                cart = [];
                selectedMethod = null;

                renderCart();

                $('.meth-btn').removeClass('active');

            } else {

                alert(
                    'Error: ' +
                    (
                        res.message ||
                        'The sale could not be completed.'
                    )
                );

                /*
                 * Keep the cart so the cashier can retry.
                 */
                checkReady();
            }
        },

        error: function(xhr) {

            let message =
                'Connection error. Please try again.';

            /*
             * process_sale.php returns safe JSON error messages.
             */
            if (
                xhr.responseJSON &&
                xhr.responseJSON.message
            ) {

                message = xhr.responseJSON.message;

            } else if (xhr.responseText) {

                try {

                    const parsed =
                        JSON.parse(xhr.responseText);

                    if (parsed.message) {
                        message = parsed.message;
                    }

                } catch (e) {
                    // Keep generic safe message.
                }
            }

            alert(message);

            /*
             * Keep cart intact after a failed request.
             */
            checkReady();
        }
    });
}
</script>
</body>
</html>
