<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

/*
 * IMPORTANT:
 * This page deliberately uses the EXISTING store_header.php.
 * Do not add another header/navigation here.
 */
if (file_exists(__DIR__ . '/store_header.php')) {
    require_once __DIR__ . '/store_header.php';
} elseif (file_exists(__DIR__ . '/api/store_header.php')) {
    require_once __DIR__ . '/api/store_header.php';
} else {
    die('Error: store_header.php could not be found.');
}

$branch_id = (int)($_SESSION['current_branch_id'] ?? $_SESSION['branch_id'] ?? 0);

if (isset($_GET['bid']) && (int)$_GET['bid'] > 0) {
    $branch_id = (int)$_GET['bid'];
    $_SESSION['current_branch_id'] = $branch_id;
}

if ($branch_id <= 0) {
    $branch_id = 10;
    $_SESSION['current_branch_id'] = $branch_id;
}

if (empty($_SESSION['online_cart_csrf'])) {
    $_SESSION['online_cart_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['online_cart_csrf'];

$cart = $_SESSION['carts'][$branch_id] ?? [];
if (!is_array($cart)) {
    $cart = [];
}

$count = 0;
$subtotal = 0.00;

foreach ($cart as $item) {
    $qty = max(1, (int)($item['qty'] ?? 1));
    $price = (float)($item['price'] ?? 0);
    $count += $qty;
    $subtotal += ($price * $qty);
}

$subtotal = round($subtotal, 2);

/* Customer prefill */
$client_id = (int)($_SESSION['client_id'] ?? 0);
$customer_name = (string)($_SESSION['client_name'] ?? '');
$customer_phone = '';
$customer_address = '';

if ($client_id > 0) {
    $stmt = $conn->prepare("
        SELECT full_name, phone, email
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if ($customer_name === '') {
            $customer_name = (string)($client['full_name'] ?? '');
        }

        $customer_phone = (string)($client['phone'] ?? '');
    }

    $pharmacy_id = (int)($parent_pharmacy_id ?? 0);

    if ($pharmacy_id > 0) {
        $stmt = $conn->prepare("
            SELECT name, phone, address
            FROM customers
            WHERE client_id = ?
              AND pharmacy_id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('iii', $client_id, $pharmacy_id, $branch_id);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            if (!empty($customer['name'])) {
                $customer_name = (string)$customer['name'];
            }
            if (!empty($customer['phone'])) {
                $customer_phone = (string)$customer['phone'];
            }
            $customer_address = (string)($customer['address'] ?? '');
        }
    }
}
?>

<style>
/* Cart-only styles. Existing store_header.php is untouched. */
.bige-cart-page{background:#f6f8fa;min-height:calc(100vh - 160px);padding:26px 0 65px}
.bige-cart-wrap{width:min(1180px,calc(100% - 26px));margin:auto}
.bige-cart-heading{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:22px}
.bige-cart-heading h1{margin:4px 0;color:#003339;font-size:30px;font-weight:800}
.bige-cart-heading p{margin:0;color:#6d7880}
.bige-cart-eyebrow{color:#00b386;font-size:11px;font-weight:900;letter-spacing:.13em}
.bige-cart-back{display:inline-flex;align-items:center;gap:7px;padding:11px 15px;background:#fff;border:1px solid #e1e6e9;border-radius:10px;color:#003339;text-decoration:none;font-weight:800}
.bige-cart-layout{display:grid;grid-template-columns:minmax(0,1fr) 350px;gap:20px;align-items:start}
.bige-cart-card{background:#fff;border:1px solid #e3e8eb;border-radius:16px;box-shadow:0 6px 24px rgba(0,51,57,.06);overflow:hidden}
.bige-cart-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #edf0f2}
.bige-cart-head strong{color:#003339}
.bige-cart-clear{border:0;background:none;color:#c0392b;font-weight:800;cursor:pointer}
.bige-cart-clear:disabled{opacity:.4;cursor:not-allowed}
.bige-cart-item{display:grid;grid-template-columns:76px minmax(0,1fr) auto auto auto;gap:15px;align-items:center;padding:17px 18px;border-bottom:1px solid #edf0f2}
.bige-cart-item:last-child{border-bottom:0}
.bige-cart-thumb{width:76px;height:76px;border-radius:12px;background:#f0f7f5;display:grid;place-items:center;overflow:hidden}
.bige-cart-thumb img{width:100%;height:100%;object-fit:contain}
.bige-cart-thumb i{font-size:29px;color:#00b386}
.bige-cart-product h3{margin:0 0 4px;color:#003339;font-size:15px;font-weight:800}
.bige-cart-product small{display:block;margin-bottom:7px;color:#7b858c}
.bige-cart-price{color:#00a878;font-weight:800}
.bige-cart-qty{display:flex;align-items:center;border:1px solid #dce3e6;border-radius:10px;overflow:hidden}
.bige-cart-qty button{width:36px;height:38px;border:0;background:#f4f7f8;color:#003339;font-size:20px;cursor:pointer}
.bige-cart-qty button:hover{background:#e7f2ef}
.bige-cart-qty input{width:45px;height:38px;border:0;outline:0;text-align:center;font-weight:800;color:#003339}
.bige-cart-line{font-weight:900;color:#003339;white-space:nowrap}
.bige-cart-remove{border:0;background:none;color:#c0392b;font-weight:700;cursor:pointer}
.bige-cart-summary{padding:20px;position:sticky;top:18px}
.bige-cart-summary h2{margin:0 0 18px;color:#003339;font-size:20px}
.bige-cart-row{display:flex;justify-content:space-between;gap:12px;margin:12px 0;color:#68747c}
.bige-cart-row strong{color:#003339}
.bige-cart-total{border-top:1px solid #e4e9ec;padding-top:15px;margin-top:16px;font-size:19px}
.bige-cart-checkout{width:100%;margin-top:18px;padding:14px;border:0;border-radius:11px;background:#00b386;color:#fff;font-weight:900;cursor:pointer}
.bige-cart-checkout:hover{background:#009b75}
.bige-cart-checkout:disabled{opacity:.45;cursor:not-allowed}
.bige-cart-note{margin-top:12px;color:#78838a;font-size:12px;line-height:1.5}
.bige-cart-empty{text-align:center;padding:68px 20px}
.bige-cart-empty-icon{width:70px;height:70px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#e8f7f2;color:#00b386;font-size:32px}
.bige-cart-empty h2{margin:0 0 7px;color:#003339}
.bige-cart-empty p{margin:0 0 18px;color:#77828a}
.bige-cart-shop{display:inline-flex;gap:7px;align-items:center;padding:12px 18px;background:#00b386;color:#fff;border-radius:10px;text-decoration:none;font-weight:800}
.bige-cart-toast{position:fixed;right:18px;bottom:18px;z-index:12000;padding:13px 16px;border-radius:10px;background:#003339;color:#fff;box-shadow:0 12px 35px rgba(0,0,0,.2);opacity:0;transform:translateY(10px);pointer-events:none;transition:.2s}
.bige-cart-toast.show{opacity:1;transform:none}
.bige-cart-modal{position:fixed;inset:0;z-index:11999;display:none;align-items:center;justify-content:center;padding:18px}
.bige-cart-modal.open{display:flex}
.bige-cart-backdrop{position:absolute;inset:0;background:rgba(0,30,34,.58)}
.bige-cart-dialog{position:relative;width:min(540px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 25px 80px rgba(0,0,0,.25)}
.bige-cart-close{position:absolute;right:14px;top:12px;width:36px;height:36px;border:0;border-radius:50%;background:#f0f3f4;font-size:22px;cursor:pointer}
.bige-cart-dialog h2{margin:5px 45px 6px;color:#003339}
.bige-cart-intro{color:#748088;font-size:14px;margin:0 0 20px}
.bige-cart-field{margin:14px 0}
.bige-cart-field label{display:block;margin-bottom:7px;color:#003339;font-size:13px;font-weight:800}
.bige-cart-field input,.bige-cart-field textarea,.bige-cart-field select{width:100%;padding:12px 13px;border:1px solid #d7dfe2;border-radius:10px;outline:0;font:inherit}
.bige-cart-field input:focus,.bige-cart-field textarea:focus,.bige-cart-field select:focus{border-color:#00b386;box-shadow:0 0 0 3px rgba(0,179,134,.1)}
.bige-cart-message{display:none;margin-top:13px;padding:11px;border-radius:9px;background:#fff0f1;color:#a51d2a;font-size:14px}
.bige-cart-message.show{display:block}
.bige-cart-success{display:none;text-align:center;padding:15px 4px}
.bige-cart-success.show{display:block}
.bige-cart-success-icon{width:64px;height:64px;margin:5px auto 14px;border-radius:50%;display:grid;place-items:center;background:#e7f8f2;color:#00b386;font-size:30px}
.bige-cart-order-no{font-size:19px;font-weight:900;color:#003339;margin:10px 0}
.bige-cart-success p{color:#748088;line-height:1.5}
.bige-cart-actions{display:flex;justify-content:center;gap:10px;margin-top:18px}
.bige-cart-actions a{padding:11px 15px;border-radius:10px;text-decoration:none;font-weight:800}
.bige-cart-primary{background:#00b386;color:#fff}
.bige-cart-secondary{background:#edf1f3;color:#003339}
.bige-confirm{position:fixed;inset:0;z-index:13000;display:none;align-items:center;justify-content:center;padding:18px}
.bige-confirm.open{display:flex}
.bige-confirm-bg{position:absolute;inset:0;background:rgba(0,0,0,.52)}
.bige-confirm-box{position:relative;width:min(420px,100%);padding:24px;background:#fff;border-radius:16px;text-align:center;box-shadow:0 20px 70px rgba(0,0,0,.25)}
.bige-confirm-icon{width:55px;height:55px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:#fff3e8;color:#d97706;font-size:26px}
.bige-confirm-box h3{margin:0 0 7px;color:#003339}.bige-confirm-box p{margin:0;color:#758088}
.bige-confirm-actions{display:flex;justify-content:center;gap:10px;margin-top:20px}
.bige-confirm-actions button{padding:11px 18px;border:0;border-radius:9px;font-weight:800;cursor:pointer}
.bige-confirm-cancel{background:#edf1f3;color:#003339}.bige-confirm-danger{background:#c0392b;color:#fff}
@media(max-width:800px){.bige-cart-page{padding:20px 0 50px}.bige-cart-wrap{width:calc(100% - 20px)}.bige-cart-heading{align-items:flex-start;flex-direction:column}.bige-cart-back{width:100%;justify-content:center}.bige-cart-layout{grid-template-columns:1fr}.bige-cart-summary{position:static}.bige-cart-item{grid-template-columns:60px minmax(0,1fr) auto;gap:11px;padding:14px 12px}.bige-cart-thumb{width:60px;height:60px}.bige-cart-qty{grid-column:2}.bige-cart-line{grid-column:3;grid-row:1}.bige-cart-remove{grid-column:3;grid-row:2}}
@media(max-width:430px){.bige-cart-heading h1{font-size:25px}.bige-cart-dialog{padding:20px 16px}.bige-cart-actions,.bige-confirm-actions{flex-direction:column}.bige-cart-actions a,.bige-confirm-actions button{width:100%;text-align:center}}
</style>

<main class="bige-cart-page">
    <div class="bige-cart-wrap">

        <div class="bige-cart-heading">
            <div>
                <div class="bige-cart-eyebrow">ONLINE PHARMACY</div>
                <h1>Your Shopping Cart</h1>
                <p id="cartSubtitle"><?= $count ?> item<?= $count === 1 ? '' : 's' ?> ready for checkout</p>
            </div>

            <a class="bige-cart-back" href="online_store.php?bid=<?= $branch_id ?>">
                <i class="mdi mdi-arrow-left"></i>
                Continue Shopping
            </a>
        </div>

        <div class="bige-cart-layout">

            <section class="bige-cart-card">
                <div class="bige-cart-head">
                    <strong><i class="mdi mdi-cart-outline me-1"></i> Cart Items</strong>
                    <button type="button" id="clearCartBtn" class="bige-cart-clear" <?= !$cart ? 'disabled' : '' ?>>Clear Cart</button>
                </div>

                <div id="cartItems">
                <?php if (!$cart): ?>
                    <div class="bige-cart-empty">
                        <div class="bige-cart-empty-icon"><i class="mdi mdi-cart-outline"></i></div>
                        <h2>Your cart is empty</h2>
                        <p>Browse the pharmacy and add the medicines you need.</p>
                        <a class="bige-cart-shop" href="online_store.php?bid=<?= $branch_id ?>">
                            <i class="mdi mdi-store-outline"></i> Start Shopping
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($cart as $item):
                        $id = (int)($item['id'] ?? 0);
                        $qty = max(1, (int)($item['qty'] ?? 1));
                        $price = (float)($item['price'] ?? 0);
                        $image = (string)($item['image'] ?? '');
                        $name = (string)($item['name'] ?? 'Product');
                        $strength = (string)($item['strength'] ?? '');
                    ?>
                        <article class="bige-cart-item" data-id="<?= $id ?>">
                            <div class="bige-cart-thumb">
                                <?php if ($image): ?>
                                    <img src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                                <?php endif; ?>
                                <i style="<?= $image ? 'display:none' : '' ?>" class="mdi mdi-pill"></i>
                            </div>

                            <div class="bige-cart-product">
                                <h3><?= htmlspecialchars($name, ENT_QUOTES) ?></h3>
                                <?php if ($strength): ?><small><?= htmlspecialchars($strength, ENT_QUOTES) ?></small><?php endif; ?>
                                <div class="bige-cart-price">K <?= number_format($price, 2) ?></div>
                            </div>

                            <div class="bige-cart-qty">
                                <button type="button" data-action="minus">−</button>
                                <input type="number" min="1" value="<?= $qty ?>">
                                <button type="button" data-action="plus">+</button>
                            </div>

                            <strong class="bige-cart-line">K <?= number_format($price * $qty, 2) ?></strong>
                            <button type="button" class="bige-cart-remove" data-action="remove">Remove</button>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </section>

            <aside class="bige-cart-card bige-cart-summary">
                <h2>Order Summary</h2>

                <div class="bige-cart-row">
                    <span>Items</span>
                    <strong id="cartCount"><?= $count ?></strong>
                </div>

                <div class="bige-cart-row">
                    <span>Subtotal</span>
                    <strong id="cartSubtotal">K <?= number_format($subtotal, 2) ?></strong>
                </div>

                <div class="bige-cart-row">
                    <span>Delivery</span>
                    <span>Confirmed by pharmacy</span>
                </div>

                <div class="bige-cart-row bige-cart-total">
                    <strong>Total</strong>
                    <strong id="cartTotal">K <?= number_format($subtotal, 2) ?></strong>
                </div>

                <button type="button" id="checkoutBtn" class="bige-cart-checkout" <?= !$cart ? 'disabled' : '' ?>>
                    <i class="mdi mdi-lock-check-outline me-1"></i> Proceed to Checkout
                </button>

                <div class="bige-cart-note">
                    Stock and current online price are checked again on the server before your order is created.
                </div>
            </aside>

        </div>
    </div>
</main>

<div id="cartToast" class="bige-cart-toast"></div>

<div id="checkoutModal" class="bige-cart-modal" aria-hidden="true">
    <div class="bige-cart-backdrop" data-close-cart></div>

    <section class="bige-cart-dialog">
        <button type="button" class="bige-cart-close" data-close-cart>×</button>

        <div id="checkoutView">
            <div class="bige-cart-eyebrow">COMPLETE YOUR ORDER</div>
            <h2>Delivery Details</h2>
            <p class="bige-cart-intro">Enter where you want the pharmacy to deliver your order.</p>

            <form id="checkoutForm">
                <div class="bige-cart-field">
                    <label>Full Name</label>
                    <input type="text" name="customer_name" maxlength="120" required value="<?= htmlspecialchars($customer_name, ENT_QUOTES) ?>">
                </div>

                <div class="bige-cart-field">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" maxlength="40" required value="<?= htmlspecialchars($customer_phone, ENT_QUOTES) ?>">
                </div>

                <div class="bige-cart-field">
                    <label>Delivery Address</label>
                    <textarea name="address" rows="4" maxlength="500" required><?= htmlspecialchars($customer_address, ENT_QUOTES) ?></textarea>
                </div>

                <div class="bige-cart-field">
                    <label>Payment Method</label>
                    <select name="payment_method" required>
                        <option value="Cash on Delivery">Cash on Delivery</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank">Bank / Transfer</option>
                    </select>
                </div>

                <button type="submit" id="placeOrderBtn" class="bige-cart-checkout">
                    <i class="mdi mdi-check-circle-outline me-1"></i> Place Order
                </button>

                <div id="checkoutMessage" class="bige-cart-message"></div>
            </form>
        </div>

        <div id="successView" class="bige-cart-success">
            <div class="bige-cart-success-icon"><i class="mdi mdi-check"></i></div>
            <h2>Order Placed Successfully</h2>
            <div id="orderNumber" class="bige-cart-order-no"></div>
            <p>Your order has been sent to the pharmacy. You can follow it from your orders page.</p>

            <div class="bige-cart-actions">
                <a class="bige-cart-primary" href="client_orders.php">View My Orders</a>
                <a class="bige-cart-secondary" href="online_store.php?bid=<?= $branch_id ?>">Continue Shopping</a>
            </div>
        </div>
    </section>
</div>

<div id="clearConfirm" class="bige-confirm" aria-hidden="true">
    <div class="bige-confirm-bg"></div>
    <section class="bige-confirm-box">
        <div class="bige-confirm-icon"><i class="mdi mdi-cart-remove"></i></div>
        <h3>Clear Shopping Cart?</h3>
        <p>All items in this branch cart will be removed.</p>
        <div class="bige-confirm-actions">
            <button type="button" id="cancelClear" class="bige-confirm-cancel">Cancel</button>
            <button type="button" id="confirmClear" class="bige-confirm-danger">Clear Cart</button>
        </div>
    </section>
</div>

<script>
window.BIGE50_CART = {
    branchId: <?= json_encode($branch_id) ?>,
    csrf: <?= json_encode($csrf) ?>,
    api: <?= json_encode(
        file_exists(__DIR__ . '/cart_api.php')
            ? 'cart_api.php'
            : 'api/cart_api.php'
    ) ?>
};

(function () {
    'use strict';

    const C = window.BIGE50_CART;
    const $ = id => document.getElementById(id);

    const items = $('cartItems');
    const count = $('cartCount');
    const subtotal = $('cartSubtotal');
    const total = $('cartTotal');
    const subtitle = $('cartSubtitle');
    const clearBtn = $('clearCartBtn');
    const checkoutBtn = $('checkoutBtn');
    const toast = $('cartToast');

    const checkoutModal = $('checkoutModal');
    const checkoutView = $('checkoutView');
    const successView = $('successView');
    const checkoutForm = $('checkoutForm');
    const placeBtn = $('placeOrderBtn');
    const message = $('checkoutMessage');

    const clearConfirm = $('clearConfirm');
    const cancelClear = $('cancelClear');
    const confirmClear = $('confirmClear');

    let toastTimer;

    function money(n) {
        return 'K ' + Number(n || 0).toLocaleString('en-ZM', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;',
            "'":'&#39;','"':'&quot;'
        }[c]));
    }

    function notify(text, error = false) {
        toast.textContent = text;
        toast.style.background = error ? '#a51d2a' : '#003339';
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2800);
    }

    async function api(data) {
        data.bid = C.branchId;
        data.csrf = C.csrf;

        const response = await fetch(C.api, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: new URLSearchParams(data)
        });

        let result;
        try {
            result = await response.json();
        } catch (e) {
            throw new Error('The cart server returned an invalid response.');
        }

        if (!result.success) {
            const error = new Error(result.message || 'Cart request failed.');
            Object.assign(error, result);
            throw error;
        }

        return result;
    }

    function syncHeaderBadge(value) {
        document.querySelectorAll('.cart-badge, .cart-count').forEach(el => {
            el.textContent = value || 0;
        });
    }

    function emptyHtml() {
        return `
            <div class="bige-cart-empty">
                <div class="bige-cart-empty-icon"><i class="mdi mdi-cart-outline"></i></div>
                <h2>Your cart is empty</h2>
                <p>Browse the pharmacy and add the medicines you need.</p>
                <a class="bige-cart-shop" href="online_store.php?bid=${encodeURIComponent(C.branchId)}">
                    <i class="mdi mdi-store-outline"></i> Start Shopping
                </a>
            </div>
        `;
    }

    function render(result) {
        const list = Array.isArray(result.items) ? result.items : [];
        const n = Number(result.cart_count || 0);
        const sub = Number(result.subtotal || 0);
        const grand = Number(result.total ?? sub);

        count.textContent = n;
        subtotal.textContent = money(sub);
        total.textContent = money(grand);
        subtitle.textContent = `${n} item${n === 1 ? '' : 's'} ready for checkout`;

        clearBtn.disabled = !list.length;
        checkoutBtn.disabled = !list.length;

        syncHeaderBadge(n);

        if (!list.length) {
            items.innerHTML = emptyHtml();
            return;
        }

        items.innerHTML = list.map(x => {
            const id = Number(x.id || 0);
            const q = Math.max(1, Number(x.qty || 1));
            const p = Number(x.price || 0);
            const img = x.image || '';

            return `
                <article class="bige-cart-item" data-id="${id}">
                    <div class="bige-cart-thumb">
                        ${img ? `<img src="${esc(img)}" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='block'>` : ''}
                        <i style="${img ? 'display:none' : ''}" class="mdi mdi-pill"></i>
                    </div>

                    <div class="bige-cart-product">
                        <h3>${esc(x.name || 'Product')}</h3>
                        ${x.strength ? `<small>${esc(x.strength)}</small>` : ''}
                        <div class="bige-cart-price">${money(p)}</div>
                    </div>

                    <div class="bige-cart-qty">
                        <button type="button" data-action="minus">−</button>
                        <input type="number" min="1" value="${q}">
                        <button type="button" data-action="plus">+</button>
                    </div>

                    <strong class="bige-cart-line">${money(p * q)}</strong>
                    <button type="button" class="bige-cart-remove" data-action="remove">Remove</button>
                </article>
            `;
        }).join('');
    }

    /* Works with the EXACT add button used by online_store.php:
       .add-cart-js[data-id][data-branch].
       The existing online_store.php handler remains responsible for
       the initial add. This listener only provides a fallback when
       that handler is not present. */
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.bige-cart-add');
        if (!btn) return;

        const id = Number(btn.dataset.id || 0);
        if (!id) return;

        try {
            btn.disabled = true;
            const result = await api({
                action: 'add',
                item_id: id,
                qty: Number(btn.dataset.qty || 1)
            });
            render(result);
            notify('Item added to cart.');
        } catch (err) {
            notify(err.message, true);
        } finally {
            btn.disabled = false;
        }
    });

    items.addEventListener('click', async function (e) {
        const row = e.target.closest('.bige-cart-item');
        if (!row) return;

        const id = Number(row.dataset.id || 0);
        const actionButton = e.target.closest('[data-action]');
        if (!actionButton) return;

        try {
            const action = actionButton.dataset.action;

            if (action === 'remove') {
                render(await api({action:'remove', item_id:id}));
                notify('Item removed.');
                return;
            }

            if (action === 'plus' || action === 'minus') {
                const input = row.querySelector('input');
                let q = Math.max(1, parseInt(input.value || '1', 10));

                if (action === 'plus') q++;
                else q--;

                if (q <= 0) {
                    render(await api({action:'remove', item_id:id}));
                    notify('Item removed.');
                    return;
                }

                render(await api({action:'update', item_id:id, qty:q}));
                notify('Cart updated.');
            }
        } catch (err) {
            notify(err.message, true);
        }
    });

    items.addEventListener('change', async function (e) {
        if (!e.target.matches('.bige-cart-qty input')) return;

        const row = e.target.closest('.bige-cart-item');
        const id = Number(row?.dataset.id || 0);
        const q = Math.max(1, parseInt(e.target.value || '1', 10));

        try {
            render(await api({action:'update', item_id:id, qty:q}));
            notify('Cart updated.');
        } catch (err) {
            notify(err.message, true);
        }
    });

    clearBtn.addEventListener('click', function () {
        if (!clearBtn.disabled) {
            clearConfirm.classList.add('open');
            clearConfirm.setAttribute('aria-hidden', 'false');
        }
    });

    cancelClear.addEventListener('click', function () {
        clearConfirm.classList.remove('open');
        clearConfirm.setAttribute('aria-hidden', 'true');
    });

    confirmClear.addEventListener('click', async function () {
        confirmClear.disabled = true;

        try {
            render(await api({action:'clear'}));
            notify('Cart cleared.');
        } catch (err) {
            notify(err.message, true);
        } finally {
            confirmClear.disabled = false;
            clearConfirm.classList.remove('open');
            clearConfirm.setAttribute('aria-hidden', 'true');
        }
    });

    checkoutBtn.addEventListener('click', function () {
        if (checkoutBtn.disabled) return;

        checkoutView.style.display = 'block';
        successView.classList.remove('show');
        checkoutModal.classList.add('open');
        checkoutModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    });

    document.querySelectorAll('[data-close-cart]').forEach(el => {
        el.addEventListener('click', function () {
            checkoutModal.classList.remove('open');
            checkoutModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        });
    });

    checkoutForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        message.className = 'bige-cart-message';
        message.textContent = '';

        placeBtn.disabled = true;
        placeBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin me-1"></i> Placing Order...';

        try {
            const data = Object.fromEntries(new FormData(checkoutForm).entries());
            data.action = 'checkout';

            const result = await api(data);

            checkoutView.style.display = 'none';
            successView.classList.add('show');

            document.getElementById('orderNumber').textContent =
                result.order_number
                    ? 'Order #' + result.order_number
                    : 'Order submitted';

            render({
                items: [],
                cart_count: 0,
                subtotal: 0,
                total: 0
            });
        } catch (err) {
            if (err.login_required) {
                window.location.href =
                    'login_client.php?redirect=' +
                    encodeURIComponent('view_cart.php?bid=' + C.branchId);
                return;
            }

            message.textContent = err.message;
            message.className = 'bige-cart-message show';
        } finally {
            placeBtn.disabled = false;
            placeBtn.innerHTML =
                '<i class="mdi mdi-check-circle-outline me-1"></i> Place Order';
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        checkoutModal.classList.remove('open');
        clearConfirm.classList.remove('open');
        document.body.style.overflow = '';
    });
})();
</script>
