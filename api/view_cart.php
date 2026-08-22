<?php
require_once(__DIR__ . "/../includes/conn.php");

$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 10);
$_SESSION['current_branch_id'] = $branch_id;

// Load header
require_once "store_header.php"; 

// Initialize multidimensional cart array structure
if (!isset($_SESSION['carts'])) {
    $_SESSION['carts'] = [];
}
if (!isset($_SESSION['carts'][$branch_id])) {
    $_SESSION['carts'][$branch_id] = [];
}

// Handle Quantity Updates
if (isset($_POST['update_cart'])) {
    foreach ($_POST['qty'] as $id => $qty) {
        $qty = intval($qty);
        if ($qty <= 0) {
            unset($_SESSION['carts'][$branch_id][$id]);
        } else {
            if (isset($_SESSION['carts'][$branch_id][$id])) {
                $_SESSION['carts'][$branch_id][$id]['qty'] = $qty;
            }
        }
    }
    header("Location: view_cart.php?bid=$branch_id");
    exit();
}

// Handle Removal
if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    unset($_SESSION['carts'][$branch_id][$remove_id]);
    header("Location: view_cart.php?bid=$branch_id");
    exit();
}

$grand_total = 0;
$active_cart = $_SESSION['carts'][$branch_id];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart | <?php echo htmlspecialchars($pharmacy_name); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --echo-teal: #003339; --echo-green: #00b386;   
            --echo-bg: #f8fafc;
        }
        body { background-color: var(--echo-bg); font-family: 'Inter', sans-serif; }
        .cart-container { margin-top: 40px; margin-bottom: 60px; }
        .cart-card { border: none; border-radius: 15px; background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .cart-table thead { background: #f1f5f9; }
        .cart-table th { border: none; padding: 15px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        .cart-table td { vertical-align: middle; padding: 20px 15px; border-color: #f1f5f9; }
        .product-name { font-weight: 700; color: var(--echo-teal); margin-bottom: 0; text-decoration: none; }
        .product-name:hover { color: var(--echo-green); }
        .qty-group { width: 110px; display: flex; align-items: center; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .qty-btn { background: #fff; border: none; width: 35px; height: 35px; font-weight: bold; transition: 0.2s; }
        .qty-btn:hover { background: #f1f5f9; }
        .qty-input { width: 40px; border: none; text-align: center; font-size: 14px; font-weight: 600; outline: none !important; }
        .summary-card { border: none; border-radius: 15px; background: var(--echo-teal); color: white; padding: 30px; position: sticky; top: 100px; }
        .btn-checkout { 
            background: var(--echo-green); color: white; border: none; width: 100%; padding: 15px; 
            border-radius: 10px; font-weight: 700; font-size: 16px; transition: 0.3s;
        }
        .btn-checkout:hover { background: #009670; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,179,134,0.4); }
        .empty-cart-state { padding: 80px 20px; text-align: center; }
        .empty-cart-state i { font-size: 80px; color: #cbd5e1; margin-bottom: 20px; display: block; }
    </style>
</head>
<body>

<div class="container cart-container">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="cart-card">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                    <h4 class="fw-bold mb-0">Shopping Cart</h4>
                    <span class="text-muted small"><?php echo count($active_cart); ?> Items</span>
                </div>

                <?php if(!empty($active_cart)): ?>
                <form action="view_cart.php?bid=<?php echo $branch_id; ?>" method="POST" id="cartForm">
                    <div class="table-responsive">
                        <table class="table cart-table mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_cart as $id => $item): 
                                    $sub = $item['price'] * $item['qty'];
                                    $grand_total += $sub;
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                        </div>
                                    </td>
                                    <td class="fw-bold">K <?php echo number_format($item['price'], 2); ?></td>
                                    <td>
                                        <div class="qty-group">
                                            <button type="button" class="qty-btn" onclick="changeQty(this, -1)">-</button>
                                            <input type="text" name="qty[<?php echo $id; ?>]" class="qty-input" value="<?php echo $item['qty']; ?>" readonly>
                                            <button type="button" class="qty-btn" onclick="changeQty(this, 1)">+</button>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-success">K <?php echo number_format($sub, 2); ?></td>
                                    <td class="text-end">
                                        <a href="view_cart.php?remove=<?php echo $id; ?>&bid=<?php echo $branch_id; ?>" class="text-danger">
                                            <i class="mdi mdi-delete-outline fs-4"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 bg-light text-end">
                        <button type="submit" name="update_cart" class="btn btn-sm btn-outline-secondary rounded-pill px-4 fw-bold">
                            Update Quantities
                        </button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="empty-cart-state">
                        <i class="mdi mdi-cart-remove"></i>
                        <h4 class="fw-bold">Your cart is feeling light</h4>
                        <p class="text-muted">You haven't added any medicines to this branch's cart yet.</p>
                        <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-success rounded-pill px-5 mt-3">Start Shopping</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="mt-4">
                <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none fw-bold text-muted">
                    <i class="mdi mdi-arrow-left"></i> Continue Shopping
                </a>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="summary-card">
                <h5 class="fw-bold mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.1) !important;">Order Summary</h5>
                
                <div class="d-flex justify-content-between mb-3">
                    <span class="opacity-75">Subtotal</span>
                    <span class="fw-bold">K <?php echo number_format($grand_total, 2); ?></span>
                </div>
                
                <div class="d-flex justify-content-between mb-4">
                    <span class="opacity-75">Estimated Delivery</span>
                    <span class="text-info fw-bold">FREE</span>
                </div>

                <div class="pt-3 border-top mb-4" style="border-color: rgba(255,255,255,0.2) !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fs-5 fw-bold">Total</span>
                        <span class="fs-2 fw-bolder">K <?php echo number_format($grand_total, 2); ?></span>
                    </div>
                    <p class="small opacity-50 mt-1">VAT and local taxes included</p>
                </div>

                <a href="checkout.php?bid=<?php echo $branch_id; ?>" class="btn-checkout text-center text-decoration-none d-block <?php echo ($grand_total <= 0) ? 'disabled opacity-50' : ''; ?>">
                    PROCEED TO CHECKOUT <i class="mdi mdi-chevron-right ms-2"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function changeQty(btn, delta) {
    let input = btn.parentNode.querySelector('.qty-input');
    let currentVal = parseInt(input.value);
    if (!isNaN(currentVal)) {
        let newVal = currentVal + delta;
        if (newVal >= 1) {
            input.value = newVal;
        }
    }
}
</script>

</body>
</html>
