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
    --neon-green: #00ffae; 
    --panel-bg: #1e1e1e; 
    --dark-bg: #121212; 
}

.layby-wrapper {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.header-section {
    background: #ffffff;
    padding: 1.25rem 1.5rem;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    border-left: 5px solid #0d6efd;
    margin-bottom: 1.25rem;
}

.layby-card {
    background-color: var(--panel-bg);
    border: 1px solid #333;
    border-radius: 12px;
}

.layby-card-title {
    color: var(--neon-green);
    font-weight: 600;
}

.layby-card .form-control {
    background-color: #2b2b2b !important;
    color: #fff !important;
    border: 1px solid #444 !important;
}

.layby-card .form-control::placeholder {
    color: #aaa !important;
}

.product-search-results { 
    position: absolute; 
    background-color: #2b2b2b; 
    border: 1px solid var(--neon-green); 
    max-height: 300px; 
    overflow-y: auto; 
    width: 100%; 
    display: none; 
    z-index: 9999; 
    list-style: none; 
    padding: 0; 
    margin-top: 2px;
    border-radius: 6px;
}

.product-item { 
    padding: 10px; 
    cursor: pointer; 
    color: #fff; 
    border-bottom: 1px solid #444; 
}

.product-item:hover { 
    background: var(--neon-green); 
    color: #000; 
}

.layby-table-container {
    background-color: var(--panel-bg);
    border-radius: 10px;
    border: 1px solid #333;
    overflow: hidden;
}

.table-dark-custom {
    color: #fff;
    margin-bottom: 0;
}

.table-dark-custom thead th {
    background-color: #151515;
    color: var(--neon-green);
    border-bottom: 1px solid #444;
}

.table-dark-custom tbody td {
    border-bottom: 1px solid #333;
    vertical-align: middle;
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper layby-wrapper">
        <div class="container-fluid p-0">

            <!-- Top Header Section -->
            <div class="header-section d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Lay-by Sales & Agreements</h3>
                    <span class="text-primary small">
                        <i class="fas fa-map-marker-alt me-1"></i> Branch: <b><?php echo e($display_bran); ?></b> | <b><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
            </div>

            <!-- Layby Entry Forms -->
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card layby-card shadow">
                        <div class="card-body">
                            <h4 class="layby-card-title mb-3"><i class="fas fa-shopping-cart me-2"></i>New Lay-by Sale</h4>
                            <div class="position-relative mb-3">
                                <input type="text" id="product_search" class="form-control" placeholder="Search product name or barcode to add...">
                                <ul id="product_results" class="product-search-results"></ul>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-dark-custom">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Price</th>
                                            <th width="100">Qty</th>
                                            <th>Total</th>
                                            <th width="50"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="layby_items">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">Cart is empty. Search products above.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <button id="clear_cart" class="btn btn-outline-danger btn-sm">Clear Cart</button>
                                <h4 class="text-white mb-0">Total: <span class="text-success">K<span id="layby_total">0.00</span></span></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card layby-card shadow">
                        <div class="card-body">
                            <h4 class="layby-card-title mb-3"><i class="fas fa-user-tag me-2"></i>Customer Setup</h4>
                            <form id="layby_form">
                                <label class="text-white small mb-1">Customer Name</label>
                                <input type="text" id="customer_name" class="form-control mb-2" placeholder="Full Name" required>
                                
                                <label class="text-white small mb-1">Phone Number</label>
                                <input type="text" id="customer_phone" class="form-control mb-2" placeholder="097xxxxxxx" required>
                                
                                <label class="text-white small mb-1">Initial Deposit</label>
                                <div class="input-group mb-2">
                                    <span class="input-group-text bg-dark text-white border-secondary">K</span>
                                    <input type="number" id="deposit" class="form-control" value="0.00" step="0.01" min="0">
                                </div>
                                
                                <label class="text-white small mb-1">Due Date</label>
                                <input type="date" id="due_date" class="form-control mb-3" required>
                                
                                <div class="alert alert-info py-2 mb-3 bg-dark text-info border-info">
                                    Balance Due: <b class="fs-5">K<span id="balance_due">0.00</span></b>
                                </div>
                                
                                <button type="submit" class="btn btn-success fw-bold w-100 mt-2 py-2">
                                    <i class="fas fa-file-contract me-1"></i> Create Agreement
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Agreements List -->
            <div class="card layby-card mt-4 shadow">
                <div class="card-body">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-3 gap-2">
                        <h4 class="layby-card-title mb-0" id="list_title"><i class="fas fa-list me-2"></i>Active Lay-by Agreements</h4>
                        <div class="d-flex gap-2">
                            <button id="toggle_paid" class="btn btn-info btn-sm fw-bold">View Fully Paid</button>
                            <button id="clear_fully_paid" class="btn btn-danger btn-sm fw-bold" style="display:none;">Clear Fully Paid Lay-bys</button>
                        </div>
                    </div>
                    
                    <div class="table-responsive layby-table-container">
                        <table class="table table-dark-custom text-center">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Total Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Due Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="layby_list">
                                <tr><td colspan="6" class="py-4 text-muted">Loading records...</td></tr>
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

    /* TOGGLE ACTIVE / PAID */
    $('#toggle_paid').on('click', function(){
        showingPaid = !showingPaid;

        if(showingPaid){
            $(this).text('View Active')
                   .removeClass('btn-info')
                   .addClass('btn-warning');

            $('#list_title').html('<i class="fas fa-check-circle me-2"></i>Fully Paid Lay-bys');
            $('#clear_fully_paid').show();
        } else {
            $(this).text('View Fully Paid')
                   .removeClass('btn-warning')
                   .addClass('btn-info');

            $('#list_title').html('<i class="fas fa-list me-2"></i>Active Lay-by Agreements');
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

function renderCart() {
    if (cart.length === 0) {
        $('#layby_items').html('<tr><td colspan="5" class="text-center text-muted py-3">Cart is empty. Search products above.</td></tr>');
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
            <td class="text-start">${i.name}</td>
            <td>K${i.price.toFixed(2)}</td>
            <td>${i.qty}</td>
            <td class="text-success">K${sub.toFixed(2)}</td>
            <td><button class="btn btn-sm btn-outline-danger" onclick="removeItem(${index})"><i class="fas fa-times"></i></button></td>
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
    $('#balance_due').text((balance < 0 ? 0 : balance).toFixed(2));
}

$('#deposit').on('input', updateBalance);
$('#clear_cart').on('click', function(){ cart = []; renderCart(); });

/* SAVE LAYBY */
$('#layby_form').on('submit', function(e) {
    e.preventDefault();
    if(cart.length === 0) return alert("Please add at least one item to the cart.");

    const btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

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
            btn.prop('disabled', false).html('<i class="fas fa-file-contract me-1"></i> Create Agreement');
        }
    }, 'json').fail(function(){
        alert("Server error occurred while creating agreement.");
        btn.prop('disabled', false).html('<i class="fas fa-file-contract me-1"></i> Create Agreement');
    });
});
</script>

</body>
</html>
