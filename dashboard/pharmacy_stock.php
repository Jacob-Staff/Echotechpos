<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

// Set current date for expiry check
$current_date = date('Y-m-d');

// Get branch and pharmacy ID from session
$branch_id   = $_SESSION['branch_id'] ?? 0; 
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;

if ($branch_id == 0 || $pharmacy_id == 0) {
    header("Location: ../index.php?error=session_expired");
    exit();
}

// Fetch Branch Name for display
$branch_name = "Our Branch";
$b_res = mysqli_query($conn, "SELECT branch_name FROM branches WHERE id = '$branch_id' LIMIT 1");
if ($b_res && $b_row = mysqli_fetch_assoc($b_res)) { 
    $branch_name = $b_row['branch_name']; 
}

require_once "../includes/head.php";
?>

<style>
    .table.v-middle td, .table.v-middle th { padding: 12px; vertical-align: middle; }
    .btn-update { background-color: #00ffae; color: #000; border: none; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .btn-update:hover { background-color: #00e699; color: #000; transform: translateY(-1px); }
    .stock-badge { padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; }
    .bg-dark-custom { background-color: #1a1a1a !important; color: #00ffae; border-bottom: 2px solid #333; }
    .text-neon { color: #00ffae !important; }
    .total-row { background-color: #f8f9fa; border-top: 2px solid #00ffae; }
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col-12 col-md-5">
                    <h4 class="page-title text-dark fw-bold mb-0"><?php echo htmlspecialchars($branch_name); ?> Inventory</h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="dashboard.php" class="text-primary small">Dashboard</a></li>
                            <li class="breadcrumb-item active small" aria-current="page">Pharmacy Stock</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>

        <div class="container-fluid">

            <?php if (isset($_GET['status']) && $_GET['status'] == 'damaged_recorded'): ?>
                <div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm" role="alert">
                    <i class="mdi mdi-check-circle me-2"></i>
                    <strong>Stock Updated!</strong> Removed damaged units of <?php echo htmlspecialchars($_GET['item']); ?>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['status']) && $_GET['status'] == 'error'): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                    <i class="mdi mdi-alert-circle me-2"></i>
                    <strong>Error!</strong> Could not update stock. <?php echo htmlspecialchars($_GET['msg'] ?? ''); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row mb-3 align-items-center">
                <div class="col-md-5">
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="search" placeholder="Search by name or barcode...">
                    </div>
                </div>
                <div class="col-md-7 text-end mt-2 mt-md-0">
                    <a href="update_items_stock.php" class="btn btn-outline-dark rounded-pill px-3 me-2 btn-sm fw-semibold">
                        <i class="fas fa-truck-loading me-1"></i> Restock
                    </a>
                    <a href="add_product.php" class="btn btn-success rounded-pill px-3 btn-sm fw-bold text-white">
                        <i class="fas fa-plus me-1"></i> New Product
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-body border-bottom">
                            <h4 class="card-title fw-bold mb-1">Live Stock Summary</h4>
                            <p class="card-subtitle text-muted mb-0 small">Manage and track your pharmaceutical assets</p>
                        </div>
                        <div class="table-responsive">
                            <table class="table v-middle mb-0 align-middle">
                                <thead>
                                    <tr class="bg-light">
                                        <th class="ps-4">S.N</th>
                                        <th>Item Name & Description</th>
                                        <th>Unit Price</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Total Value</th>
                                        <th>Expiry Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="output">
                                <?php 
                                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                                $num_per_page = 15;
                                $start_from = ($page - 1) * $num_per_page;

                                // Filter by Branch and Pharmacy (Strict SQL compatible)
                                $where_clause = " WHERE branch_id = '$branch_id' AND pharmacy_id = '$pharmacy_id' AND (expiry_date > '$current_date' OR expiry_date IS NULL OR CAST(expiry_date AS CHAR) = '0000-00-00') AND quantity > 0 ";

                                $sql = "SELECT * FROM store_items $where_clause ORDER BY item_name ASC LIMIT $start_from, $num_per_page";
                                $res = mysqli_query($conn, $sql);

                                $total_q = "SELECT COUNT(*) AS total FROM store_items $where_clause";
                                $total_res = mysqli_query($conn, $total_q);
                                $total_row = mysqli_fetch_assoc($total_res);
                                $total_page = ceil(($total_row['total'] ?? 0) / $num_per_page);

                                $total_inventory_value = 0;

                                if ($res && mysqli_num_rows($res) > 0) {
                                    $sn = $start_from + 1;
                                    while ($row = mysqli_fetch_assoc($res)) {
                                        $price = floatval($row['price']);
                                        $qty = intval($row['quantity']);
                                        $row_total = $price * $qty;
                                        $total_inventory_value += $row_total;
                                        
                                        $is_expired = ($row['expiry_date'] < $current_date && $row['expiry_date'] != '0000-00-00' && !empty($row['expiry_date']));
                                        $stock_status = ($qty <= 10) ? 'bg-danger' : 'bg-success';
                                ?>
                                    <tr data-id="<?php echo $row['id']; ?>">
                                        <td class="ps-4 text-muted"><?php echo $sn++; ?></td>
                                        <td>
                                            <span class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($row['item_name']); ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold">K <?php echo number_format($price, 2); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category'] ?? 'Medicine'); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $stock_status; ?> text-white px-2 py-1">
                                                <?php echo number_format($qty); ?>
                                            </span>
                                        </td>
                                        <td class="text-dark fw-bold">K <?php echo number_format($row_total, 2); ?></td>
                                        <td>
                                            <?php if ($row['expiry_date'] != '0000-00-00' && !empty($row['expiry_date'])): ?>
                                                <span class="<?php echo $is_expired ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                    <?php echo date('d M Y', strtotime($row['expiry_date'])); ?>
                                                </span>
                                                <?php if ($is_expired) echo '<br><small class="badge bg-danger">EXPIRED</small>'; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="update_product.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-info btn-sm rounded-circle me-1" title="Edit"><i class="fas fa-pen"></i></a>
                                            <button type="button" class="btn btn-outline-danger btn-sm rounded-circle delete-btn" data-id="<?php echo $row['id']; ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php 
                                    } 
                                } else { 
                                ?>
                                    <tr><td colspan="8" class="text-center py-5 text-muted">No stock items found. Click "New Product" to begin.</td></tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-white p-4">
                            <div class="row justify-content-end">
                                <div class="col-md-4 text-end">
                                    <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Branch Asset Valuation</h6>
                                    <h3 class="text-success fw-bold mb-0">K <?php echo number_format($total_inventory_value ?? 0, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($total_page > 1): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_page; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="pharmacy_stock.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php"; 
}
?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function(){
    $("#search").on("keyup", function(){
        var query = $(this).val();
        $.ajax({
            url: "fetch_products.php",
            method: "POST",
            data: {query: query},
            success: function(data){
                $("#output").html(data);
            }
        });
    });

    $(document).on("click", ".delete-btn", function(){
        var id = $(this).data("id");
        var row = $(this).closest("tr");

        if(confirm("Are you sure? This will permanently remove the item from this branch's records.")){
            $.ajax({
                url: "../includes/delete_product_inc.php",
                type: "POST",
                data: {id: id},
                success: function(resp){
                    if(resp.includes("success")){
                        row.fadeOut(400, function(){ $(this).remove(); });
                    } else {
                        alert("Error: Delete failed. Ensure you have the required permissions.");
                    }
                }
            });
        }
    });
});
</script>
</body>
</html>
