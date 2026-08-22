<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Database Connection
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
}

// 2. Resolve Active Branch ID dynamically (checks GET parameter, then SESSION)
$branch_id = isset($_GET['bid']) && intval($_GET['bid']) > 0 
    ? intval($_GET['bid']) 
    : (isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 0);

// If still 0, grab the first active branch from database as a safe fallback
if ($branch_id === 0) {
    $b_query = $conn->query("SELECT id FROM branches WHERE is_active = 1 LIMIT 1");
    if ($b_query && $row = $b_query->fetch_assoc()) {
        $branch_id = intval($row['id']);
    }
}

$_SESSION['current_branch_id'] = $branch_id;

// Load store header
if (file_exists(__DIR__ . "/store_header.php")) {
    require_once __DIR__ . "/store_header.php";
} elseif (file_exists(__DIR__ . "/../store_header.php")) {
    require_once __DIR__ . "/../store_header.php";
}

// 3. Ensure Session structure exists
if (!isset($_SESSION['carts'])) {
    $_SESSION['carts'] = [];
}
if ($branch_id > 0 && !isset($_SESSION['carts'][$branch_id])) {
    $_SESSION['carts'][$branch_id] = [];
}

// 4. Handle Quantity Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    if (isset($_POST['qty']) && is_array($_POST['qty'])) {
        foreach ($_POST['qty'] as $id => $qty) {
            $id  = intval($id);
            $qty = intval($qty);
            if ($qty <= 0) {
                unset($_SESSION['carts'][$branch_id][$id]);
            } else {
                if (isset($_SESSION['carts'][$branch_id][$id])) {
                    $_SESSION['carts'][$branch_id][$id]['qty'] = $qty;
                }
            }
        }
    }
    header("Location: view_cart.php?bid=" . $branch_id);
    exit();
}

// 5. Handle Item Removal
if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    unset($_SESSION['carts'][$branch_id][$remove_id]);
    header("Location: view_cart.php?bid=" . $branch_id);
    exit();
}

$grand_total = 0;
$active_cart = ($branch_id > 0 && isset($_SESSION['carts'][$branch_id])) ? $_SESSION['carts'][$branch_id] : [];
$store_url = (basename(__DIR__) === 'api') ? "../online_store.php?bid=" . $branch_id : "online_store.php?bid=" . $branch_id;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shopping Cart</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root { --echo-teal: #003339; --echo-green: #00b386; --echo-bg: #f8fafc; }
        body { background-color: var(--echo-bg); font-family: 'Inter', sans-serif; }
        .cart-container { margin-top: 40px; margin-bottom: 60px; }
        .cart-card { border: none; border-radius: 15px; background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .cart-table thead { background: #f1f5f9; }
        .cart-table th { border: none; padding: 15px; font-size: 13px; text-transform: uppercase; color: #64748b; }
        .cart-table td { vertical-align: middle; padding: 20px 15px; }
        .product-name { font-weight: 700; color: var(--echo-teal); }
        .qty-group { width: 110px; display: flex; align-items: center; border: 1px solid #e2e8f0; border-radius: 8px; }
        .qty-btn { background: #fff; border: none; width: 35px; height: 35px; font-weight: bold; cursor: pointer; }
        .qty-input { width: 40px; border: none; text-align: center; font-size: 14px; font-weight: 600; }
        .summary-card { border: none; border-radius: 15px; background: var(--echo-teal); color: white; padding: 30px; }
        .btn-checkout { background: var(--echo-green); color: white; border: none; width: 100%; padding: 15px; border-radius: 10px; font-weight: 700; display: block; text-align: center; text-decoration: none; }
        .empty-cart-state { padding: 80px 20px; text-align: center; }
        .empty-cart-state i { font-size: 80px; color: #cbd5e1; display: block; margin-bottom: 20px; }
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
                                    $sub = floatval($item['price']) * intval($item['qty']);
                                    $grand_total += $sub;
                                ?>
                                <tr>
                                    <td>
                                        <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
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
                        <h4 class="fw-bold">Your cart is empty</h4>
                        <p class="text-muted">You haven't added any products to this branch cart yet.</p>
                        <a href="<?php echo $store_url; ?>" class="btn btn-success rounded-pill px-5 mt-3">Start Shopping</a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-4">
                <a href="<?php echo $store_url; ?>" class="text-decoration-none fw-bold text-muted">
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
                <div class="pt-3 border-top mb-4" style="border-color: rgba(255,255,255,0.2) !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fs-5 fw-bold">Total</span>
                        <span class="fs-2 fw-bolder">K <?php echo number_format($grand_total, 2); ?></span>
                    </div>
                </div>
                <a href="checkout.php?bid=<?php echo $branch_id; ?>" class="btn-checkout <?php echo ($grand_total <= 0) ? 'disabled opacity-50' : ''; ?>">
                    PROCEED TO CHECKOUT <i class="mdi mdi-chevron-right ms-2"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function changeQty(btn, delta) {
    let input = btn.parentNode.querySelector('.qty-input');
    let currentVal = parseInt(input.value);
    if (!isNaN(currentVal)) {
        let newVal = currentVal + delta;
        if (newVal >= 0) {
            input.value = newVal;
            document.getElementById('cartForm').submit();
        }
    }
}
</script>
</body>
</html>
