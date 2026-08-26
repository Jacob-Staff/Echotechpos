<?php
/**
 * BIGE50 ONLINE PHARMACY STORE
 * Branch-safe, Zambia Standard Time, and uses cart.php as the ONLY cart endpoint.
 * Keep the existing store_header.php â€” this page does not create another header.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

/* ---------------------------------------------------------
   DATABASE
--------------------------------------------------------- */
$store_conn_file = realpath(__DIR__ . '/../includes/conn.php');
if (!$store_conn_file) {
    $store_conn_file = realpath(__DIR__ . '/includes/conn.php');
}
if (!$store_conn_file || !is_file($store_conn_file)) {
    http_response_code(500);
    die('Database connection file (conn.php) not found.');
}

require_once $store_conn_file;

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection unavailable.');
}

/* ---------------------------------------------------------
   EXISTING STORE HEADER
--------------------------------------------------------- */
$header_candidates = [
    __DIR__ . '/store_header.php',
    __DIR__ . '/api/store_header.php',
];

$header_loaded = false;

foreach ($header_candidates as $header_file) {
    if (is_file($header_file)) {
        require_once $header_file;
        $header_loaded = true;
        break;
    }
}

if (!$header_loaded) {
    die('Error: store_header.php could not be found.');
}

/* ---------------------------------------------------------
   PATHS
--------------------------------------------------------- */
$is_in_api_folder = basename(__DIR__) === 'api';
$path_prefix      = $is_in_api_folder ? '' : 'api/';
$store_root       = $is_in_api_folder ? dirname(__DIR__) : __DIR__;
$web_root_prefix  = $is_in_api_folder ? '../' : '';

/* ---------------------------------------------------------
   BRANCH CONTEXT
   ?bid= is accepted only for an active branch.
   If a branch is already selected, another pharmacy cannot
   be injected through the URL.
--------------------------------------------------------- */
$requested_branch  = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
$current_branch_id = (int)($_SESSION['current_branch_id'] ?? 0);
$current_pharmacy_id = 0;

if ($current_branch_id > 0) {
    $stmt = $conn->prepare(
        "SELECT pharmacy_id
         FROM branches
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $current_branch_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $current_pharmacy_id = (int)($row['pharmacy_id'] ?? 0);
    }
}

if ($requested_branch > 0) {
    if ($current_pharmacy_id > 0) {
        $stmt = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ?
               AND pharmacy_id = ?
               AND is_active = 1
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $requested_branch, $current_pharmacy_id);
            $stmt->execute();
            $valid = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($valid) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    } else {
        $stmt = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ? AND is_active = 1
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('i', $requested_branch);
            $stmt->execute();
            $valid = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($valid) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    }
}

$branch_id = (int)($_SESSION['current_branch_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT id, pharmacy_id, branch_name
     FROM branches
     WHERE id = ? AND is_active = 1
     LIMIT 1"
);

if (!$stmt) {
    die('Unable to verify the selected branch.');
}

$stmt->bind_param('i', $branch_id);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$branch) {
    die(
        '<div style="font-family:Arial;padding:30px;text-align:center">
            No active pharmacy branch was selected.
         </div>'
    );
}

$pharmacy_id = (int)$branch['pharmacy_id'];
$branch_name = (string)$branch['branch_name'];
$zambia_today = date('Y-m-d');

/* ---------------------------------------------------------
   SEARCH / CATEGORY FILTERS
--------------------------------------------------------- */
$category_filter = trim((string)($_GET['cat'] ?? ''));
$search_query    = trim((string)($_GET['q'] ?? ''));
$search_type     = (($_GET['type'] ?? 'all') === 'rx') ? 'rx' : 'all';

$where = "
    WHERE si.pharmacy_id = ?
      AND si.branch_id = ?
      AND si.is_active = 1
      AND si.is_online = 1
      AND si.quantity > 0
";

$params = [$pharmacy_id, $branch_id];
$types  = 'ii';

