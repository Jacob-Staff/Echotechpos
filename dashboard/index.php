<?php
session_start(); 
require "includes/conn.php";

/** * MULTI-TENANCY LOGIC 
 * Fetches branch-specific context (Lusaka, Kitwe, etc.)
 */
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 10; 

// Fetch Branch Data
$branch_res = $conn->query("SELECT b.*, p.name AS pharmacy_name FROM branches b JOIN pharmacies p ON b.pharmacy_id = p.id WHERE b.id = $branch_id");
$branch = $branch_res->fetch_assoc();

// Fallback
if(!$branch) {
    $branch = ['branch_name' => 'Echo Prime Lusaka', 'pharmacy_name' => 'Echo Prime', 'phone' => '260961457104'];
}

// Fetch Dynamic Categories
$cat_query = $conn->query("SELECT * FROM categories WHERE status = 1 LIMIT 8");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Echo Prime | <?php echo $branch['branch_name']; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --echo-teal: #003339;    /* Primary Brand Dark */
            --echo-green: #00b386;   /* Action Green */
            --echo-blue: #1a4a7c;    /* Accent Blue */
            --echo-light: #f5faff;
        }

        body { 
            background-color: #f8f9fa; 
            font-family: 'IBM Plex Sans', sans-serif; 
            margin: 0; 
        }

        .tier-1-utility { background: #fff; border-bottom: 1px solid #eee; padding: 8px 0; }
        .location-selector { font-size: 13px; color: var(--echo-teal); font-weight: 600; cursor: pointer; }
        .apollo-nav-pill { 
            color: #555; text-decoration: none; font-weight: 700; font-size: 14px; margin-right: 20px; transition: 0.3s;
        }
        .apollo-nav-pill:hover, .apollo-nav-pill.active { 
            color: var(--echo-teal); border-bottom: 3px solid var(--echo-green); padding-bottom: 5px;
        }

        .tier-2-strip { 
            background: var(--echo-teal); padding: 10px 0; box-shadow: inset 0 -2px 5px rgba(0,0,0,0.1);
            overflow-x: auto; white-space: nowrap;
        }
        .strip-link { 
            color: #fff !important; font-size: 12px; font-weight: 700; text-decoration: none; margin: 0 20px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .strip-link:hover { color: var(--echo-green) !important; }

        .tier-3-action { background: var(--echo-blue); padding: 15px 0; color: white; }
        .hng-logo-area { display: flex; align-items: center; gap: 10px; }
        .hng-search-container { 
            background: white; border-radius: 4px; display: flex; align-items: center; overflow: hidden; width: 100%;
        }
        .hng-search-container select { 
            border: none; background: #f1f1f1; padding: 10px; font-size: 13px; font-weight: 600; outline: none;
        }
        .hng-search-container input { border: none; padding: 10px 15px; width: 100%; outline: none; color: #333; }
        .hng-search-btn { background: #eee; border: none; padding: 10px 20px; color: var(--echo-blue); }
        
        .hng-nav-icon { 
            color: white; text-decoration: none; text-align: center; font-size: 12px; font-weight: 600;
        }
        .hng-nav-icon i { 
            background: white; color: var(--echo-blue); border-radius: 50%; width: 30px; height: 30px; 
            display: flex; align-items: center; justify-content: center; margin: 0 auto 4px; font-size: 16px;
        }

        .action-card {
            background: white; border-radius: 12px; padding: 20px; border: 1px solid #eef;
            transition: 0.3s; cursor: pointer; display: flex; justify-content: space-between; align-items: center;
        }
        .action-card:hover { 
            transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); border-color: var(--echo-green);
        }

        .product-card {
            background: white; border-radius: 8px; border: 1px solid #eee; padding: 15px; transition: 0.3s; height: 100%;
        }
        .product-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .product-price { color: var(--echo-green); font-weight: 800; font-size: 1.1rem; }
        .add-to-cart-btn {
            background: var(--echo-teal); color: white; border: none; width: 100%; padding: 8px; 
            border-radius: 4px; font-weight: 700; font-size: 12px; margin-top: 10px;
        }

        .wa-sticky {
            position: fixed; bottom: 30px; right: 30px; background: #25d366; color: white; width: 60px; height: 60px; 
            border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); z-index: 1000; text-decoration: none;
        }
    </style>
</head>
<body>

<div class="tier-1-utility">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h2 class="fw-bold mb-0 me-4" style="color:var(--echo-teal)">Echo<span style="color:var(--echo-green)">Prime</span></h2>
            <div class="dropdown">
                <div class="location-selector dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="mdi mdi-map-marker-outline fs-5"></i> 
                    Location: <span class="text-primary"><?php echo $branch['branch_name']; ?></span>
                </div>
                <ul class="dropdown-menu shadow border-0">
                    <?php 
                    $br_list = $conn->query("SELECT id, branch_name FROM branches WHERE is_active = 1");
                    while($bl = $br_list->fetch_assoc()): ?>
                        <li><a class="dropdown-item" href="?bid=<?php echo $bl['id']; ?>"><?php echo $bl['branch_name']; ?></a></li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
        <div class="d-flex align-items-center gap-4">
            <nav class="d-none d-lg-flex">
                <a href="#" class="apollo-nav-pill active">Buy Medicines</a>
                <a href="#" class="apollo-nav-pill">Find Doctors</a>
                <a href="#" class="apollo-nav-pill">Lab Tests</a>
            </nav>
            <div class="d-flex align-items-center gap-3 border-start ps-3">
                <a href="view_cart.php?bid=<?php echo $branch_id; ?>" class="text-dark position-relative text-decoration-none">
                    <i class="mdi mdi-cart-outline fs-4"></i>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 10px;">
                        <?php 
                        $cart_count = 0;
                        if(isset($_SESSION['cart'])) {
                            foreach($_SESSION['cart'] as $item) { $cart_count += $item['qty']; }
                        }
                        echo $cart_count; 
                        ?>
                    </span>
                </a>
                <button class="btn btn-outline-dark btn-sm rounded-pill px-4 fw-bold">Sign In</button>
            </div>
        </div>
    </div>
</div>

<div class="tier-2-strip">
    <div class="container d-flex justify-content-center">
        <?php 
        if($cat_query && $cat_query->num_rows > 0) {
            while($cat = $cat_query->fetch_assoc()) {
                echo '<a href="category.php?id='.$cat['id'].'&bid='.$branch_id.'" class="strip-link">'.$cat['name'].'</a>';
            }
        } else {
            echo '<span class="text-white-50 small">Store Categories Loading...</span>';
        }
        ?>
    </div>
</div>

<div class="tier-3-action shadow-sm">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-2">
                <div class="hng-logo-area">
                    <i class="mdi mdi-plus-box text-white fs-1"></i>
                    <div>
                        <h4 class="mb-0 fw-bold">HnG</h4>
                        <small class="opacity-75" style="font-size: 10px;">Online Pharmacy</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <form action="search.php" method="GET" class="hng-search-container">
                    <input type="hidden" name="bid" value="<?php echo $branch_id; ?>">
                    <select name="type">
                        <option value="all">All</option>
                        <option value="rx">Prescription</option>
                    </select>
                    <input type="text" name="q" placeholder="Search medicines in <?php echo $branch['branch_name']; ?>...">
                    <button type="submit" class="hng-search-btn"><i class="mdi mdi-magnify"></i></button>
                </form>
            </div>
            <div class="col-md-4 d-flex justify-content-between mt-3 mt-md-0">
                <a href="index.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-home"></i>Home</a>
                <a href="#" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a>
                <a href="#" class="hng-nav-icon"><i class="mdi mdi-label-percent"></i>Offer</a>
                <a href="#" class="hng-nav-icon"><i class="mdi mdi-help-circle"></i>Help</a>
                <a href="#" class="hng-nav-icon"><i class="mdi mdi-bank"></i>Bank Details</a>
            </div>
        </div>
    </div>
</div>

<div class="bg-white border-bottom py-3">
    <div class="container text-center">
        <div class="row">
            <div class="col-md-3 border-end small fw-bold"><i class="mdi mdi-check-decagram text-primary"></i> GENUINE MEDICINES</div>
            <div class="col-md-3 border-end small fw-bold"><i class="mdi mdi-tag-heart text-primary"></i> ATTRACTIVE OFFERS</div>
            <div class="col-md-3 border-end small fw-bold"><i class="mdi mdi-truck-fast text-primary"></i> TIMELY DELIVERY</div>
            <div class="col-md-3 small fw-bold text-success">DELIVERY IN 24-48 HRS</div>
        </div>
    </div>
</div>

<div class="container my-5">
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <a href="upload.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                <div class="action-card shadow-sm">
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">Get 20%* off</h6>
                        <small class="text-success fw-bold">UPLOAD PRESCRIPTION</small>
                    </div>
                    <i class="mdi mdi-chevron-right text-muted"></i>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <div class="action-card shadow-sm">
                <div>
                    <h6 class="fw-bold mb-1">Doctor Consult</h6>
                    <small class="text-primary fw-bold">BOOK APPOINTMENT</small>
                </div>
                <i class="mdi mdi-chevron-right text-muted"></i>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">Shop Medicines at <?php echo $branch['pharmacy_name']; ?></h4>
        <a href="#" class="text-primary fw-bold text-decoration-none small">VIEW ALL</a>
    </div>

    <div class="row g-4">
        <?php 
        $items = $conn->query("SELECT * FROM store_items WHERE branch_id = $branch_id AND is_active = 1 AND is_online = 1 LIMIT 12");
        
        if($items && $items->num_rows > 0):
            while($row = $items->fetch_assoc()): 
                $img = !empty($row['product_image']) ? $row['product_image'] : 'default_med.png';
            ?>
            <div class="col-6 col-md-3">
                <div class="product-card shadow-sm">
                    <div class="text-center mb-3">
                        <img src="assets/img/<?php echo $img; ?>" class="img-fluid" style="height:120px; object-fit: contain;" alt="<?php echo $row['item_name']; ?>">
                    </div>
                    <h6 class="fw-bold mb-1 text-truncate" title="<?php echo $row['item_name']; ?>"><?php echo $row['item_name']; ?></h6>
                    <p class="text-muted small mb-2"><?php echo $row['strength']; ?> | <?php echo $row['category']; ?></p>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="product-price">K <?php echo number_format($row['price'], 2); ?></span>
                    </div>
                    <button class="add-to-cart-btn add-cart-js" data-id="<?php echo $row['id']; ?>" data-branch="<?php echo $branch_id; ?>">
                        <i class="mdi mdi-cart-plus me-1"></i> ADD TO CART
                    </button>
                </div>
            </div>
            <?php endwhile; 
        else: ?>
            <div class="col-12 text-center py-5">
                <i class="mdi mdi-pill-off fs-1 text-muted"></i>
                <p class="mt-3 text-muted">No medicines currently listed online for this branch.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<a href="https://wa.me/<?php echo $branch['phone']; ?>" class="wa-sticky" target="_blank">
    <i class="mdi mdi-whatsapp"></i>
</a>

<?php if(file_exists("includes/footer.php")) require "includes/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // Add to Cart Logic
    $(document).on('click', '.add-cart-js', function(e) {
        e.preventDefault();
        var itemId = $(this).data('id');
        var branchId = $(this).data('branch');
        var btn = $(this);

        btn.html('<i class="mdi mdi-check"></i> ADDED').addClass('btn-success');

        $.ajax({
            url: 'cart_handler.php',
            method: 'POST',
            data: { item_id: itemId, branch_id: branch_id },
            success: function(response) {
                $('.badge.bg-danger').text(response);
                setTimeout(function() {
                    btn.html('<i class="mdi mdi-cart-plus me-1"></i> ADD TO CART').removeClass('btn-success');
                }, 1500);
            }
        });
    });
});
</script>

</body>
</html>