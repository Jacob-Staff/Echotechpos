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

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

// Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $pharmacy_id, $branch_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

require_once "../includes/head.php";
?>

<style>
.layby-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
    color: #212529;
}

@media (min-width: 768px) {
    .layby-wrapper {
        padding: 1.5rem;
    }
}

.header-banner {
    background: #ffffff;
    padding: 1.25rem;
    border-radius: 12px;
    border-left: 5px solid #0d6efd;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    margin-bottom: 1.25rem;
}

.layby-card {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
}

.layby-card-header {
    background-color: #f8fafc;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}

.layby-card-title {
    color: #0f172a;
    font-weight: 700;
    font-size: 1.05rem;
    margin: 0;
}

.layby-card .form-control {
    background-color: #ffffff !important;
    color: #0f172a !important;
    border: 1px solid #cbd5e1 !important;
    padding: 0.6rem 0.85rem;
    font-size: 0.95rem;
    border-radius: 8px;
}

.layby-card .form-control:focus {
    border-color: #0d6efd !important;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15) !important;
}

.product-search-results { 
    position: absolute; 
    background-color: #ffffff; 
    border: 1px solid #0d6efd; 
    max-height: 280px; 
    overflow-y: auto; 
    width: 100%; 
    display: none; 
    z-index: 9999; 
    list-style: none; 
    padding: 0; 
    margin-top: 4px;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.product-item { 
    padding: 10px 14px; 
    cursor: pointer; 
    color: #1e293b; 
    border-bottom: 1px solid #f1f5f9; 
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-item:hover { 
    background-color: #e0f2fe; 
    color: #0369a1; 
    font-weight: 600;
}

.table-light-custom {
    color: #334155;
    margin-bottom: 0;
}

.table-light-custom thead th {
    background-color: #f1f5f9;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
}

.table-light-custom tbody td {
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    padding: 12px;
    font-size: 0.9rem;
}

.cart-qty-btn {
    width: 26px;
    height: 26px;
    padding: 0;
    line-height: 1;
    border-radius: 50%;
}

.balance-box {
    background: #f8fafc;
    border: 1px solid #0d6efd;
    border-radius: 10px;
}

@media (max-width: 767.98px) {
    .desktop-table-view {
        display: none !important;
    }
    .mobile-card-view {
        display: block !important;
    }
}

@media (min-width: 768px) {
    .desktop-table-view {
        display: table !important;
    }
    .mobile-card-view {
        display: none !important;
    }
}

.layby-mobile-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 15px;
    margin-bottom: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper layby-wrapper">
        <div class="container-fluid p-0">

            <!-- Banner Header -->
            <div class="header-banner d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><i class="fas fa-hand-holding-usd text-primary me-2"></i>Lay-by Management</h3>
                    <span class="text-secondary small">
                        <i class="fas fa-store me-1"></i> Branch: <b class="text-dark"><?php echo e($display_bran); ?></b> | <b class="text-dark"><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
            </div>

            <!-- Main Workstation Layout -->
            <div class="row g-3 g-lg-4">
                <!-- Left: Cart Area -->
                <div class="col-12 col-lg-7 col-xl-8">
                    <div class="card layby-card shadow-sm h-100">
                        <div class="layby-card-header d-flex align-items-center justify-content-between">
                            <h4 class="layby-card-title"><i class="fas fa-cart-plus me-2 text-primary"></i>New Lay-by Sale</h4>
                            <span class="badge bg-primary rounded-pill small fw-normal px-3 py-1">POS Mode</span>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <!-- Product Search Bar -->
                            <div class="position-relative mb-3">
                                <label class="form-label fw-bold text-dark small mb-1"><i class="fas fa-search me-1 text-primary"></i> Search Inventory Item</label>
                                <input type="text" id="product_search" class="form-control" placeholder="Type product name or scan barcode..." autocomplete="off">
                                <ul id="product_results" class="product-search-results"></ul>
                            </div>

                            <!-- Cart Table -->
                            <div class="table-responsive">
                                <table class="table table-light-custom align-middle">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Price</th>
                                            <th width="120" class="text-center">Qty</th>
                                            <th>Total</th>
                                            <th width="40"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="layby_items">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
                                                <i class="fas fa-shopping-basket display-6 d-block mb-2 opacity-25"></i>
                                                Cart is currently empty. Use the search box to add products.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Cart Summary -->
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mt-3 pt-3 border-top gap-3">
                                <button id="clear_cart" class="btn btn-outline-danger btn-sm px-3 align-self-start align-self-sm-center">
                                    <i class="fas fa-trash me-1"></i> Clear Cart
                                </button>
                                <div class="text-sm-end">
                                    <small class="text-muted text-uppercase d-block fw-bold" style="letter-spacing: 0.5px;">Cart Grand Total</small>
                                    <h3 class="text-dark fw-bold mb-0">K<span id="layby_total" class="text-success">0.00</span></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Customer Setup -->
                <div class="col-12 col-lg-5 col-xl-4">
                    <div class="card layby-card shadow-sm h-100">
                        <div class="layby-card-header">
                            <h4 class="layby-card-title"><i class="fas fa-user-check me-2 text-primary"></i>Customer & Payment</h4>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <form id="layby_form">
                                <div class="mb-2">
                                    <label class="form-label fw-bold text-dark small mb-1">Customer Full Name <span class="text-danger">*</span></label>
                                    <input type="text" id="customer_name" class="form-control" placeholder="e.g. John Banda" required>
                                </div>
                                
                                <div class="mb-2">
                                    <label class="form-label fw-bold text-dark small mb-1">Phone Number <span class="text-danger">*</span></label>
                                    <input type="tel" id="customer_phone" class="form-control" placeholder="e.g. 097xxxxxxx" required>
                                </div>
                                
                                <div class="mb-2">
                                    <label class="form-label fw-bold text-dark small mb-1">Initial Deposit (K)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-dark border-secondary">K</span>
                                        <input type="number" id="deposit" class="form-control" value="0.00" step="0.01" min="0">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-dark small mb-1">Final Settlement Due Date <span class="text-danger">*</span></label>
                                    <input type="date" id="due_date" class="form-control" required>
                                </div>
                                
                                <div class="balance-box p-3 mb-3 text-center">
                                    <small class="text-primary text-uppercase fw-bold d-block mb-1" style="letter-spacing: 0.5px;">Remaining Balance Due</small>
                                    <h3 class="text-dark fw-bold mb-0">K<span id="balance_due" class="text-danger">0.00</span></h3>
                                </div>
                                
                                <button type="submit" class="btn btn-success fw-bold w-100 py-2 shadow-sm">
                                    <i class="fas fa-check-circle me-1"></i> Create Agreement
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom: Existing Agreements Section -->
            <div class="card layby-card mt-4 shadow-sm">
                <div class="layby-card-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <h4 class="layby-card-title" id="list_title"><i class="fas fa-list-alt me-2 text-primary"></i>Active Lay-by Agreements</h4>
                    <div class="d-flex flex-wrap gap-2">
                        <button id="toggle_paid" class="btn btn-info btn-sm text-white fw-bold">View Fully Paid</button>
                    </div>
                </div>
                <div class="card-body p-2 p-md-3">
                    <!-- Desktop View -->
                    <div class="table-responsive">
                        <table class="table table-light-custom text-center align-middle desktop-table-view w-100">
                            <thead>
                                <tr>
                                    <th class="text-start ps-3">Customer</th>
                                    <th>Total Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Due Date</th>
                                    <th class="pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody id="layby_list_desktop">
                                <tr><td colspan="6" class="py-4 text-muted">Loading agreements...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div id="layby_list_mobile" class="mobile-card-view">
                        <div class="text-center py-4 text-muted">Loading agreements...</div>
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
let cart = [];
let showingPaid = false;

function loadRecords(){
    let status = showingPaid ? 'Completed' : 'Active';

    $('#layby_list_desktop').html('<tr><td colspan="6" class="py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading agreements...</td></tr>');
    $('#layby_list_mobile').html('<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading agreements...</div>');

    $.get('actions/fetch_laybys.php', { status: status })
    .done(function(data){
        $('#layby_list_desktop').html(data);

        let mobileHtml = '';
        let rows = $('#layby_list_desktop tr');

        if(rows.length && !$(rows[0]).find('td[colspan]').length) {
            rows.each(function(){
                let cols = $(this).find('td');
                if(cols.length >= 6) {
                    let customer = $(cols[0]).html();
                    let total    = $(cols[1]).text();
                    let paid     = $(cols[2]).text();
                    let balance  = $(cols[3]).text();
                    let dueDate  = $(cols[4]).text();
                    let action   = $(cols[5]).html();

                    mobileHtml += `
                        <div class="layby-mobile-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div><strong class="text-dark fs-6">${customer}</strong></div>
                                <span class="badge bg-light text-dark border">${dueDate}</span>
                            </div>
                            <div class="row g-2 mb-2 text-center small">
                                <div class="col-4">
                                    <div class="text-muted">Total</div>
                                    <div class="fw-bold">${total}</div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">Paid</div>
                                    <div class="fw-bold text-success">${paid}</div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">Balance</div>
                                    <div class="fw-bold text-danger">${balance}</div>
                                </div>
                            </div>
                            <div class="pt-2 border-top d-flex justify-content-end gap-2">
                                ${action}
                            </div>
                        </div>
                    `;
                }
            });
            $('#layby_list_mobile').html(mobileHtml);
        } else {
            $('#layby_list_mobile').html('<div class="text-center py-4 text-muted">No agreements found.</div>');
        }
    })
    .fail(function(){
        $('#layby_list_desktop').html('<tr><td colspan="6" class="text-danger py-4">Failed to load lay-by records.</td></tr>');
        $('#layby_list_mobile').html('<div class="text-center text-danger py-4">Failed to load lay-by records.</div>');
    });
}

$(document).ready(function(){
    loadRecords();

    let today = new Date();
    today.setDate(today.getDate() + 30);
    document.getElementById('due_date').valueAsDate = today;

    $('#toggle_paid').on('click', function(){
        showingPaid = !showingPaid;

        if(showingPaid){
            $(this).text('View Active').removeClass('btn-info').addClass('btn-warning');
            $('#list_title').html('<i class="fas fa-check-circle text-success me-2"></i>Fully Paid Lay-bys');
        } else {
            $(this).text('View Fully Paid').removeClass('btn-warning').addClass('btn-info');
            $('#list_title').html('<i class="fas fa-list-alt me-2 text-primary"></i>Active Lay-by Agreements');
        }

        loadRecords();
    });
});

$('#product_search').on('input', function(){
    let q = $(this).val().trim();
    if(q.length > 1){
        $.post('actions/fetch_products.php', { query: q }, data => $('#product_results').html(data).show());
    } else {
        $('#product_results').hide();
    }
});

$(document).on('click', '.product-item', function(){
    let item = {
        id: $(this).data('id'),
        name: $(this).data('name'),
        price: parseFloat($(this).data('price'))
    };
    let exist = cart.find(i => i.id == item.id);
    if(exist) exist.qty++;
    else cart.push({...item, qty: 1});

    renderCart();
    $('#product_results').hide();
    $('#product_search').val('');
});

function adjustQty(index, delta) {
    if (cart[index]) {
        cart[index].qty += delta;
        if (cart[index].qty <= 0) cart.splice(index, 1);
        renderCart();
    }
}

function renderCart() {
    if (cart.length === 0) {
        $('#layby_items').html(`
            <tr>
                <td colspan="5" class="text-center text-muted py-4">
                    <i class="fas fa-shopping-basket display-6 d-block mb-2 opacity-25"></i>
                    Cart is currently empty. Use the search box to add products.
                </td>
            </tr>
        `);
        $('#layby_total').text('0.00');
        updateBalance();
        return;
    }

    let html = '';
    let total = 0;
    cart.forEach((i, index) => {
        let sub = i.price * i.qty;
        total += sub;
        html += `<tr>
            <td class="text-start font-weight-bold">${i.name}</td>
            <td>K${i.price.toFixed(2)}</td>
            <td class="text-center">
                <div class="d-inline-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary cart-qty-btn" onclick="adjustQty(${index}, -1)">-</button>
                    <span class="fw-bold px-1">${i.qty}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary cart-qty-btn" onclick="adjustQty(${index}, 1)">+</button>
                </div>
            </td>
            <td class="text-success fw-bold">K${sub.toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeItem(${index})" title="Remove item">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        </tr>`;
    });
    $('#layby_items').html(html);
    $('#layby_total').text(total.toFixed(2));
    updateBalance();
}

function removeItem(index) { 
    cart.splice(index, 1); 
    renderCart(); 
}

function updateBalance() {
    let total = parseFloat($('#layby_total').text()) || 0;
    let deposit = parseFloat($('#deposit').val()) || 0;
    let balance = total - deposit;
    
    let balanceSpan = $('#balance_due');
    balanceSpan.text((balance < 0 ? 0 : balance).toFixed(2));

    if (balance <= 0 && total > 0) {
        balanceSpan.removeClass('text-danger').addClass('text-success');
    } else {
        balanceSpan.removeClass('text-success').addClass('text-danger');
    }
}

$('#deposit').on('input', updateBalance);
$('#clear_cart').on('click', function(){ cart = []; renderCart(); });

$('#layby_form').on('submit', function(e) {
    e.preventDefault();
    if(cart.length === 0) return alert("Please add at least one item to the cart.");

    const btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Creating...');

    $.post('actions/process_layby.php', {
        customer_name: $('#customer_name').val(),
        customer_phone: $('#customer_phone').val(),
        deposit: $('#deposit').val(),
        total: $('#layby_total').text(),
        due_date: $('#due_date').val(),
        cart: JSON.stringify(cart)
    }, function(res){
        if(res.status === 'success'){
            alert("Agreement Created Successfully!");
            location.reload();
        } else {
            alert("Error: " + (res.message || "Failed to save agreement."));
            btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Create Agreement');
        }
    }, 'json').fail(function(){
        alert("Server error occurred while creating agreement.");
        btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Create Agreement');
    });
});
</script>
</body>
</html>
