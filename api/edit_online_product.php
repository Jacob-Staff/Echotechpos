<?php
require "config.php";

$branch_id = isset($_GET['branch_id']) ? $_GET['branch_id'] : 10;
$category  = isset($_GET['category']) ? $_GET['category'] : null;

// Build the query with an optional Category filter
$sql = "SELECT item_name, strength, price, category, product_image, SUM(quantity) as total_qty 
        FROM store_items 
        WHERE is_online = 1 
        AND branch_id = '$branch_id' 
        AND quantity > 0";

if ($category) {
    // Escape the string for safety
    $safe_cat = $conn->real_escape_string($category);
    $sql .= " AND category = '$safe_cat'";
}

$sql .= " GROUP BY item_name ORDER BY item_name ASC";

$result = $conn->query($sql);
$products = [];

while($row = $result->fetch_assoc()) {
    $row['image_url'] = "http://localhost/pharmacy_v1-master/uploads/products/" . $row['product_image'];
    $products[] = $row;
}

echo json_encode([
    "status" => "success",
    "category_filter" => $category ? $category : "All",
    "data" => $products
]);
?>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h4 class="fw-bold text-primary">Edit Online Display: <?php echo $item['item_name']; ?></h4>
                <hr>
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Online Display Price (K)</label>
                            <input type="number" step="0.01" name="online_price" class="form-control" value="<?php echo $item['price']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="prod_img" class="form-control">
                            <small class="text-muted">Current: <?php echo $item['product_image']; ?></small>
                        </div>
                    </div>
                    <button type="submit" name="update_product" class="btn btn-success px-4">Save to Online App</button>
                    <a href="online_manager.php" class="btn btn-light">Cancel</a>
                </form>
            </div>
        </div>
    </div>
    <?php require "../includes/footer.php"; ?>
</div>