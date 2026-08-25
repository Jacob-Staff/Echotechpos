<?php
/**
 * ONLINE STORE
 * Multi-tenant + branch-safe public pharmacy storefront.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// BIGE/Pharmacy POS uses Zambia Standard Time (Africa/Lusaka).
date_default_timezone_set('Africa/Lusaka');
$zambia_today = date('Y-m-d');

// The header lives beside this file or inside /api/.
$header_candidates = [
    __DIR__ . '/store_header.php',
    __DIR__ . '/api/store_header.php',
];
$header_loaded = false;
foreach ($header_candidates as $header_file) {
    if (file_exists($header_file)) {
        require_once $header_file;
        $header_loaded = true;
        break;
    }
}
if (!$header_loaded) {
    die('Error: store_header.php could not be found.');
}

$is_in_api_folder = (basename(__DIR__) === 'api');
$path_prefix = $is_in_api_folder ? '' : 'api/';
$store_root = $is_in_api_folder ? dirname(__DIR__) : __DIR__;
$web_root_prefix = $is_in_api_folder ? '../' : '';

/*
 * ---------------------------------------------------------------
 * PUBLIC STORE BRANCH CONTEXT
 * ---------------------------------------------------------------
 * The shared store header already validates branch switching, but
 * this page validates it again so a visitor cannot bypass the
 * header by manually changing ?bid= in the URL.
 * ---------------------------------------------------------------
 */
$requested_branch  = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
$current_branch_id = isset($_SESSION['current_branch_id']) ? (int)$_SESSION['current_branch_id'] : 0;
$current_pharmacy_id = 0;

