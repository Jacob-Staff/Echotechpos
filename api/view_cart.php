<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Database Connection Inclusion
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    die("Database connection file (conn.php) not found.");
}

// 2. Resolve Active Branch Context
$branch_id = isset($_GET['bid']) 
    ? intval($_GET['bid']) 
    : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 10);

$_SESSION['current_branch_id'] = $branch_id;

// 3. Handle AJAX Quantity Updates & Item Removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action  = $_POST['action'];
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;

    if ($item_id > 0 && isset($_SESSION['carts'][$branch_id][$item_id])) {
        if ($action === 'update_qty') {
            $new_qty = max(1, intval($_POST['qty']));
            $_SESSION['carts'][$branch_id][$item_id]['qty'] = $new_qty;
        } elseif ($action === 'remove') {
            unset($_SESSION['carts'][$branch_id][$item_id]);
        }
    }

    // Recalculate totals for active branch
    $total_items = 0;
    $grand_total = 0.00;
    if (isset($_SESSION['carts'][$branch_id])) {
        foreach ($_SESSION['carts'][$branch_id] as $cart_item) {
            $qty = intval($cart_item['qty'] ?? 1);
            $price = floatval($cart_item['price'] ?? 0);
            $total_items += $qty;
            $grand_total += ($price * $qty);
        }
    }

    echo json_encode([
        'status'      => 'success',
        'cart_count'  => $total_items,
        'grand_total' => number_format($grand_total, 2)
    ]);
    exit();
}

// Include Header Navigation
include_once __DIR__ . '/store_header.php';

// 4. Retrieve Cart Items for current branch
$cart_items = $_SESSION['carts'][$branch_id] ?? [];
$subtotal = 0.00;
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-dark mb-0">
            <i class="mdi mdi-cart-outline text-success"></i> Shopping Cart
        </h4>
        <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="mdi mdi-arrow-left"></i> Continue Shopping
        </a>
    </div>

    <?php if (empty($cart_items)): ?>
        <!-- Empty Cart State -->
        <div class="card shadow-sm border-0 text-center py-5">
            <div class="card-body">
                <i class="mdi mdi-cart-off text-muted" style="font-size: 64px;"></i>
                <h5 class="mt-3 text-secondary">Your cart is currently empty.</h5>
                <p class="text-muted small">Looks like you haven't added any products from this branch yet.</p>
                <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-success px-4 rounded-pill mt-2">
                    Browse Medicines
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Active Cart Contents -->
        <div class="row g-4">
            <!-- Items Table -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Product</th>
                                        <th>Price</th>
                                        <th style="width: 140px;">Quantity</th>
                                        <th>Total</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // 5. Iteration over $_SESSION['carts'][$branch_id]
                                    foreach ($cart_items as $item_id => $item): 
                                        $item_name  = htmlspecialchars($item['name'] ?? 'Product');
                                        $unit_price = floatval($item['price'] ?? 0);
                                        $qty        = intval($item['qty'] ?? 1);
                                        $line_total = $unit_price * $qty;
                                        $subtotal  += $line_total;
                                    ?>
                                        <tr id="cart-row-<?php echo $item_id; ?>">
                                            <td class="ps-3 fw-bold text-dark">
                                                <?php echo $item_name; ?>
                                            </td>
                                            <td>
                                                K<?php echo number_format($unit_price, 2); ?>
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       class="form-control form-control-sm text-center item-qty-input" 
                                                       value="<?php echo $qty; ?>" 
                                                       min="1" 
                                                       data-id="<?php echo $item_id; ?>"
                                                       data-price="<?php echo $unit_price; ?>">
                                            </td>
                                            <td class="fw-bold text-success line-total" id="line-total-<?php echo $item_id; ?>">
                                                K<?php echo number_format($line_total, 2); ?>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-danger border-0 btn-remove-item" 
                                                        data-id="<?php echo $item_id; ?>"
                                                        title="Remove Item">
                                                    <i class="mdi mdi-trash-can-outline fs-5"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3" style="color: var(--echo-teal);">Order Summary</h5>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Subtotal</span>
                            <span class="fw-bold" id="cart-subtotal">K<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Fulfillment</span>
                            <span class="text-success fw-bold">Pickup / Standard Delivery</span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-4 fs-5">
                            <span class="fw-bold">Total</span>
                            <span class="fw-bold text-success" id="cart-grand-total">K<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <a href="checkout.php?bid=<?php echo $branch_id; ?>" class="btn btn-success w-100 py-2 fw-bold rounded-pill">
                            Proceed to Checkout <i class="mdi mdi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const BRANCH_ID = <?php echo $branch_id; ?>;

// Handle quantity changes dynamically
$(document).on('change', '.item-qty-input', function() {
    const itemId = $(this).data('id');
    const unitPrice = parseFloat($(this).data('price'));
    const newQty = parseInt($(this).val()) || 1;

    // Local recalculation
    const lineTotal = unitPrice * newQty;
    $(`#line-total-${itemId}`).text('K' + lineTotal.toFixed(2));

    // AJAX session sync
    $.ajax({
        url: 'view_cart.php?bid=' + BRANCH_ID,
        type: 'POST',
        data: {
            action: 'update_qty',
            item_id: itemId,
            qty: newQty
        },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                $('.cart-count').text(res.cart_count);
                $('#cart-subtotal, #cart-grand-total').text('K' + res.grand_total);
            }
        }
    });
});

// Handle item deletion dynamically
$(document).on('click', '.btn-remove-item', function() {
    const itemId = $(this).data('id');
    
    if (confirm('Are you sure you want to remove this item from your cart?')) {
        $.ajax({
            url: 'view_cart.php?bid=' + BRANCH_ID,
            type: 'POST',
            data: {
                action: 'remove',
                item_id: itemId
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    $(`#cart-row-${itemId}`).fadeOut(300, function() {
                        $(this).remove();
                        if (res.cart_count === 0) {
                            location.reload();
                        }
                    });
                    $('.cart-count').text(res.cart_count);
                    $('#cart-subtotal, #cart-grand-total').text('K' + res.grand_total);
                }
            }
        });
    }
});
</script>

</body>
</html>
