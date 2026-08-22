<?php 
// 1. Header handles session_start() and database connection
require "api/store_header.php"; 

// 2. Override default branch choice safely
if (isset($_GET['bid'])) {
    $_SESSION['branch_id'] = intval($_GET['bid']);
    $branch_id = $_SESSION['branch_id']; 
} else {
    $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;
}

// 3. Fetch products
$category_filter = isset($_GET['cat']) ? $_GET['cat'] : '';

$product_sql = "SELECT id, item_name, strength, category, price, image FROM store_items 
                WHERE branch_id = '$branch_id' 
                AND is_active = 1 
                AND is_online = 1";

if ($category_filter != '') {
    $product_sql .= " AND category = '" . $conn->real_escape_string($category_filter) . "'";
}

$items = $conn->query($product_sql . " LIMIT 12");
?>

<style>
    .transition-hover { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .transition-hover:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; border-color: #00b386 !important; }
    .product-link { text-decoration: none; color: inherit; display: block; }
    .product-link:hover .product-title { color: #00b386; }
    
    /* Mobile responsive adjustments */
    @media (max-width: 576px) {
        .feature-card { padding: 0.75rem !important; }
        .feature-card i { font-size: 1.5rem !important; margin-right: 0.5rem !important; }
        .feature-title { font-size: 11px !important; }
        .feature-sub { font-size: 9px !important; }
        .product-title { font-size: 11px !important; height: 30px; overflow: hidden; }
        .product-price { font-size: 0.9rem !important; }
    }
</style>

<!-- Action Banners (Mobile Responsive Stack) -->
<div class="container mt-3 mt-md-4">
    <div class="row g-2 g-md-3">
        <div class="col-6 col-md-3">
            <a href="api/upload_prescription.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-prescription text-success fs-2 me-3"></i>
                    <div>
                        <h6 class="feature-title mb-0 fw-bold text-dark" style="font-size: 13px;">Upload Prescriptions</h6>
                        <small class="feature-sub text-muted" style="font-size: 11px;">Easy & Fast</small>
                    </div>
                </div>
            </a>
        </div>
        
        <div class="col-6 col-md-3">
            <a href="api/lab_results.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-flask-outline text-primary fs-2 me-3"></i>
                    <div>
                        <h6 class="feature-title mb-0 fw-bold text-dark" style="font-size: 13px;">Lab Test Results</h6>
                        <small class="feature-sub text-muted" style="font-size: 11px;">Secure Access</small>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-3">
            <a href="https://wa.me/<?php echo $phone; ?>?text=I%20need%20a%20fast%20delivery" target="_blank" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-truck-fast-outline text-warning fs-2 me-3"></i>
                    <div>
                        <h6 class="feature-title mb-0 fw-bold text-dark" style="font-size: 13px;">Delivery in 2 HRS</h6>
                        <small class="feature-sub text-muted" style="font-size: 11px;">Within Lusaka</small>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-3">
            <a href="https://wa.me/<?php echo $phone; ?>?text=Do%20you%20accept%20insurance" target="_blank" class="text-decoration-none">
                <div class="feature-card d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover">
                    <i class="mdi mdi-shield-check-outline text-danger fs-2 me-3"></i>
                    <div>
                        <h6 class="feature-title mb-0 fw-bold text-dark" style="font-size: 13px;">Health Insurance</h6>
                        <small class="feature-sub text-muted" style="font-size: 11px;">Co-pay Support</small>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Product Grid Section -->
<div class="container my-4 my-md-5">
    <div class="d-flex justify-content-between align-items-center mb-3 mb-md-4">
        <h4 class="fw-bold mb-0 fs-5 fs-md-4">Shop Medicines</h4>
        <a href="all_products.php?bid=<?php echo $branch_id; ?>" class="text-primary fw-bold text-decoration-none small">VIEW ALL</a>
    </div>

    <!-- Responsive Grid: 2 columns on phones, 3 on small tablets, 4 on medium screens, 6 on large desktop -->
    <div class="row g-2 g-md-3" id="product-grid"> 
        <?php 
        if($items && $items->num_rows > 0):
            while($row = $items->fetch_assoc()): 
                $img_name = (!empty($row['image'])) ? $row['image'] : 'default_med.png';
                $img_path = "uploads/products/" . $img_name;
                
                if(!file_exists($img_path)) {
                    $img_path = "assets/img/default_med.png";
                }
            ?>
            <div class="col-6 col-sm-4 col-md-3 col-lg-2"> 
                <div class="product-card shadow-sm h-100 d-flex flex-column justify-content-between p-2 p-md-3">
                    <a href="api/product_details.php?id=<?php echo $row['id']; ?>&bid=<?php echo $branch_id; ?>" class="product-link">
                        <div class="text-center mb-2">
                            <img src="<?php echo $img_path; ?>" class="img-fluid" style="height:85px; object-fit: contain;" alt="<?php echo htmlspecialchars($row['item_name']); ?>">
                        </div>
                        <div>
                            <div class="product-title fw-bold" style="font-size: 13px; line-height: 1.2;">
                                <?php echo htmlspecialchars($row['item_name']); ?> 
                                <br><span class="text-muted small" style="font-weight: normal;"><?php echo htmlspecialchars($row['category']); ?></span>
                            </div>
                            <div class="product-price text-success fw-bold mt-1">K <?php echo number_format($row['price'], 2); ?></div>
                        </div>
                    </a>

                    <button class="add-to-cart-btn add-cart-js mt-2 w-100" data-id="<?php echo $row['id']; ?>" data-branch="<?php echo $branch_id; ?>">
                        <i class="mdi mdi-cart-plus me-1"></i> ADD
                    </button>
                </div>
            </div>
            <?php endwhile; 
        else: ?>
            <div class="col-12 text-center py-5">
                <i class="mdi mdi-pill-off fs-1 text-muted"></i>
                <p class="mt-3 text-muted">No medicines currently listed online.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<a href="https://wa.me/<?php echo $phone; ?>" class="wa-sticky" target="_blank">
    <i class="mdi mdi-whatsapp"></i>
</a>

<?php if(file_exists("includes/footer.php")) require "includes/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    $(document).on('click', '.add-cart-js', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var itemId = $(this).data('id');
        var branchId = $(this).data('branch');
        var btn = $(this);

        $.ajax({
            url: 'api/cart_handler.php', 
            method: 'POST',
            data: { item_id: itemId, branch_id: branchId },
            success: function(response) {
                if(!isNaN(response)) {
                    $('.cart-badge').text(response).fadeOut(100).fadeIn(100);
                    btn.html('<i class="mdi mdi-check"></i> OK').addClass('btn-success text-white');
                }
                setTimeout(function() {
                    btn.html('<i class="mdi mdi-cart-plus me-1"></i> ADD').removeClass('btn-success text-white');
                }, 1500);
            }
        });
    });

    $('.cat-filter').on('click', function(e) {
        e.preventDefault();
        $('.cat-filter').removeClass('active');
        $(this).addClass('active');
        var categoryName = $(this).data('category');
        var branch = <?php echo $branch_id; ?>;

        $('#product-grid').html('<div class="col-12 text-center py-5"><div class="spinner-border text-success"></div></div>');

        $.ajax({
            url: 'api/filter_products.php',
            method: 'GET',
            data: { category: categoryName, bid: branch },
            success: function(response) {
                $('#product-grid').hide().html(response).fadeIn(300);
            }
        });
    });
});
</script>
</body>
</html>
