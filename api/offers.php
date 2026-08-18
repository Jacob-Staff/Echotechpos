<?php
require_once("store_header.php");
require_once("../includes/conn.php");

// ==============================
// 🔒 SECURE BRANCH RESOLUTION
// ==============================
$current_bid = isset($_GET['bid']) && is_numeric($_GET['bid'])
    ? intval($_GET['bid'])
    : ($_SESSION['branch_id'] ?? ($branch_id ?? 10));

// ==============================
// 📦 FETCH FLASH DEALS (SECURE)
// ==============================
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

$stmt->bind_param("i", $current_bid);
$stmt->execute();
$offers_res = $stmt->get_result();

// ==============================
// 🛠 HELPER FUNCTIONS
// ==============================
function safe_output($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function format_price($price) {
    return "K" . number_format((float)$price, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Flash Deals | <?php echo safe_output($pharmacy_name ?? 'Pharmacy'); ?></title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

<style>
.offer-hero {
    background: linear-gradient(135deg, #00d2ff, #3a7bd5);
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
    border-color: #00d2ff;
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
}

.price-new { color: #2ed573; font-weight: 700; }
.price-old { text-decoration: line-through; color: #999; font-size: 0.85rem; }

.countdown {
    font-family: monospace;
    background: rgba(255,255,255,0.2);
    padding: 6px 12px;
    border-radius: 8px;
}
</style>
</head>

<body class="bg-light">

<div class="container mt-4">

<!-- ================= HERO ================= -->
<div class="offer-hero animate__animated animate__fadeIn">
    <div class="row align-items-center">
        <div class="col-md-7 text-center text-md-start">
            <span class="badge bg-white text-primary px-3 rounded-pill fw-bold">LIMITED TIME</span>
            <h1 class="fw-bold mt-3">Flash Health Deals</h1>
            <p>
                Save big on essential medications 
                <span id="timer" class="countdown">05:00:00</span>
            </p>
            <a href="#deals" class="btn btn-light rounded-pill px-4 fw-bold">Shop Now</a>
        </div>

        <div class="col-md-5 text-end d-none d-md-block">
            <?php 
            $logo = !empty($tenant_context['tenant_logo']) 
                ? $tenant_context['tenant_logo'] 
                : 'default_logo.png';
            ?>
            <img src="/pharmacy_v1-master/uploads/logos/<?php echo $logo; ?>" 
                 style="width:140px; opacity:0.3; filter:invert(1);">
        </div>
    </div>
</div>

<!-- ================= PRODUCTS ================= -->
<div class="row g-4" id="deals">

<?php if($offers_res && $offers_res->num_rows > 0): ?>
<?php while($item = $offers_res->fetch_assoc()): 

    $discount = ($item['price'] > 0)
        ? round((($item['price'] - $item['online_price']) / $item['price']) * 100)
        : 0;

    $img = !empty($item['image']) ? $item['image'] : 'default_med.png';
    $img_path = "/pharmacy_v1-master/uploads/products/" . $img;
?>

<div class="col-6 col-md-4 col-lg-3 animate__animated animate__fadeInUp">
    <div class="offer-card position-relative">

        <div class="badge-discount">-<?php echo $discount; ?>%</div>

        <img src="<?php echo $img_path; ?>"
             onerror="this.src='/pharmacy_v1-master/uploads/products/default_med.png'"
             class="w-100 p-3"
             style="height:160px; object-fit:contain;">

        <div class="p-3">

            <small class="text-primary fw-bold">
                <?php echo safe_output($item['category']); ?>
            </small>

            <h6 class="fw-bold text-dark text-truncate">
                <?php echo safe_output($item['item_name']); ?>
            </h6>

            <small class="text-muted">
                <?php echo safe_output($item['strength']); ?>
            </small>

            <div class="mt-2">
                <span class="price-new"><?php echo format_price($item['online_price']); ?></span>
                <span class="price-old"><?php echo format_price($item['price']); ?></span>
            </div>

            <button class="btn btn-primary w-100 mt-3 add-to-cart-btn"
                data-id="<?php echo $item['id']; ?>"
                data-name="<?php echo safe_output($item['item_name']); ?>">
                Add to Cart
            </button>

        </div>
    </div>
</div>

<?php endwhile; ?>

<?php else: ?>

<div class="col-12 text-center py-5">
    <div class="bg-white p-5 rounded shadow-sm">
        <h4>No Active Deals</h4>
        <p class="text-muted">Check back later.</p>
    </div>
</div>

<?php endif; ?>

</div>
</div>

<!-- ================= JS ================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
$(function(){

    // ================= CART =================
    $('.add-to-cart-btn').click(function(){

        let btn = $(this);
        let id = btn.data('id');
        let name = btn.data('name');

        btn.prop('disabled', true).text('Adding...');

        $.post('actions/manage_cart.php', {
            action: 'add',
            product_id: id,
            qty: 1
        }, function(res){

            try {
                let data = JSON.parse(res);

                if(data.status === 'success'){

                    Toastify({
                        text: name + " added",
                        duration: 2500,
                        gravity: "bottom",
                        position: "right"
                    }).showToast();

                    $('.cart-badge').text(data.cart_count);
                }

            } catch(e){
                console.error("Invalid response", res);
            }

            btn.prop('disabled', false).text('Add to Cart');
        });

    });

    // ================= TIMER =================
    let timer = 18000; // 5 hours

    let interval = setInterval(function(){

        let h = Math.floor(timer / 3600);
        let m = Math.floor((timer % 3600) / 60);
        let s = timer % 60;

        $('#timer').text(
            (h<10?'0':'')+h+":"+
            (m<10?'0':'')+m+":"+
            (s<10?'0':'')+s
        );

        if(timer-- <= 0){
            clearInterval(interval);
            location.reload();
        }

    },1000);

});
</script>

</body>
</html>