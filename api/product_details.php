<?php
require "store_header.php"; 
require_once(__DIR__ . "/../includes/conn.php");

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 13;

// Join store_items with clinical info
$query = "SELECT s.*, i.* FROM store_items s 
          LEFT JOIN product_details_info i ON s.id = i.product_id 
          WHERE s.id = ? AND s.branch_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $product_id, $branch_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) { die("Product not found."); }

$safety = json_decode($product['safety_warnings'], true) ?? [];
$fact_box = json_decode($product['fact_box'], true) ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo $product['item_name']; ?> | Echo Prime</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
:root {
    --primary:#004a99;
    --accent:#00bcd4;
    --bg:#f6f9fc;
    --card:#ffffff;
}

body{
    background:var(--bg);
    font-family: 'Inter', sans-serif;
}

/* HERO */
.product-hero{
    background: linear-gradient(135deg,#ffffff,#f8fbff);
    padding:50px 0;
    border-bottom:1px solid #eee;
}

/* IMAGE */
.product-img{
    border-radius:20px;
    transition:0.4s;
}
.product-img:hover{
    transform:scale(1.05);
}

/* PRICE */
.price-main{
    font-size:34px;
    font-weight:800;
}
.mrp-old{
    text-decoration:line-through;
    color:#aaa;
}

/* BUTTON */
.btn-cart{
    background:linear-gradient(135deg,#004a99,#007bff);
    border:none;
    padding:14px 30px;
    border-radius:10px;
    font-weight:600;
    transition:0.3s;
}
.btn-cart:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(0,0,0,0.1);
}

/* CARD */
.card-modern{
    background:var(--card);
    border-radius:16px;
    box-shadow:0 10px 30px rgba(0,0,0,0.05);
    border:none;
}

/* NAV */
.nav-pills-custom .nav-link{
    border-radius:10px;
    margin-bottom:6px;
    color:#555;
}
.nav-pills-custom .nav-link.active{
    background:var(--primary);
    color:#fff;
}

/* TAB */
.tab-content-wrapper{
    border-radius:16px;
    overflow:hidden;
}

/* SECTION */
.section-title{
    font-weight:700;
    margin-bottom:20px;
}

/* SAFETY */
.safety-card{
    border-radius:12px;
    padding:15px;
    background:#fafafa;
    transition:0.3s;
}
.safety-card:hover{
    background:#f0f7ff;
    transform:translateY(-3px);
}

/* NOTICE */
.notice-box{
    background:#fff8e1;
    border-left:4px solid #ffc107;
    border-radius:10px;
}
</style>
</head>

<body>

<!-- HERO -->
<div class="product-hero">
<div class="container">
<div class="row align-items-center">

<div class="col-md-5 text-center mb-4">
    <img src="../uploads/products/<?php echo $product['image']; ?>" 
         class="img-fluid product-img shadow">
</div>

<div class="col-md-7">

<h2 class="fw-bold"><?php echo $product['item_name']; ?> <?php echo $product['strength']; ?></h2>

<p class="text-muted">
By <strong class="text-primary">
<?php echo $product['manufacturer'] ?: 'Echo Prime Pharma'; ?>
</strong>
</p>

<div class="mb-3">
    <span class="mrp-old me-2">ZMW <?php echo number_format($product['price']*1.25,2); ?></span>
    <span class="price-main text-dark">ZMW <?php echo number_format($product['price'],2); ?></span>
</div>

<div class="notice-box p-3 mb-3">
<i class="bi bi-shield-lock"></i> Prescription required
</div>

<div class="d-flex gap-2">
    <input type="number" value="1" min="1" class="form-control" style="width:90px;">
    <button class="btn btn-cart text-white">
        <i class="bi bi-cart-plus"></i> Add to Cart
    </button>
</div>

</div>
</div>
</div>
</div>

<!-- CONTENT -->
<div class="container mt-5 pb-5">
<div class="row">

<!-- SIDEBAR -->
<div class="col-md-3">
<div class="card-modern p-3 sticky-top">

<div class="nav flex-column nav-pills nav-pills-custom" role="tablist">
<button class="nav-link active" data-bs-toggle="pill" data-bs-target="#about">Overview</button>
<button class="nav-link" data-bs-toggle="pill" data-bs-target="#uses">Uses</button>
<button class="nav-link" data-bs-toggle="pill" data-bs-target="#effects">Side Effects</button>
<button class="nav-link" data-bs-toggle="pill" data-bs-target="#safety">Safety</button>
<button class="nav-link" data-bs-toggle="pill" data-bs-target="#mechanism">Mechanism</button>
<button class="nav-link" data-bs-toggle="pill" data-bs-target="#storage">Storage</button>
</div>

</div>
</div>

<!-- CONTENT -->
<div class="col-md-9">
<div class="tab-content card-modern p-4">

<!-- ABOUT -->
<div class="tab-pane fade show active" id="about">
<h5 class="section-title">Overview</h5>
<p class="text-muted"><?php echo $product['about_text'] ?: 'No description available.'; ?></p>
</div>

<!-- USES -->
<div class="tab-pane fade" id="uses">
<h5 class="section-title">Uses</h5>
<ul class="list-group list-group-flush">
<?php 
$uses_list = explode('.', $product['uses']);
foreach($uses_list as $use){
if(trim($use)){
echo "<li class='list-group-item'>✔ ".trim($use)."</li>";
}}
?>
</ul>
</div>

<!-- SIDE EFFECTS -->
<div class="tab-pane fade" id="effects">
<h5 class="section-title">Side Effects</h5>
<p><?php echo $product['side_effects'] ?: 'No known side effects.'; ?></p>
</div>

<!-- SAFETY -->
<div class="tab-pane fade" id="safety">
<h5 class="section-title">Safety</h5>

<?php 
foreach(['Pregnancy','Alcohol','Driving','Kidney','Liver'] as $key): ?>
<div class="safety-card mb-2 d-flex">
<div class="me-3 text-primary">
<i class="bi bi-shield-check fs-4"></i>
</div>
<div>
<strong><?php echo $key; ?></strong><br>
<small><?php echo $safety[$key] ?? 'Consult doctor'; ?></small>
</div>
</div>
<?php endforeach; ?>

</div>

<!-- MECHANISM -->
<div class="tab-pane fade" id="mechanism">
<h5 class="section-title">How it Works</h5>
<div class="alert alert-info">
<?php echo $product['how_it_works']; ?>
</div>
</div>

<!-- STORAGE -->
<div class="tab-pane fade" id="storage">
<h5 class="section-title">Storage</h5>
<div class="alert alert-secondary">
<?php echo $product['storage_info']; ?>
</div>
</div>

</div>
</div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>