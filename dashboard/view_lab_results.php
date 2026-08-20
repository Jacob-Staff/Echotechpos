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
:root {
    --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    --accent-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --card-bg-dark: #1e293b;
    --card-header-dark: #0f172a;
    --border-color-dark: #334155;
    --text-neon: #38ef7d;
}

.layby-wrapper {
    background-color: #f1f5f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
    color: #334155;
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
    border-left: 6px solid #0d6efd;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    margin-bottom: 1.25rem;
}

/* Custom Modern Card Design */
.layby-card {
    background-color: var(--card-bg-dark);
    border: 1px solid var(--border-color-dark);
    border-radius: 14px;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.layby-card-header {
    background-color: var(--card-header-dark);
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color-dark);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.layby-card-title {
    color: var(--text-neon);
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
}

/* Forms & Inputs */
.layby-card .form-control {
    background-color: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid var(--border-color-dark) !important;
    padding: 0.65rem 0.85rem;
    font-size: 0.95rem;
}

.layby-card .form-control:focus {
    border-color: #38ef7d !important;
    box-shadow: 0 0 0 0.25rem rgba(56, 239, 125, 0.15) !important;
}

.layby-card .form-control::placeholder {
    color: #64748b !important;
}

/* Live Search Dropdown */
.product-search-results { 
    position: absolute; 
    background-color: #0f172a; 
    border: 1px solid #38ef7d; 
    max-height: 280px; 
    overflow-y: auto; 
    width: 100%; 
    display: none; 
    z-index: 9999; 
    list-style: none; 
    padding: 0; 
    margin-top: 4px;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
}

.product-item { 
    padding: 12px 15px; 
    cursor: pointer; 
    color: #f8fafc; 
    border-bottom: 1px solid var(--border-color-dark); 
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-item:hover { 
    background: var(--accent-gradient); 
    color: #000; 
    font-weight: 600;
}

/* Responsive Table Stylings */
.table-dark-custom {
    color: #f8fafc;
    margin-bottom: 0;
}

.table-dark-custom thead th {
    background-color: #0f172a;
    color: var(--text-neon);
    border-bottom: 2px solid var(--border-color-dark);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
}

.table-dark-custom tbody td {
    border-bottom: 1px solid var(--border-color-dark);
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
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border: 1px solid #0d6efd;
    border-radius: 10px;
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
                <!-- Left: Shopping Cart Area -->
                <div class="col-12 col-lg-7 col-xl-8">
                    <div class="card layby-card shadow-sm h-100">
                        <div class="layby-card-header">
                            <h4 class="layby-card-title"><i class="fas fa-cart-plus me-2"></i>New Lay-by Sale</h4>
                            <span class="badge bg-primary rounded-pill small fw-normal px-3 py-2">POS Mode</span>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <!-- Product Search Bar -->
                            <div class="position-relative mb-3">
                                <label class="text-white small mb-1"><i class="fas fa-search me-1"></i> Search Inventory Item</label>
                                <input type="text" id="product_search" class="form-control" placeholder="Type product name or scan barcode..." autocomplete="off">
                                <ul id="product_results" class="product-search-results"></ul>
                            </div>

                            <!-- Cart Table -->
                            <div class="table-responsive">
                                <table class="table table-dark-custom align-middle">
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

                            <!-- Cart Summary Footer -->
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mt-3 pt-3 border-top border-secondary gap-3">
                                <button id="clear_cart" class="btn btn-outline-danger btn-sm px-3 align-self-start align-self-sm-center">
                                    <i class="fas fa-trash me-1"></i> Clear Cart
                                </button>
                                <div class="text-sm-end">
                                    <small class="text-muted text-uppercase d-block fw-bold" style="letter-spacing: 1px;">Cart Grand Total</small>
                                    <h3 class="text-white fw-bold mb-0">K<span id="layby_total" class="text-success">0.00</span></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Customer Setup & Terms -->
                <div class="col-12 col-lg-5 col-xl-4">
                    <div class="card layby-card shadow-sm h-100">
                        <div class="layby-card-header">
                            <h4 class="layby-card-title"><i class="fas fa-user-check me-2"></i>Customer & Payment</h4>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <form id="layby_form">
                                <div class="mb-2">
                                    <label class="text-white small mb-1">Customer Full Name <span class="text-danger">*</span></label>
                                    <input type="text" id="customer_name" class="form-control" placeholder="e.g. John Banda" required>
                                </div>
                                
                                <div class="mb-2">
                                    <label class="text-white small mb-1">Phone Number <span class="text-danger">*</span></label>
                                    <input type="tel" id="customer_phone" class="form-control" placeholder="e.g. 097xxxxxxx" required>
                                </div>
                                
                                <div class="mb-2">
                                    <label class="text-white small mb-1">Initial Deposit (K)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-dark text-white border-secondary">K</span>
                                        <input type="number" id="deposit" class="form-control" value="0.00" step="0.01" min="0">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="text-white small mb-1">Final Settlement Due Date <span class="text-danger">*</span></label>
                                    <input type="date" id="due_date" class="form-control" required>
                                </div>
                                
                                <!-- Balance Preview Widget -->
                                <div class="balance-box p-3 mb-3 text-center">
                                    <small class="text-info text-uppercase fw-bold d-block mb-1" style="letter-spacing: 0.5px;">Remaining Balance Due</small>
                                    <h3 class="text-white fw-bold mb-0">K<span id="balance_due" class="text-warning">0.00</span></h3>
                                </div>
                                
                                <button type="submit" class="btn btn-success fw-bold w-100 py-2 shadow-sm">
                                    <i class="fas fa-check-circle me-1"></i> Create Agreement
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom: Existing Agreements Table -->
            <div class="card layby-card mt-4 shadow-sm">
                <div class="layby-card-header flex-column flex-sm-row gap-2">
                    <h4 class="layby-card-title" id="list_title"><i class="fas fa-list-alt me-2"></i>Active Lay-by Agreements</h4>
                    <div class="d-flex gap-2">
                        <button id="toggle_paid" class="btn btn-info btn-sm fw-bold">View Fully Paid</button>
                        <button id="clear_fully_paid" class="btn btn-danger btn-sm fw-bold" style="display:none;">Clear Fully Paid Lay-bys</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-custom text-center align-middle">
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
                            <tbody id="layby_list">
                                <tr><td colspan="6" class="py-4 text-muted">Loading agreements...</td></tr>
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

<script>
let cart = [];
let showingPaid = false;

/* LOAD RECORDS */
function loadRecords(){
    let status = showingPaid ? 'Completed' : 'Active';

    $('#layby_list').html('<tr><td colspan="6" class="py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading agreements...</td></tr>');

    $.get('actions/fetch_laybys.php', { status: status })
    .done(function(data){
        $('#layby_list').html(data);
    })
    .fail(function(){
        $('#layby_list').html('<tr><td colspan="6" class="text-danger py-4">Failed to load lay-by records.</td></tr>');
    });
}

$(document).ready(function(){

    loadRecords();

    /* Set default Due Date (e.g. 30 days from today) */
    let today = new Date();
    today.setDate(today.getDate() + 30);
    document.getElementById('due_date').valueAsDate = today;

    /* TOGGLE ACTIVE / PAID */
    $('#toggle_paid').on('click', function(){
        showingPaid = !showingPaid;

        if(showingPaid){
            $(this).text('View Active')
                   .removeClass('btn-info')
                   .addClass('btn-warning');

            $('#list_title').html('<i class="fas fa-check-circle text-success me-2"></i>Fully Paid Lay-bys');
            $('#clear_fully_paid').show();
        } else {
            $(this).text('View Fully Paid')
                   .removeClass('btn-warning')
                   .addClass('btn-info');

            $('#list_title').html('<i class="fas fa-list-alt me-2"></i>Active Lay-by Agreements');
            $('#clear_fully_paid').hide();
        }

        loadRecords();
    });

    /* CLEAR FULLY PAID LAYBYS */
    $('#clear_fully_paid').on('click', function(){
        if(!confirm("Are you sure you want to delete all fully paid lay-bys? This action cannot be undone.")) return;

        const btn = $(this);
        btn.prop('disabled', true).text('Clearing...');

        $.post('actions/clear_fully_paid.php', {}, function(res){
            if(res.status === 'success'){
                alert("All fully paid lay-bys cleared successfully!");
                loadRecords();
            } else {
                alert("Error: " + (res.message || 'Unable to clear records.'));
            }
        }, 'json').fail(function(){
            alert("Server error occurred while clearing records.");
        }).always(function(){
            btn.prop('disabled', false).text('Clear Fully Paid Lay-bys');
        });
    });

});

/* PRODUCT SEARCH */
$('#product_search').on('input', function(){
    let q = $(this).val().trim();
    if(q.length > 1){
        $.post('actions/fetch_products.php', { 
            query: q,
            pharmacy_id: "<?= $pharmacy_id ?>",
            branch_id: "<?= $branch_id ?>"
        }, data => $('#product_results').html(data).show());
    } else {
        $('#product_results').hide();
    }
});

/* ADD TO CART */
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
        if (cart[index].qty <= 0) {
            cart.splice(index, 1);
        }
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
                    <button type="button" class="btn btn-sm btn-outline-light cart-qty-btn" onclick="adjustQty(${index}, -1)">-</button>
                    <span class="fw-bold px-1">${i.qty}</span>
                    <button type="button" class="btn btn-sm btn-outline-light cart-qty-btn" onclick="adjustQty(${index}, 1)">+</button>
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
        balanceSpan.removeClass('text-warning').addClass('text-success');
    } else {
        balanceSpan.removeClass('text-success').addClass('text-warning');
    }
}

$('#deposit').on('input', updateBalance);
$('#clear_cart').on('click', function(){ cart = []; renderCart(); });

/* SAVE LAYBY */
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
