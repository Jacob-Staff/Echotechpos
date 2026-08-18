<?php
require_once(__DIR__ . "/../includes/conn.php");

$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 0;
$category = isset($_GET['category']) ? $_GET['category'] : '';

$sql = "SELECT * FROM store_items WHERE branch_id = $branch_id AND is_active = 1 AND is_online = 1";

if ($category !== 'all' && !empty($category)) {
    $safe_cat = $conn->real_escape_string($category);
    $sql .= " AND category = '$safe_cat'";
}

$sql .= " LIMIT 12";
$items = $conn->query($sql);

if($items && $items->num_rows > 0) {
    while($row = $items->fetch_assoc()) {
        $img_name = (!empty($row['image'])) ? $row['image'] : 'default_med.png';
        echo '
        <div class="col-6 col-md-3 col-lg-2"> 
            <div class="product-card shadow-sm">
                <div class="text-center mb-2">
                    <img src="uploads/products/'.$img_name.'" class="img-fluid" style="height:85px; object-fit: contain;">
                </div>
                <div class="product-title">'.htmlspecialchars($row['item_name']).' <span class="cat-label">| '.htmlspecialchars($row['category']).'</span></div>
                <div class="product-price">K '.number_format($row['price'], 2).'</div>
                <button class="add-to-cart-btn add-cart-js" data-id="'.$row['id'].'" data-branch="'.$branch_id.'">
                    <i class="mdi mdi-cart-plus me-1"></i> ADD
                </button>
            </div>
        </div>';
    }
} else {
    echo '<div class="col-12 text-center py-5"><p class="text-muted">No products found.</p></div>';
}
?>