if ($category_filter !== '') {
    $where .= " AND si.category = ?";
    $params[] = $category_filter;
    $types .= 's';
}

if ($search_query !== '') {
    $where .= "
        AND (
            si.item_name LIKE ?
            OR si.barcode LIKE ?
            OR si.strength LIKE ?
            OR si.category LIKE ?
        )
    ";

    $like = '%' . $search_query . '%';

    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($search_type === 'rx') {
    $where .= "
        AND (
            LOWER(COALESCE(si.description, '')) LIKE '%prescription%'
            OR LOWER(COALESCE(si.description, '')) LIKE '%rx%'
        )
    ";
}

$sql = "
    SELECT
        si.id,
        si.item_name,
        si.strength,
        si.category,
        si.price,
        si.online_price,
        si.quantity,
        si.expiry_date,
        si.image,
        si.product_image
    FROM store_items si
    $where
    ORDER BY si.item_name ASC
    LIMIT 250
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to load online inventory.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$raw_items = $stmt->get_result();

/* ---------------------------------------------------------
   EXPIRY SAFETY
   Never compare expiry_date inside MySQL. Legacy pharmacy
   databases may contain 0000-00-00, which strict MySQL can
   reject during date comparisons. We validate it in PHP.
--------------------------------------------------------- */
function bige_store_expiry_ok($expiry): bool
{
    $value = trim((string)($expiry ?? ''));

    if ($value === '' || $value === '0000-00-00') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10));
    if (!$date || $date->format('Y-m-d') !== substr($value, 0, 10)) {
        return true;
    }

    return $date->format('Y-m-d') >= $zambia_today;
}

$items = [];
if ($raw_items) {
    while ($candidate = $raw_items->fetch_assoc()) {
        if (!bige_store_expiry_ok($candidate['expiry_date'] ?? null)) {
            continue;
        }

        $items[] = $candidate;

        if (count($items) >= 24) {
            break;
        }
    }
}

$stmt->close();

/* ---------------------------------------------------------
   HELPERS
--------------------------------------------------------- */
function big50_store_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function big50_store_money($value): string
{
    return 'K ' . number_format((float)$value, 2);
}

/* ---------------------------------------------------------
   CART BADGE â€” read session directly so it is correct even
   before the first AJAX request.
--------------------------------------------------------- */
$cart = $_SESSION['carts'][$branch_id] ?? [];
$cart_count = 0;

/* cart.php uses the same session token for all cart POST requests */
if (empty($_SESSION['online_cart_csrf'])) {
    $_SESSION['online_cart_csrf'] = bin2hex(random_bytes(32));
}
$cart_csrf = (string)$_SESSION['online_cart_csrf'];

if (is_array($cart)) {
    foreach ($cart as $cart_item) {
        $cart_count += max(0, (int)($cart_item['qty'] ?? 0));
    }
}
?>

