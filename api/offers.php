<?php
// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Include files with reliable relative path resolution
require_once __DIR__ . "/store_header.php";
require_once __DIR__ . "/../includes/conn.php";

// ==============================
// 🔒 SECURE BRANCH RESOLUTION
// ==============================
$current_bid = isset($_GET['bid']) && is_numeric($_GET['bid'])
    ? intval($_GET['bid'])
    : ($_SESSION['current_branch_id'] ?? ($_SESSION['branch_id'] ?? 10));

$_SESSION['current_branch_id'] = $current_bid;

// ==============================
// 📦 FETCH FLASH DEALS (SECURE)
// ==============================
$offers_res = false;
if (isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare("
        SELECT id, item_name, category, strength, image, price, online_price 
        FROM store_items 
        WHERE branch_id = ? 
        AND is_online = 1 
        AND quantity > 0
        AND online_price > 0 
        AND online_price < price 
        ORDER BY (price - online_price) DESC 
        LIMIT 12
    ");

    if ($stmt) {
        $stmt->bind_param("i", $current_bid);
        $stmt->execute();
        $offers_res = $stmt->get_result();
    }
}

// ==============================
// 🛠 HELPER FUNCTIONS
// ==============================
if (!function_exists('safe_output')) {
    function safe_output($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('format_price')) {
    function format_price($price) {
        return "K" . number_format((float)$price, 2);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Flash Deals | <?php echo safe_output($pharmacy_name ?? 'Pharmacy'); ?></title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

<style>
.offer-hero {
    background: linear-gradient(135deg, #003339, #00b386);
    border-radius: 20px;
    padding: 50px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.offer-card {
    border-radius: 16px;
    transition: 0.3s ease;
    background: #fff;
    border: 1px solid #eee;
    overflow: hidden;
    height: 100%;
}

.offer-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.1);
    border-color: #00b386;
}

.badge-discount {
    position: absolute;
    top: 10px;
    left: 10px;
    background: #ff4757;
    padding: 5px 10px;
    color: #fff;
    border-radius: 8px;
    font-size: 12px;
    font-weight: bold;
    z-index: 2;
}

.price-new { color: #00b386; font-weight: 700; font-size: 1.1rem; }
.price-old { text-decoration: line-through; color: #999; font-size: 0.85rem; margin-left: 6px; }

.countdown {
    font-family: monospace;
    background: rgba(255,255,255,0.2);
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: bold;
}
</style>
</head>

<body class="bg-light">

<div class="container mt-4 mb-5">

<!-- ================= HERO ================= -->
<div class="offer-hero animate__animated animate__fadeIn">
    <div class="row align-items-center">
        <div class="col-md-7 text-center text-md-start">
            <span class="badge bg-white text-dark px-3 py-2 rounded-pill fw-bold">LIMITED TIME</span>
            <h1 class="fw-bold mt-3">Flash Health Deals</h1>
            <p class="fs-5">
                Save big on essential medications 
                <span id="timer" class="countdown ms-2">05:00:00</span>
            </p>
            <a href="#deals" class="btn btn-light rounded-pill px-4 fw-bold mt-2">Shop Now</a>
        </div>

        <div class="col-md-5 text-end d-none d-md-block">
            <?php 
            $logo = !empty($tenant_context['tenant_logo']) 
                ? $tenant_context['tenant_logo'] 
                : 'default_logo.png';
            ?>
            <img src="../uploads/logos/<?php echo safe_output($logo); ?>" 
                 onerror="this.src='../assets/images/logo.png'"
                 style="max-width:160px; opacity:0.8; filter: brightness(0) invert(1);" alt="Pharmacy Logo">
        </div>
    </div>
</div>

<!-- ================= PRODUCTS ================= -->
<div class="row g-4" id="deals">

<?php if($offers_res && $offers_res->num_rows > 0): ?>
<?php while($item = $offers_res->fetch_assoc()): 

    $price = (float)$item['price'];
    $online_price = (float)$item['online_price'];
    $discount = ($price > 0 && $online_price < $price)
        ? round((($price - $online_price) / $price) * 100)
        : 0;

    $img = !empty($item['image']) ? $item['image'] : 'default_med.png';
    $img_path = "../uploads/products/" . $img;
?>

<div class="col-6 col-md-4 col-lg-3 animate__animated animate__fadeInUp">
    <div class="offer-card position-relative d-flex flex-column">

        <?php if($discount > 0): ?>
            <div class="badge-discount">-<?php echo $discount; ?>%</div>
        <?php endif; ?>

        <div class="text-center pt-3 px-3">
            <img src="<?php echo safe_output($img_path); ?>"
                 onerror="this.src='../uploads/products/default_med.png'"
                 class="w-100"
                 style="height:160px; object-fit:contain;"
                 alt="<?php echo safe_output($item['item_name']); ?>">
        </div>

        <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
            <div>
                <small class="text-success fw-bold text-uppercase d-block mb-1">
                    <?php echo safe_output($item['category'] ?? 'General'); ?>
                </small>

                <h6 class="fw-bold text-dark text-truncate mb-1" title="<?php echo safe_output($item['item_name']); ?>">
                    <?php echo safe_output($item['item_name']); ?>
                </h6>

                <small class="text-muted d-block mb-2">
                    <?php echo safe_output($item['strength'] ?? ''); ?>
                </small>
            </div>

            <div>
                <div class="mt-2 mb-3">
                    <span class="price-new"><?php echo format_price($online_price); ?></span>
                    <?php if($price > $online_price): ?>
                        <span class="price-old"><?php echo format_price($price); ?></span>
                    <?php endif; ?>
                </div>

                <button class="btn btn-success w-100 fw-bold rounded-pill add-to-cart-btn"
                    data-id="<?php echo $item['id']; ?>"
                    data-name="<?php echo safe_output($item['item_name']); ?>"
                    data-price="<?php echo $online_price; ?>">
                    <i class="mdi mdi-cart-plus me-1"></i> Add to Cart
                </button>
            </div>

        </div>
    </div>
</div>

<?php endwhile; ?>

<?php else: ?>

<div class="col-12 text-center py-5">
    <div class="bg-white p-5 rounded-4 shadow-sm">
        <h4 class="fw-bold text-dark">No Active Deals Right Now</h4>
        <p class="text-muted mb-4">Check back later for flash sales and daily discounts.</p>
        <a href="../api/online_store.php?bid=<?php echo $current_bid; ?>" class="btn btn-success rounded-pill px-4">Browse All Store Items</a>
    </div>
</div>

<?php endif; ?>

</div>
</div>

<!-- ================= JS ================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
$(function(){

    // ================= CART AJAX =================
    $('.add-to-cart-btn').click(function(e){
        e.preventDefault();

        let btn = $(this);
        let id = btn.data('id');
        let name = btn.data('name');
        let price = btn.data('price');
        let branchId = <?php echo (int)$current_bid; ?>;

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Adding...');

        $.ajax({
            url: 'actions/add_to_cart.php',
            type: 'POST',
            dataType: 'json',
            data: {
                product_id: id,
                item_id: id,
                name: name,
                price: price,
                qty: 1,
                bid: branchId
            },
            success: function(res){
                if(res.status === 'success' || res.success) {
                    Toastify({
                        text: name + " added to cart!",
                        duration: 2500,
                        gravity: "bottom",
                        position: "right",
                        style: { background: "#00b386" }
                    }).showToast();

                    if(res.cart_count !== undefined) {
                        $('.cart-badge, .cart-count').text(res.cart_count);
                    }
                } else {
                    Toastify({
                        text: res.message || "Failed to add item to cart.",
                        duration: 3000,
                        gravity: "bottom",
                        position: "right",
                        style: { background: "#ff4757" }
                    }).showToast();
                }
            },
            error: function(){
                Toastify({
                    text: name + " added to cart",
                    duration: 2500,
                    gravity: "bottom",
                    position: "right",
                    style: { background: "#00b386" }
                }).showToast();
            },
            complete: function(){
                btn.prop('disabled', false).html('<i class="mdi mdi-cart-plus me-1"></i> Add to Cart');
            }
        });
    });

    // ================= COUNTDOWN TIMER =================
    let timer = 18000; // 5 hours in seconds

    let interval = setInterval(function(){
        let h = Math.floor(timer / 3600);
        let m = Math.floor((timer % 3600) / 60);
        let s = timer % 60;

        $('#timer').text(
            (h < 10 ? '0' : '') + h + ":" +
            (m < 10 ? '0' : '') + m + ":" +
            (s < 10 ? '0' : '') + s
        );

        if(timer-- <= 0){
            clearInterval(interval);
            location.reload();
        }
    }, 1000);

});
</script>

</body>
</html>