if ($current_branch_id > 0) {
    $current_ctx = $conn->prepare(
        "SELECT pharmacy_id
         FROM branches
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    if ($current_ctx) {
        $current_ctx->bind_param('i', $current_branch_id);
        $current_ctx->execute();
        $current_row = $current_ctx->get_result()->fetch_assoc();
        $current_ctx->close();
        $current_pharmacy_id = (int)($current_row['pharmacy_id'] ?? 0);
    }
}

if ($requested_branch > 0) {
    if ($current_pharmacy_id > 0) {
        $branch_check = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ?
               AND pharmacy_id = ?
               AND is_active = 1
             LIMIT 1"
        );
        if ($branch_check) {
            $branch_check->bind_param('ii', $requested_branch, $current_pharmacy_id);
            $branch_check->execute();
            $valid_branch = $branch_check->get_result()->fetch_assoc();
            $branch_check->close();
            if ($valid_branch) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    } else {
        // First branch selection for a public visitor.
        $branch_check = $conn->prepare(
            "SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        if ($branch_check) {
            $branch_check->bind_param('i', $requested_branch);
            $branch_check->execute();
            $valid_branch = $branch_check->get_result()->fetch_assoc();
            $branch_check->close();
            if ($valid_branch) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    }
}

$branch_id = isset($_SESSION['current_branch_id']) ? (int)$_SESSION['current_branch_id'] : 0;

// If the header resolved a branch, use it. Otherwise fail safely instead of
// displaying another tenant's products.
$branch_stmt = $conn->prepare("SELECT id, pharmacy_id, branch_name FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
if (!$branch_stmt) {
    die('Unable to verify the selected branch.');
}
$branch_stmt->bind_param('i', $branch_id);
$branch_stmt->execute();
$branch_row = $branch_stmt->get_result()->fetch_assoc();
$branch_stmt->close();

if (!$branch_row) {
    die('<div style="font-family:Arial;padding:30px;text-align:center">No active pharmacy branch was selected.</div>');
}

$pharmacy_id = (int)$branch_row['pharmacy_id'];
$branch_name = $branch_row['branch_name'];

// Search + category filters.
$category_filter = trim($_GET['cat'] ?? '');
$search_query = trim($_GET['q'] ?? '');
$search_type = ($_GET['type'] ?? 'all') === 'rx' ? 'rx' : 'all';

// IMPORTANT: never compare a DATE column directly to '0000-00-00'.
// The database contains legacy zero-dates and MySQL strict mode throws
// #1525 when that literal is used in a DATE comparison. Convert the
// stored value to text for the comparison instead. This also keeps the
// storefront date aligned with Zambia Standard Time.
$where = "WHERE si.pharmacy_id = ?
          AND si.branch_id = ?
          AND si.is_active = 1
          AND si.is_online = 1
          AND si.quantity > 0
          AND (
              si.expiry_date IS NULL
              OR LEFT(CAST(si.expiry_date AS CHAR), 10) = '0000-00-00'
              OR LEFT(CAST(si.expiry_date AS CHAR), 10) >= ?
          )";
$params = [$pharmacy_id, $branch_id, $zambia_today];
$types = 'iis';

if ($category_filter !== '') {
    $where .= " AND si.category = ?";
    $params[] = $category_filter;
    $types .= 's';
}

if ($search_query !== '') {
    $where .= " AND (si.item_name LIKE ? OR si.barcode LIKE ? OR si.strength LIKE ? OR si.category LIKE ?)";
    $like = '%' . $search_query . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

// Rx mode is intentionally conservative: only products with clinical
// information/description are treated as searchable Rx products when that
// information exists. The default 'all' view remains unchanged.
if ($search_type === 'rx') {
    $where .= " AND (LOWER(COALESCE(si.description,'')) LIKE '%prescription%'
               OR LOWER(COALESCE(si.description,'')) LIKE '%rx%')";
}

$product_sql = "SELECT
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
                LIMIT 24";

$p_stmt = $conn->prepare($product_sql);
if (!$p_stmt) {
    die('Unable to load online inventory.');
}
$p_stmt->bind_param($types, ...$params);
$p_stmt->execute();
$items = $p_stmt->get_result();

function store_e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function store_money($value): string {
    return 'K ' . number_format((float)$value, 2);
}
?>

<style>
    .store-main { min-height: 50vh; }
    .transition-hover { transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .transition-hover:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,.08)!important; border-color:#00b386!important; }
    .product-link { text-decoration:none; color:inherit; display:block; }
    .product-link:hover .product-title { color:#00b386; }
    .product-card { min-height:100%; transition:.2s ease; }
    .product-card:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(0,0,0,.08)!important; }
    .product-image-wrap { height:115px; display:flex; align-items:center; justify-content:center; background:#fafafa; border-radius:10px; overflow:hidden; }
    .product-image { max-height:105px; max-width:100%; object-fit:contain; }
    .product-title { min-height:34px; line-height:1.2; }
    .product-price-old { font-size:.76rem; }
    .offer-badge { font-size:10px; }
    .stock-badge { font-size:10px; }
    .store-empty { border:1px dashed #ced4da; border-radius:14px; background:#fff; }
    .store-result-count { font-size:12px; color:#6c757d; }
    .add-cart-js:disabled { opacity:.75; }
    @media (max-width:576px) {
        .feature-card { padding:.75rem!important; }
        .feature-card i { font-size:1.5rem!important; margin-right:.5rem!important; }
        .feature-title { font-size:11px!important; }
        .feature-sub { font-size:9px!important; }
        .product-title { font-size:11px!important; }
        .product-price { font-size:.9rem!important; }
        .product-image-wrap { height:92px; }
        .product-image { max-height:84px; }
    }
</style>

<div class="store-main">
    <!-- Action Banners -->
    <div class="container mt-3 mt-md-4">
        <div class="row g-2 g-md-3">
            <div class="col-6 col-md-3">
                <a href="<?php echo store_e($path_prefix); ?>upload_prescription.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-prescription text-success fs-2 me-3"></i>
                        <div><h6 class="feature-title mb-0 fw-bold text-dark">Upload Prescriptions</h6><small class="feature-sub text-muted">Easy &amp; Fast</small></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?php echo store_e($path_prefix); ?>lab_results.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-flask-outline text-primary fs-2 me-3"></i>
                        <div><h6 class="feature-title mb-0 fw-bold text-dark">Lab Test Results</h6><small class="feature-sub text-muted">Secure Access</small></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>?text=I%20need%20a%20fast%20delivery" target="_blank" rel="noopener" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-truck-fast-outline text-warning fs-2 me-3"></i>
                        <div><h6 class="feature-title mb-0 fw-bold text-dark">Delivery in 2 HRS</h6><small class="feature-sub text-muted">Within Lusaka</small></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>?text=Do%20you%20accept%20insurance" target="_blank" rel="noopener" class="text-decoration-none">
                    <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                        <i class="mdi mdi-shield-check-outline text-danger fs-2 me-3"></i>
                        <div><h6 class="feature-title mb-0 fw-bold text-dark">Health Insurance</h6><small class="feature-sub text-muted">Co-pay Support</small></div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Product Grid -->
    <div class="container my-4 my-md-5">
        <div class="d-flex justify-content-between align-items-end mb-3 mb-md-4 gap-2">
            <div>
                <h4 class="fw-bold mb-1 fs-5 fs-md-4">Shop Medicines</h4>
                <div class="store-result-count">
                    <?php echo (int)$items->num_rows; ?> product<?php echo $items->num_rows === 1 ? '' : 's'; ?> available in <?php echo store_e($branch_name); ?>
                    <?php if ($category_filter !== ''): ?> Â· <?php echo store_e($category_filter); ?><?php endif; ?>
                    <?php if ($search_query !== ''): ?> Â· â€œ<?php echo store_e($search_query); ?>â€<?php endif; ?>
                </div>
            </div>
            <a href="<?php echo store_e($path_prefix); ?>all_products.php?bid=<?php echo $branch_id; ?>" class="text-primary fw-bold text-decoration-none small">VIEW ALL</a>
        </div>

        <div class="row g-2 g-md-3" id="product-grid">
            <?php if ($items && $items->num_rows > 0): ?>
                <?php while ($row = $items->fetch_assoc()):
                    $normal_price = (float)$row['price'];
                    $online_price = (float)($row['online_price'] ?? 0);
                    $has_offer = $online_price > 0 && $online_price < $normal_price;
                    $display_price = $has_offer ? $online_price : $normal_price;

                    $img_name = !empty($row['image']) ? $row['image'] : (!empty($row['product_image']) ? $row['product_image'] : 'default_med.png');
                    $local_path = $store_root . '/uploads/products/' . $img_name;
                    $web_path = $web_root_prefix . 'uploads/products/' . rawurlencode($img_name);
                    if (!file_exists($local_path)) {
                        $web_path = $web_root_prefix . 'assets/img/default_med.png';
                    }
                ?>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                        <div class="product-card shadow-sm h-100 d-flex flex-column justify-content-between p-2 p-md-3 bg-white border rounded">
                            <a href="<?php echo store_e($path_prefix); ?>product_details.php?id=<?php echo (int)$row['id']; ?>&bid=<?php echo $branch_id; ?>" class="product-link">
                                <div class="position-relative mb-2">
                                    <?php if ($has_offer): ?><span class="badge bg-danger position-absolute top-0 start-0 offer-badge">OFFER</span><?php endif; ?>
                                    <div class="product-image-wrap">
                                        <img src="<?php echo store_e($web_path); ?>" class="product-image" alt="<?php echo store_e($row['item_name']); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo store_e($web_root_prefix . 'assets/img/default_med.png'); ?>';">
                                    </div>
                                </div>
                                <div>
                                    <div class="product-title fw-bold" style="font-size:13px;">
                                        <?php echo store_e($row['item_name']); ?>
                                        <?php if (!empty($row['strength'])): ?><br><span class="text-muted small fw-normal"><?php echo store_e($row['strength']); ?></span><?php endif; ?>
                                        <?php if (!empty($row['category'])): ?><br><span class="text-muted small fw-normal"><?php echo store_e($row['category']); ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($has_offer): ?>
                                        <div class="product-price text-success fw-bold mt-1"><?php echo store_money($display_price); ?></div>
                                        <div class="product-price-old text-muted text-decoration-line-through"><?php echo store_money($normal_price); ?></div>
                                    <?php else: ?>
                                        <div class="product-price text-success fw-bold mt-1"><?php echo store_money($display_price); ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <button type="button" class="add-to-cart-btn add-cart-js mt-2 w-100 btn btn-outline-success btn-sm" data-id="<?php echo (int)$row['id']; ?>" data-branch="<?php echo $branch_id; ?>">
                                <i class="mdi mdi-cart-plus me-1"></i> ADD
                            </button>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="store-empty text-center py-5 px-3">
                        <i class="mdi mdi-pill-off fs-1 text-muted"></i>
                        <h6 class="fw-bold mt-3">No medicines found</h6>
                        <p class="text-muted mb-3">There are no available online products matching your selection.</p>
                        <?php if ($category_filter !== '' || $search_query !== ''): ?>
                            <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-success btn-sm">Clear Search</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>" class="wa-sticky position-fixed bottom-0 end-0 m-3 btn btn-success rounded-circle shadow p-3" target="_blank" rel="noopener" style="z-index:1000;" aria-label="WhatsApp">
    <i class="mdi mdi-whatsapp fs-3"></i>
</a>

<?php
$footer_path = $is_in_api_folder ? dirname(__DIR__) . '/includes/footer.php' : __DIR__ . '/includes/footer.php';
if (file_exists($footer_path)) require $footer_path;
?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    const apiPrefix = <?php echo json_encode($path_prefix); ?>;
    const branchId = <?php echo (int)$branch_id; ?>;

    function storeToast(type, title, message) {
        if (typeof window.showStoreToast === 'function') {
            window.showStoreToast(type, title, message);
            return;
        }
        // Fallback is an in-page notification, never a browser alert.
        let box = $('#storePageToast');
        if (!box.length) {
            $('body').append('<div id="storePageToast" style="position:fixed;top:85px;right:16px;z-index:3000;width:min(360px,calc(100vw - 32px));"></div>');
            box = $('#storePageToast');
        }
        const el = $('<div class="alert alert-' + (type === 'error' ? 'danger' : type) + ' shadow-sm">').html('<strong>' + $('<div>').text(title).html() + '</strong><br><small>' + $('<div>').text(message).html() + '</small>');
        box.append(el);
        setTimeout(() => el.fadeOut(200, () => el.remove()), 3500);
    }

    $(document).on('click', '.add-cart-js', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const btn = $(this);
        const itemId = Number(btn.data('id'));
        const selectedBranch = Number(btn.data('branch')) || branchId;
        const original = btn.html();

        if (!itemId || !selectedBranch) {
            storeToast('error', 'Unable to add item', 'The product or branch information is invalid.');
            return;
        }

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Adding...');

        $.ajax({
            url: apiPrefix + 'cart_handler.php',
            method: 'POST',
            dataType: 'text',
            data: { item_id: itemId, branch_id: selectedBranch },
            timeout: 10000
        }).done(function (response) {
            const count = parseInt(String(response).trim(), 10);
            if (!Number.isNaN(count)) {
                $('.cart-badge, .cart-count').text(count).removeClass('d-none');
                btn.html('<i class="mdi mdi-check"></i> ADDED').removeClass('btn-outline-success').addClass('btn-success text-white');
                if (typeof window.showStoreToast === 'function') window.showStoreToast('success', 'Added to Cart', 'The product was added successfully.');
            } else {
                btn.html(original);
                storeToast('error', 'Could not add product', 'The store did not return a valid cart response.');
            }
        }).fail(function (xhr) {
            console.error('Cart error:', xhr.responseText);
            btn.html(original);
            storeToast('error', 'Could not add product', 'Please try again.');
        }).always(function () {
            setTimeout(() => btn.prop('disabled', false).html(original), 1500);
        });
    });
});
</script>
</body>
</html>