<style>
.store-main{min-height:50vh}
.transition-hover{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.transition-hover:hover{transform:translateY(-3px);box-shadow:0 10px 20px rgba(0,0,0,.08)!important;border-color:#00b386!important}
.product-link{text-decoration:none;color:inherit;display:block}
.product-link:hover .product-title{color:#00a878}
.product-card{min-height:100%;transition:.2s ease}
.product-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(0,0,0,.08)!important}
.product-image-wrap{height:115px;display:flex;align-items:center;justify-content:center;background:#fafafa;border-radius:10px;overflow:hidden}
.product-image{max-height:105px;max-width:100%;object-fit:contain}
.product-title{min-height:34px;line-height:1.2}
.product-price-old{font-size:.76rem}
.offer-badge{font-size:10px}
.stock-badge{font-size:10px}
.store-empty{border:1px dashed #ced4da;border-radius:14px;background:#fff}
.store-result-count{font-size:12px;color:#6c757d}
.add-cart-js{position:relative;z-index:5}
.add-cart-js:disabled{opacity:.75;cursor:wait}
.bige-store-toast{
    position:fixed;
    top:85px;
    right:16px;
    z-index:9999;
    width:min(360px,calc(100vw - 32px));
    pointer-events:none;
}
.bige-store-toast .toast-box{
    pointer-events:auto;
    background:#003339;
    color:#fff;
    border-radius:12px;
    padding:13px 15px;
    margin-bottom:8px;
    box-shadow:0 12px 30px rgba(0,0,0,.18);
    animation:bigeToastIn .2s ease;
}
.bige-store-toast .toast-box.error{background:#a51d2a}
.bige-store-toast .toast-box.success{background:#008f70}
.bige-store-toast .toast-title{font-weight:800;font-size:14px}
.bige-store-toast .toast-message{font-size:12px;opacity:.92;margin-top:2px}
@keyframes bigeToastIn{
    from{opacity:0;transform:translateY(-8px)}
    to{opacity:1;transform:none}
}
@media(max-width:576px){
    .feature-card{padding:.75rem!important}
    .feature-card i{font-size:1.5rem!important;margin-right:.5rem!important}
    .feature-title{font-size:11px!important}
    .feature-sub{font-size:9px!important}
    .product-title{font-size:11px!important}
    .product-price{font-size:.9rem!important}
    .product-image-wrap{height:92px}
    .product-image{max-height:84px}
}
</style>

<div class="store-main">

    <!-- Action banners -->
    <div class="container mt-3 mt-md-4">
        <div class="row g-2 g-md-3">

            <div class="col-6 col-md-3">
                <a href="<?= big50_store_e($path_prefix) ?>upload_prescription.php?bid=<?= $branch_id ?>"
                   class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-prescription text-success fs-2 me-3"></i>
                        <div>
                            <h6 class="feature-title mb-0 fw-bold text-dark">Upload Prescriptions</h6>
                            <small class="feature-sub text-muted">Easy &amp; Fast</small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3">
                <a href="<?= big50_store_e($path_prefix) ?>lab_results.php?bid=<?= $branch_id ?>"
                   class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-flask-outline text-primary fs-2 me-3"></i>
                        <div>
                            <h6 class="feature-title mb-0 fw-bold text-dark">Lab Test Results</h6>
                            <small class="feature-sub text-muted">Secure Access</small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3">
                <a href="https://wa.me/<?= preg_replace('/\D+/', '', (string)($phone ?? '')) ?>?text=I%20need%20a%20fast%20delivery"
                   target="_blank" rel="noopener" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-truck-fast-outline text-warning fs-2 me-3"></i>
                        <div>
                            <h6 class="feature-title mb-0 fw-bold text-dark">Delivery in 2 HRS</h6>
                            <small class="feature-sub text-muted">Within Lusaka</small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3">
                <a href="https://wa.me/<?= preg_replace('/\D+/', '', (string)($phone ?? '')) ?>?text=Do%20you%20accept%20insurance"
                   target="_blank" rel="noopener" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-shield-check-outline text-danger fs-2 me-3"></i>
                        <div>
                            <h6 class="feature-title mb-0 fw-bold text-dark">Health Insurance</h6>
                            <small class="feature-sub text-muted">Co-pay Support</small>
                        </div>
                    </div>
                </a>
            </div>

        </div>
    </div>

    <!-- Products -->
    <div class="container my-4 my-md-5">

        <div class="d-flex justify-content-between align-items-end mb-3 mb-md-4 gap-2">
            <div>
                <h4 class="fw-bold mb-1 fs-5 fs-md-4">Shop Medicines</h4>
                <div class="store-result-count">
                    <?= count($items) ?>
                    product<?= count($items) === 1 ? '' : 's' ?>
                    available in <?= big50_store_e($branch_name) ?>

                    <?php if ($category_filter !== ''): ?>
                        Â· <?= big50_store_e($category_filter) ?>
                    <?php endif; ?>

                    <?php if ($search_query !== ''): ?>
                        Â· â€œ<?= big50_store_e($search_query) ?>â€
                    <?php endif; ?>
                </div>
            </div>

            <a href="<?= big50_store_e($path_prefix) ?>all_products.php?bid=<?= $branch_id ?>"
               class="text-primary fw-bold text-decoration-none small">
                VIEW ALL
            </a>
        </div>

        <div class="row g-2 g-md-3" id="product-grid">

            <?php if (count($items) > 0): ?>

                <?php foreach ($items as $row): ?>

                    <?php
                    $normal_price = (float)$row['price'];
                    $online_price = (float)($row['online_price'] ?? 0);
                    $has_offer = $online_price > 0 && $online_price < $normal_price;
                    $display_price = $has_offer ? $online_price : $normal_price;

                    $img_name = !empty($row['image'])
                        ? $row['image']
                        : (!empty($row['product_image'])
                            ? $row['product_image']
                            : 'default_med.png');

                    $local_path = $store_root . '/uploads/products/' . $img_name;
                    $web_path = $web_root_prefix . 'uploads/products/' . rawurlencode($img_name);

                    if (!is_file($local_path)) {
                        $web_path = $web_root_prefix . 'assets/img/default_med.png';
                    }
                    ?>

                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">

                        <div class="product-card shadow-sm h-100 d-flex flex-column justify-content-between p-2 p-md-3 bg-white border rounded">

                            <a href="<?= big50_store_e($path_prefix) ?>product_details.php?id=<?= (int)$row['id'] ?>&bid=<?= $branch_id ?>"
                               class="product-link">

                                <div class="position-relative mb-2">

                                    <?php if ($has_offer): ?>
                                        <span class="badge bg-danger position-absolute top-0 start-0 offer-badge">
                                            OFFER
                                        </span>
                                    <?php endif; ?>

                                    <div class="product-image-wrap">
                                        <img
                                            src="<?= big50_store_e($web_path) ?>"
                                            class="product-image"
                                            alt="<?= big50_store_e($row['item_name']) ?>"
                                            loading="lazy"
                                            onerror="this.onerror=null;this.src='<?= big50_store_e($web_root_prefix . 'assets/img/default_med.png') ?>';"
                                        >
                                    </div>
                                </div>

                                <div>
                                    <div class="product-title fw-bold" style="font-size:13px;">
                                        <?= big50_store_e($row['item_name']) ?>

                                        <?php if (!empty($row['strength'])): ?>
                                            <br>
                                            <span class="text-muted small fw-normal">
                                                <?= big50_store_e($row['strength']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($row['category'])): ?>
                                            <br>
                                            <span class="text-muted small fw-normal">
                                                <?= big50_store_e($row['category']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($has_offer): ?>
                                        <div class="product-price text-success fw-bold mt-1">
                                            <?= big50_store_money($display_price) ?>
                                        </div>
                                        <div class="product-price-old text-muted text-decoration-line-through">
                                            <?= big50_store_money($normal_price) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="product-price text-success fw-bold mt-1">
                                            <?= big50_store_money($display_price) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </a>

                            <!-- IMPORTANT: this is now handled by cart.php -->
                            <button
                                type="button"
                                class="add-to-cart-btn add-cart-js mt-2 w-100 btn btn-outline-success btn-sm"
                                data-id="<?= (int)$row['id'] ?>"
                                data-branch="<?= $branch_id ?>"
                            >
                                <i class="mdi mdi-cart-plus me-1"></i>
                                ADD
                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="col-12">
                    <div class="store-empty text-center py-5 px-3">
                        <i class="mdi mdi-pill-off fs-1 text-muted"></i>
                        <h6 class="fw-bold mt-3">No medicines found</h6>
                        <p class="text-muted mb-3">
                            There are no available online products matching your selection.
                        </p>

                        <?php if ($category_filter !== '' || $search_query !== ''): ?>
                            <a href="online_store.php?bid=<?= $branch_id ?>"
                               class="btn btn-outline-success btn-sm">
                                Clear Search
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<div id="bigeStoreToast" class="bige-store-toast"></div>

<a
    href="https://wa.me/<?= preg_replace('/\D+/', '', (string)($phone ?? '')) ?>"
    class="wa-sticky position-fixed bottom-0 end-0 m-3 btn btn-success rounded-circle shadow p-3"
    target="_blank"
    rel="noopener"
    style="z-index:1000;"
    aria-label="WhatsApp"
>
    <i class="mdi mdi-whatsapp fs-3"></i>
</a>

<?php
$footer_path = $is_in_api_folder
    ? dirname(__DIR__) . '/includes/footer.php'
    : __DIR__ . '/includes/footer.php';

if (is_file($footer_path)) {
    require $footer_path;
}
?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    'use strict';

    const branchId = <?= (int)$branch_id ?>;
    const cartEndpoint = 'cart.php';
    const cartCsrf = <?= json_encode($cart_csrf) ?>;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function toast(type, title, message) {
        const container = $('#bigeStoreToast');

        const box = $(`
            <div class="toast-box ${type === 'error' ? 'error' : 'success'}">
                <div class="toast-title"></div>
                <div class="toast-message"></div>
            </div>
        `);

        box.find('.toast-title').text(title);
        box.find('.toast-message').text(message);

        container.append(box);

        setTimeout(function () {
            box.fadeOut(180, function () {
                box.remove();
            });
        }, 3000);
    }

    function updateCartBadge(count) {
        const value = Number(count || 0);

        document.querySelectorAll('.cart-badge, .cart-count').forEach(function (el) {
            el.textContent = value;

            if (value > 0) {
                el.classList.remove('d-none');
                el.style.display = '';
            }
        });
    }

    $(document).on('click', '.add-cart-js', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const btn = $(this);
        const itemId = Number(btn.attr('data-id') || 0);
        const selectedBranch = Number(btn.attr('data-branch') || branchId);

        if (!itemId || !selectedBranch) {
            toast('error', 'Unable to add item', 'The product or branch information is invalid.');
            return;
        }

        if (btn.prop('disabled')) {
            return;
        }

        const original = btn.html();

        btn.prop('disabled', true).html(
            '<span class="spinner-border spinner-border-sm me-1"></span> Adding...'
        );

        $.ajax({
            url: cartEndpoint,
            method: 'POST',
            dataType: 'json',
            timeout: 15000,
            data: {
                action: 'add',
                item_id: itemId,
                branch_id: selectedBranch,
                qty: 1,
                csrf: cartCsrf
            }
        })
        .done(function (response) {

            if (!response || response.success !== true) {
                toast(
                    'error',
                    'Could not add product',
                    response && response.message
                        ? response.message
                        : 'The product could not be added.'
                );

                btn.html(original);
                return;
            }

            updateCartBadge(response.cart_count);

            btn
                .html('<i class="mdi mdi-check me-1"></i> ADDED')
                .removeClass('btn-outline-success')
                .addClass('btn-success text-white');

            toast(
                'success',
                'Added to Cart',
                response.message || 'The product was added successfully.'
            );
        })
        .fail(function (xhr) {

            console.error('BIGE50 cart request failed:', xhr.responseText);

            let message = 'Please try again.';

            try {
                const result = JSON.parse(xhr.responseText);
                if (result.message) {
                    message = result.message;
                }
            } catch (ignore) {}

            toast('error', 'Could not add product', message);
            btn.html(original);
        })
        .always(function () {

            setTimeout(function () {
                btn
                    .prop('disabled', false)
                    .html(original)
                    .removeClass('btn-success text-white')
                    .addClass('btn-outline-success');
            }, 1300);
        });
    });

})();
</script>

</body>
</html>
