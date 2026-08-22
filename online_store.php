<?php 
// 1. Let the header start the session and database connection
require "api/store_header.php";[cite: 11]

// 2. Override branch choice if present in URL
if (isset($_GET['bid'])) {[cite: 11]
    $_SESSION['branch_id'] = intval($_GET['bid']); // Secure casting to Integer[cite: 11]
    $branch_id = $_SESSION['branch_id'];[cite: 11]
} else {
    $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;[cite: 11]
}

// 3. Fetch products
$category_filter = isset($_GET['cat']) ? $_GET['cat'] : '';[cite: 11]

$product_sql = "SELECT id, item_name, strength, category, price, image FROM store_items 
                WHERE branch_id = '$branch_id' 
                AND is_active = 1 
                AND is_online = 1";[cite: 11]

if ($category_filter != '') {[cite: 11]
    $product_sql .= " AND category = '" . $conn->real_escape_string($category_filter) . "'";[cite: 11]
}

$items = $conn->query($product_sql . " LIMIT 12");[cite: 11]
?>

<style>
    /* Mobile-First Responsive Styles */
    .transition-hover { transition: transform 0.2s ease, box-shadow 0.2s ease; }[cite: 11]
    .transition-hover:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important; border-color: #00b386 !important; }[cite: 11]
    .product-link { text-decoration: none; color: inherit; display: block; }[cite: 11]
    .product-link:hover .product-title { color: #00b386; }[cite: 11]

    @media (max-width: 576px) {
        /* Mobile Feature Banners */
        .feature-card {
            padding: 10px !important;
        }
        .feature-card i {
            font-size: 1.5rem !important;
            margin-right: 8px !important;
        }
        .feature-card h6 {
            font-size: 11px !important;
        }
        .feature-card small {
            font-size: 9px !important;
        }

        /* Mobile Product Grid */
        .product-card {
            padding: 8px !important;
        }
        .product-card img {
            height: 70px !important;
        }
        .product-title {
            font-size: 11px !important;
        }
        .product-price {
            font-size: 13px !important;
        }
        .add-to-cart-btn {
            font-size: 10px !important;
            padding: 8px 4px !important; /* Larger touch area for mobile thumbs */
        }
    }
</style>

<!-- Quick Action Feature Cards -->
<div class="container mt-3 mt-md-4">
    <div class="row g-2 g-md-3">
        <div class="col-6 col-lg-3">[cite: 11]
            <a href="api/upload_prescription.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">[cite: 11]
                <div class="d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover feature-card">[cite: 11]
                    <i class="mdi mdi-prescription text-success fs-2 me-3"></i>[cite: 11]
                    <div>
                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 13px;">Upload Prescriptions</h6>[cite: 11]
                        <small class="text-muted" style="font-size: 11px;">Easy & Fast</small>[cite: 11]
                    </div>
                </div>
            </a>
        </div>
        
        <div class="col-6 col-lg-3">[cite: 11]
            <a href="api/lab_results.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none">[cite: 11]
                <div class="d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover feature-card">[cite: 11]
                    <i class="mdi mdi-flask-outline text-primary fs-2 me-3"></i>[cite: 11]
                    <div>
                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 13px;">Lab Test Results</h6>[cite: 11]
                        <small class="text-muted" style="font-size: 11px;">Secure Access</small>[cite: 11]
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-lg-3">[cite: 11]
            <a href="https://wa.me/<?php echo $phone; ?>?text=I%20need%20a%20fast%20delivery" target="_blank" class="text-decoration-none">[cite: 11]
                <div class="d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover feature-card">[cite: 11]
                    <i class="mdi mdi-truck-fast-outline text-warning fs-2 me-3"></i>[cite: 11]
                    <div>
                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 13px;">Delivery in 2 HRS</h6>[cite: 11]
                        <small class="text-muted" style="font-size: 11px;">Within Lusaka</small>[cite: 11]
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-lg-3">[cite: 11]
            <a href="https://wa.me/<?php echo $phone; ?>?text=Do%20you%20accept%20insurance" target="_blank" class="text-decoration-none">[cite: 11]
                <div class="d-flex align-items-center p-3 bg-white border rounded shadow-sm h-100 transition-hover feature-card">[cite: 11]
                    <i class="mdi mdi-shield-check-outline text-danger fs-2 me-3"></i>[cite: 11]
                    <div>
                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 13px;">Health Insurance</h6>[cite: 11]
                        <small class="text-muted" style="font-size: 11px;">Co-pay Support</small>[cite: 11]
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Product Grid -->
<div class="container my-4 my-md-5">
    <div class="d-flex justify-content-between align-items-center mb-3 mb-md-4">
        <h4 class="fw-bold mb-0 fs-5 fs-md-4">Shop Medicines</h4>[cite: 11]
        <a href="all_products.php?bid=<?php echo $branch_id; ?>" class="text-primary fw-bold text-decoration-none small">VIEW ALL</a>[cite: 11]
    </div>

    <div class="row g-2 g-md-3" id="product-grid"> [cite: 11]
        <?php 
        if($items && $items->num_rows > 0):[cite: 11]
            while($row = $items->fetch_assoc()): [cite: 11]
                $img_name = (!empty($row['image'])) ? $row['image'] : 'default_med.png';[cite: 11]
                $img_path = "uploads/products/" . $img_name;[cite: 11]
                
                if(!file_exists($img_path)) {[cite: 11]
                    $img_path = "assets/img/default_med.png";[cite: 11]
                }
            ?>
            <div class="col-6 col-md-3 col-lg-2"> 
                <div class="product-card shadow-sm h-100 d-flex flex-column justify-content-between">[cite: 11]
                    <a href="api/product_details.php?id=<?php echo $row['id']; ?>&bid=<?php echo $branch_id; ?>" class="product-link">[cite: 11]
                        <div class="text-center mb-2">[cite: 11]
                            <img src="<?php echo $img_path; ?>" class="img-fluid" style="height:85px; object-fit: contain;">[cite: 11]
                        </div>
                        <div>
                            <div class="product-title fw-bold" style="font-size: 13px; line-height: 1.2;">[cite: 11]
                                <?php echo htmlspecialchars($row['item_name']); ?> 
                                <br><span class="text-muted small" style="font-weight: normal;"><?php echo htmlspecialchars($row['category']); ?></span>[cite: 11]
                            </div>
                            <div class="product-price text-success fw-bold mt-1">K <?php echo number_format($row['price'], 2); ?></div>[cite: 11]
                        </div>
                    </a>

                    <button class="add-to-cart-btn add-cart-js mt-2 w-100" data-id="<?php echo $row['id']; ?>" data-branch="<?php echo $branch_id; ?>">[cite: 11]
                        <i class="mdi mdi-cart-plus me-1"></i> ADD[cite: 11]
                    </button>
                </div>
            </div>
            <?php endwhile; 
        else: ?>
            <div class="col-12 text-center py-5">[cite: 11]
                <i class="mdi mdi-pill-off fs-1 text-muted"></i>[cite: 11]
                <p class="mt-3 text-muted">No medicines currently listed online.</p>[cite: 11]
            </div>
        <?php endif; ?>
    </div>
</div>

<a href="https://wa.me/<?php echo $phone; ?>" class="wa-sticky" target="_blank">[cite: 11]
    <i class="mdi mdi-whatsapp"></i>[cite: 11]
</a>

<?php if(file_exists("includes/footer.php")) require "includes/footer.php"; ?>[cite: 11]

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>[cite: 11]
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>[cite: 11]

<script>
$(document).ready(function() {
    // 1. ADD TO CART LOGIC
    $(document).on('click', '.add-cart-js', function(e) {[cite: 11]
        e.preventDefault();[cite: 11]
        e.stopPropagation();[cite: 11]
        
        var itemId = $(this).data('id');[cite: 11]
        var branchId = $(this).data('branch');[cite: 11]
        var btn = $(this);[cite: 11]

        $.ajax({[cite: 11]
            url: 'api/cart_handler.php', [cite: 11]
            method: 'POST',[cite: 11]
            data: { item_id: itemId, branch_id: branchId },[cite: 11]
            success: function(response) {[cite: 11]
                if(!isNaN(response)) {[cite: 11]
                    $('.cart-badge').text(response).fadeOut(100).fadeIn(100);[cite: 11]
                    btn.html('<i class="mdi mdi-check"></i> OK').addClass('btn-success text-white');[cite: 11]
                }
                setTimeout(function() {[cite: 11]
                    btn.html('<i class="mdi mdi-cart-plus me-1"></i> ADD').removeClass('btn-success text-white');[cite: 11]
                }, 1500);[cite: 11]
            }
        });
    });

    // 2. CATEGORY FILTER LOGIC
    $('.cat-filter').on('click', function(e) {[cite: 11]
        e.preventDefault();[cite: 11]
        $('.cat-filter').removeClass('active');[cite: 11]
        $(this).addClass('active');[cite: 11]
        var categoryName = $(this).data('category');[cite: 11]
        var branch = <?php echo $branch_id; ?>;[cite: 11]

        $('#product-grid').html('<div class="col-12 text-center py-5"><div class="spinner-border text-success"></div></div>');[cite: 11]

        $.ajax({[cite: 11]
            url: 'api/filter_products.php',[cite: 11]
            method: 'GET',[cite: 11]
            data: { category: categoryName, bid: branch },[cite: 11]
            success: function(response) {[cite: 11]
                $('#product-grid').hide().html(response).fadeIn(300);[cite: 11]
            }
        });
    });
});
</script>
</body>
</html>
