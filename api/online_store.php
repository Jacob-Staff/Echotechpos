<?php
/**
 * ============================================================
 * PUBLIC ONLINE STORE
 * ============================================================
 * - Uses the shared store_header.php
 * - Branch-aware through the public-store session
 * - Supports category, section and live search URLs
 * - Keeps the existing product/cart behaviour
 * ============================================================
 */

if (file_exists(__DIR__ . "/store_header.php")) {
    require_once __DIR__ . "/store_header.php";
} elseif (file_exists(__DIR__ . "/api/store_header.php")) {
    require_once __DIR__ . "/api/store_header.php";
} else {
    die("Error: store_header.php could not be found.");
}

$is_in_api_folder = (basename(__DIR__) === 'api');
$path_prefix = $is_in_api_folder ? '' : 'api/';

$category_filter = isset($_GET['cat']) ? trim((string)$_GET['cat']) : '';
$search_query    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$section         = isset($_GET['section']) ? trim((string)$_GET['section']) : '';
$type            = isset($_GET['type']) ? trim((string)$_GET['type']) : 'all';

/*
 * The Truemeds-style navigation is only a presentation/navigation layer.
 * These section mappings translate it to the categories that actually exist
 * in this pharmacy database, so the new menu never points to a dead page.
 */
$section_categories = [
    'medicines' => ['Medicines'],
    'personal-care' => ['Cosmetics', 'Baby Products', 'Wellness', 'Home Essentials'],
    'health-conditions' => ['Medicines', 'Herbal', 'Wellness'],
    'vitamins-supplements' => ['Wellness', 'Medicines'],
    'diabetes-care' => ['Wellness', 'Medicines'],
    'healthcare-devices' => ['Health Devices', 'Surgical'],
    'homeopathic-medicine' => ['Herbal', 'Wellness'],
];

$where = [
    'branch_id = ?',
    'is_active = 1',
    'is_online = 1'
];
$params = [$branch_id];
$types_sql = 'i';

/* Existing category filter remains supported. */
if ($category_filter !== '' && strtolower($category_filter) !== 'all') {
    $where[] = 'category = ?';
    $params[] = $category_filter;
    $types_sql .= 's';
}

/* New Truemeds-style section filter. */
if ($category_filter === '' && isset($section_categories[$section])) {
    $section_cats = $section_categories[$section];
    $placeholders = implode(',', array_fill(0, count($section_cats), '?'));
    $where[] = "category IN ($placeholders)";
    foreach ($section_cats as $section_cat) {
        $params[] = $section_cat;
        $types_sql .= 's';
    }
}

/* Search from the new mega-menu or the search bar. */
if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $where[] = '(item_name LIKE ? OR category LIKE ? OR strength LIKE ? OR description LIKE ? OR manufacturer LIKE ?)';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types_sql .= 's';
    }
}

/* Rx mode follows the existing store behaviour. */
if ($type === 'rx') {
    $where[] = "category = 'Medicines'";
}

$product_sql = "
    SELECT id, item_name, strength, category, price, online_price, image, product_image
    FROM store_items
    WHERE " . implode(' AND ', $where) . "
    ORDER BY item_name ASC
    LIMIT 60
";

$p_stmt = $conn->prepare($product_sql);
if (!$p_stmt) {
    die('Unable to load online store products.');
}

$p_stmt->bind_param($types_sql, ...$params);
$p_stmt->execute();
$items = $p_stmt->get_result();

$display_title = 'Shop Medicines';
if ($search_query !== '') {
    $display_title = 'Search Results';
} elseif ($section !== '' && isset($section_categories[$section])) {
    $display_title = ucwords(str_replace('-', ' ', $section));
} elseif ($category_filter !== '') {
    $display_title = $category_filter;
}
?>

