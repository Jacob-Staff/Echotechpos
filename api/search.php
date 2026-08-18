<?php
// Go up one level to find your includes
require_once(__DIR__ . "/../includes/conn.php");
// If store_header.php handles the session and basic UI, keep it. 
// If not, ensure session_start() is at the top.
require "store_header.php"; 

$query = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 10;
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

// 1. Fetch results from store_items
$sql = "SELECT * FROM store_items 
        WHERE branch_id = $branch_id 
        AND is_online = 1 
        AND (item_name LIKE '%$query%' OR description LIKE '%$query%')";

if ($type === 'rx') {
    $sql .= " AND category = 'Medicines'";
}

$results = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root { --echo-teal: #003339; --echo-green: #00b386; --echo-blue: #1a4a7c; }
        body { background: #f4f7f6; }
        
        .product-card { 
            background: white; 
            border-radius: 10px; 
            border: 1px solid #eee; 
            padding: 12px; 
            transition: all 0.3s ease; 
            position: relative;
            height: 100%;
        }
        .product-card:hover { 
            box-shadow: 0 10px 20px rgba(0,0,0,0.08); 
            transform: translateY(-3px);
        }
        
        .offer-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: var(--echo-green);
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            z-index: 2;
        }

        .img-container {
            height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .product-link { text-decoration: none; color: inherit; }
        .product-link:hover { color: var(--echo-teal); }

        .mrp-price { 
            text-decoration: line-through; 
            color: #999; 
            font-size: 11px; 
            margin-left: 5px; 
        }

        .add-to-cart-btn { 
            background: white; 
            color: var(--echo-teal); 
            border: 1px solid var(--echo-teal); 
            width: 100%; 
            padding: 6px; 
            border-radius: 6px; 
            font-weight: 700;
            font-size: 13px;
            transition: 0.2s;
        }
        .add-to-cart-btn:hover { 
            background: var(--echo-teal); 
            color: white; 
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Search Results</h4>
            <small class="text-muted">Found <?php echo $results->num_rows; ?> items for "<?php echo htmlspecialchars($query); ?>"</small>
        </div>
        <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-dark btn-sm rounded-pill px-3">
            <i class="mdi mdi-arrow-left"></i> Back to Store
        </a>
    </div>

    <div class="row g-3">
        <?php if($results && $results->num_rows > 0): ?>
            <?php while($item = $results->fetch_assoc()): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="product-card">
                        <div class="offer-badge">10% OFF</div>
                        
                        <a href="product_details.php?id=<?php echo $item['id']; ?>&bid=<?php echo $branch_id; ?>" class="product-link">
                            <div class="img-container">
                                <img src="../uploads/products/<?php echo $item['image'] ?: 'default_med.png'; ?>" class="img-fluid" style="max-height: 100%;">
                            </div>
                            
                            <h6 class="fw-bold mb-1 text-truncate" title="<?php echo $item['item_name']; ?>">
                                <?php echo $item['item_name']; ?>
                            </h6>
                            <p class="small text-muted mb-2"><?php echo $item['strength']; ?></p>
                            
                            <div class="mb-3">
                                <span class="fw-bold text-dark">ZMK <?php echo number_format($item['price'], 2); ?></span>
                                <span class="mrp-price">ZMK <?php echo number_format($item['price'] * 1.1, 2); ?></span>
                            </div>
                        </a>

                        <button class="add-to-cart-btn" onclick="addToCart(<?php echo $item['id']; ?>)">
                            <i class="mdi mdi-cart-outline me-1"></i> ADD TO CART
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <div class="bg-white p-5 rounded shadow-sm">
                    <i class="mdi mdi-magnify-close fs-1 text-muted"></i>
                    <h5 class="mt-3">No matching items found</h5>
                    <p class="text-muted">Try checking your spelling or search for a general term like "Panadol".</p>
                    <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-primary mt-3">Browse All Products</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function addToCart(productId) {
    // You can implement your AJAX add-to-cart logic here
    console.log("Added product " + productId + " to cart");
    // Show a small toast or alert
}
</script>

</body>
</html>