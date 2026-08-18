<?php 
session_start();
ob_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = intval($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = intval($_SESSION['branch_id'] ?? 0);
$user_id     = intval($_SESSION['user_id'] ?? 0);
?>

<style>
:root { --neon-green: #00ffae; --panel-bg: #1e1e1e; --dark-bg: #121212; }
.page-wrapper { background-color: floralwhite !important; margin-left: 250px !important; margin-top: 64px !important; min-height: 100vh; padding: 20px !important; }
.page-wrapper .card { background-color: var(--panel-bg); border: 1px solid #333; border-radius: 12px; }
.page-wrapper .card-title { color: var(--neon-green); font-weight: 600; }
.page-wrapper .form-control { background-color: #2b2b2b !important; color: #fff !important; border: 1px solid #444 !important; }
.table-dark { color: #fff; }
.product-search-results { position: absolute; background-color: #2b2b2b; border: 1px solid var(--neon-green); max-height: 300px; overflow-y: auto; width: 100%; display: none; z-index: 9999; list-style: none; padding: 0; }
.product-item { padding: 10px; cursor: pointer; color: #fff; border-bottom: 1px solid #444; }
.product-item:hover { background: var(--neon-green); color: #000; }
</style>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow">
            <div class="card-body">
                <h4 class="card-title">New Lay-by Sale</h4>
                <div class="position-relative mb-3">
                    <input type="text" id="product_search" class="form-control" placeholder="Search product to add...">
                    <ul id="product_results" class="product-search-results"></ul>
                </div>
                <table class="table table-dark">
                    <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Total</th><th></th></tr></thead>
                    <tbody id="layby_items"></tbody>
                </table>
                <div class="d-flex justify-content-between mt-3">
                    <button id="clear_cart" class="btn btn-outline-danger btn-sm">Clear Cart</button>
                    <h4 class="text-white">Total: K<span id="layby_total">0.00</span></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow">
            <div class="card-body">
                <h4 class="card-title">Customer Setup</h4>
                <form id="layby_form">
                    <input type="text" id="customer_name" class="form-control mb-2" placeholder="Customer Name" required>
                    <input type="text" id="customer_phone" class="form-control mb-2" placeholder="Phone" required>
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-dark text-white">Deposit K</span>
                        <input type="number" id="deposit" class="form-control" value="0" step="0.01">
                    </div>
                    <input type="date" id="due_date" class="form-control mb-3" required>
                    <div class="alert alert-info py-2">Balance Due: <b>K<span id="balance_due">0.00</span></b></div>
                    <button type="submit" class="btn btn-success w-100 mt-2">Create Agreement</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4 shadow">
    <div class="card-body">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="card-title mb-0" id="list_title">Active Lay-by Agreements</h4>
    <div>
        <button id="toggle_paid" class="btn btn-info btn-sm">View Fully Paid</button>
        <button id="clear_fully_paid" class="btn btn-danger btn-sm" style="display:none;">Clear Fully Paid Lay-bys</button>
    </div>
</div>
        
        <div class="table-responsive">
            <table class="table table-dark text-center">
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
                <tbody id="layby_list"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="../assets/libs/jquery/dist/jquery.min.js"></script>

<script>
let cart = [];
let showingPaid = false;

/* LOAD RECORDS */
function loadRecords(){
    let status = showingPaid ? 'Completed' : 'Active';

    $('#layby_list').html('<tr><td colspan="6">Loading...</td></tr>');

    $.get('actions/fetch_laybys.php', { status: status })
    .done(function(data){
        $('#layby_list').html(data);
    })
    .fail(function(){
        $('#layby_list').html('<tr><td colspan="6" class="text-danger">Failed to load records</td></tr>');
    });
}

/* ENSURE DOM READY */
$(document).ready(function(){

    loadRecords();

    /* TOGGLE ACTIVE / PAID */
    $('#toggle_paid').on('click', function(){
        showingPaid = !showingPaid; // toggle status once

        if(showingPaid){
            $(this).text('View Active')
                   .removeClass('btn-info')
                   .addClass('btn-warning');

            $('#list_title').text('Fully Paid Lay-bys');
            $('#clear_fully_paid').show(); // Show the clear button
        } else {
            $(this).text('View Fully Paid')
                   .removeClass('btn-warning')
                   .addClass('btn-info');

            $('#list_title').text('Active Lay-by Agreements');
            $('#clear_fully_paid').hide(); // Hide the clear button
        }

        loadRecords(); // refresh table dynamically
    });

    /* CLEAR FULLY PAID LAYBYS */
    $('#clear_fully_paid').on('click', function(){
        if(!confirm("Are you sure you want to delete all fully paid lay-bys?")) return;

        const btn = $(this);
        btn.prop('disabled', true).text('Clearing...');

        $.post('actions/clear_fully_paid.php', {}, function(res){
            if(res.status === 'success'){
                alert("All fully paid lay-bys cleared!");
                loadRecords();
            } else {
                alert("Error: " + res.message);
            }
        }, 'json').always(function(){
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
    let html = '';
    let total = 0;
    cart.forEach((i, index) => {
        let sub = i.price * i.qty;
        total += sub;
        html += `<tr>
            <td>${i.name}</td>
            <td>K${i.price.toFixed(2)}</td>
            <td>${i.qty}</td>
            <td>K${sub.toFixed(2)}</td>
            <td><button class="btn btn-sm btn-danger" onclick="removeItem(${index})">×</button></td>
        </tr>`;
    });
    $('#layby_items').html(html);
    $('#layby_total').text(total.toFixed(2));
    updateBalance();
}

function removeItem(index) { cart.splice(index, 1); renderCart(); }

function updateBalance() {
    let total = parseFloat($('#layby_total').text()) || 0;
    let deposit = parseFloat($('#deposit').val()) || 0;
    $('#balance_due').text((total - deposit).toFixed(2));
}

$('#deposit').on('input', updateBalance);
$('#clear_cart').on('click', function(){ cart = []; renderCart(); });

/* SAVE LAYBY */
$('#layby_form').on('submit', function(e) {
    e.preventDefault();
    if(cart.length === 0) return alert("Cart is empty");

    const btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).text('Saving...');

    $.post('actions/process_layby.php', {
        customer_name: $('#customer_name').val(),
        customer_phone: $('#customer_phone').val(),
        deposit: $('#deposit').val(),
        total: $('#layby_total').text(),
        due_date: $('#due_date').val(),
        cart: JSON.stringify(cart)
    }, function(res){
        if(res.status === 'success'){
            alert("Agreement Created!");
            location.reload();
        } else {
            alert("Error: " + res.message);
            btn.prop('disabled', false).text('Create Agreement');
        }
    }, 'json');
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php";
?>