<style>
    .transition-hover { transition: transform .2s ease, box-shadow .2s ease; }
    .transition-hover:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0,0,0,.08) !important;
        border-color: #00b386 !important;
    }
    .product-link { text-decoration:none; color:inherit; display:block; }
    .product-link:hover .product-title { color:#00b386; }
    .product-card { min-height:100%; }
    .store-section-title { color:#17202a; }
    .store-result-note { color:#687887; font-size:12px; }
    .store-clear-filter {
        font-size:11px;
        font-weight:700;
        text-decoration:none;
        color:#1769d1;
    }
    .product-image-box {
        height:115px;
        background:#fafafa;
        border-radius:10px;
        display:flex;
        align-items:center;
        justify-content:center;
        overflow:hidden;
        margin-bottom:9px;
    }
    .product-image-box img {
        max-height:105px;
        max-width:100%;
        object-fit:contain;
    }
    @media (max-width:576px) {
        .feature-card { padding:.75rem !important; }
        .feature-card i { font-size:1.5rem !important; margin-right:.5rem !important; }
        .feature-title { font-size:11px !important; }
        .feature-sub { font-size:9px !important; }
        .product-title { font-size:11px !important; height:30px; overflow:hidden; }
        .product-price { font-size:.9rem !important; }
    }
</style>

<!-- Action Banners -->
<div class="container mt-3 mt-md-4">
    <div class="row g-2 g-md-3">
        <div class="col-6 col-md-3">
            <a href="<?php echo $path_prefix; ?>upload_prescription.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-prescription text-success fs-2 me-3"></i>
                    <div><h6 class="feature-title mb-0 fw-bold text-dark" style="font-size:13px;">Upload Prescriptions</h6><small class="feature-sub text-muted" style="font-size:11px;">Easy &amp; Fast</small></div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="<?php echo $path_prefix; ?>lab_results.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-flask-outline text-primary fs-2 me-3"></i>
                    <div><h6 class="feature-title mb-0 fw-bold text-dark" style="font-size:13px;">Lab Test Results</h6><small class="feature-sub text-muted" style="font-size:11px;">Secure Access</small></div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>?text=I%20need%20a%20fast%20delivery" target="_blank" rel="noopener" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-truck-fast-outline text-warning fs-2 me-3"></i>
                    <div><h6 class="feature-title mb-0 fw-bold text-dark" style="font-size:13px;">Delivery in 2 HRS</h6><small class="feature-sub text-muted" style="font-size:11px;">Within Lusaka</small></div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>?text=Do%20you%20accept%20insurance" target="_blank" rel="noopener" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-shield-check-outline text-danger fs-2 me-3"></i>
                    <div><h6 class="feature-title mb-0 fw-bold text-dark" style="font-size:13px;">Health Insurance</h6><small class="feature-sub text-muted" style="font-size:11px;">Co-pay Support</small></div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Product Grid -->
<div class="container my-4 my-md-5">
    <div class="d-flex justify-content-between align-items-end mb-3 mb-md-4 gap-3">
        <div>
            <h4 class="fw-bold mb-1 fs-5 fs-md-4 store-section-title"><?php echo htmlspecialchars($display_title); ?></h4>
            <div class="store-result-note">
                <?php echo (int)($items ? $items->num_rows : 0); ?> products available in <?php echo htmlspecialchars($branch_name); ?>
                <?php if ($search_query !== ''): ?>
                    for â€œ<?php echo htmlspecialchars($search_query); ?>â€
                <?php endif; ?>
            </div>
        </div>
        <?php if ($search_query !== '' || $section !== '' || $category_filter !== ''): ?>
            <a class="store-clear-filter" href="online_store.php?bid=<?php echo $branch_id; ?>">VIEW ALL</a>
        <?php else: ?>
            <a class="store-clear-filter" href="online_store.php?bid=<?php echo $branch_id; ?>">VIEW ALL</a>
        <?php endif; ?>
    </div>

    <div class="row g-2 g-md-3" id="product-grid">
        <?php
        $upload_root = $is_in_api_folder ? dirname(__DIR__) : __DIR__;
        if ($items && $items->num_rows > 0):
            while ($row = $items->fetch_assoc()):
                $img_name = !empty($row['image']) ? $row['image'] : (!empty($row['product_image']) ? $row['product_image'] : 'default_med.png');
                $local_path = $upload_root . "/uploads/products/" . $img_name;
                $web_path = ($is_in_api_folder ? "../" : "") . "uploads/products/" . $img_name;
                if (!file_exists($local_path)) {
                    $web_path = ($is_in_api_folder ? "../" : "") . "assets/img/default_med.png";
                }
                $display_price = isset($row['online_price']) && $row['online_price'] !== null && (float)$row['online_price'] > 0
                    ? (float)$row['online_price']
                    : (float)$row['price'];
        ?>
            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <div class="product-card shadow-sm h-100 d-flex flex-column justify-content-between p-2 p-md-3 bg-white border rounded">
                    <a href="<?php echo $path_prefix; ?>product_details.php?id=<?php echo (int)$row['id']; ?>&bid=<?php echo $branch_id; ?>" class="product-link">
                        <div class="product-image-box">
                            <img src="<?php echo htmlspecialchars($web_path); ?>" alt="<?php echo htmlspecialchars($row['item_name']); ?>" loading="lazy" onerror="this.src='<?php echo $path_prefix; ?>assets/img/default_med.png';">
                        </div>
                        <div>
                            <div class="product-title fw-bold" style="font-size:13px;line-height:1.2;">
                                <?php echo htmlspecialchars($row['item_name']); ?><br>
                                <span class="text-muted small" style="font-weight:normal;">
                                    <?php echo htmlspecialchars($row['strength'] ?: $row['category']); ?>
                                </span>
                            </div>
                            <div class="product-price text-success fw-bold mt-1">K <?php echo number_format($display_price, 2); ?></div>
                        </div>
                    </a>

                    <button class="add-to-cart-btn add-cart-js mt-2 w-100 btn btn-outline-success btn-sm"
                            data-id="<?php echo (int)$row['id']; ?>"
                            data-branch="<?php echo $branch_id; ?>">
                        <i class="mdi mdi-cart-plus me-1"></i> ADD
                    </button>
                </div>
            </div>
        <?php endwhile; else: ?>
            <div class="col-12 text-center py-5">
                <i class="mdi mdi-pill-off fs-1 text-muted"></i>
                <p class="mt-3 text-muted">No products were found for this selection.</p>
                <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-success btn-sm rounded-pill px-4">View All Products</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- WhatsApp -->
<a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $phone); ?>"
   class="wa-sticky position-fixed bottom-0 end-0 m-3 btn btn-success rounded-circle shadow p-3"
   target="_blank" rel="noopener" style="z-index:1000;">
    <i class="mdi mdi-whatsapp fs-3"></i>
</a>

<?php
$footer_path = $is_in_api_folder ? dirname(__DIR__) . "/includes/footer.php" : __DIR__ . "/includes/footer.php";
if (file_exists($footer_path)) {
    require $footer_path;
}
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    var apiPrefix = "<?php echo $path_prefix; ?>";

    $(document).on('click', '.add-cart-js', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var itemId = $(this).data('id');
        var branchId = $(this).data('branch');
        var btn = $(this);

        $.ajax({
            url: apiPrefix + 'cart_handler.php',
            method: 'POST',
            data: { item_id: itemId, branch_id: branchId },
            success: function(response) {
                var count = String(response).trim();
                if (!isNaN(count)) {
                    $('.cart-badge, .cart-count').text(count).fadeOut(100).fadeIn(100);
                    btn.html('<i class="mdi mdi-check"></i> OK')
                       .addClass('btn-success text-white')
                       .removeClass('btn-outline-success');
                }
                setTimeout(function() {
                    btn.html('<i class="mdi mdi-cart-plus me-1"></i> ADD')
                       .removeClass('btn-success text-white')
                       .addClass('btn-outline-success');
                }, 1500);
            },
            error: function() {
                btn.html('<i class="mdi mdi-alert"></i> ERROR');
                setTimeout(function() {
                    btn.html('<i class="mdi mdi-cart-plus me-1"></i> ADD');
                }, 1600);
            }
        });
    });
});
</script>
</body>
</html>
