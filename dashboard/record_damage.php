<!DOCTYPE html>
<html dir="ltr" lang="en">

<?php 
session_start();
require "../includes/head.php";
require "../includes/conn.php";

// Get session data for multi-tenancy
$pharmacy_id = $_SESSION['pharmacy_id'];
$branch_id = $_SESSION['branch_id'];

// Check if an ID was passed in the URL
if (!isset($_GET['id'])) {
    header("Location: pharmacy_stock.php");
    exit();
}

$id = (int)$_GET['id'];

// Fetch the specific item safely using Prepared Statements
$stmt = $conn->prepare("SELECT * FROM store_items WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
$stmt->bind_param("iii", $id, $pharmacy_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $item_name = $row['item_name']; // This already contains "Name + Strength"
    $current_qty = $row['quantity'];
    $unit_price = $row['price'];
} else {
    die("Item not found or access denied.");
}
?>

<body>
    <div class="preloader">
        <div class="lds-ripple">
            <div class="lds-pos"></div>
            <div class="lds-pos"></div>
        </div>
    </div>

    <div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5" data-sidebartype="full"
        data-sidebar-position="absolute" data-header-position="absolute" data-boxed-layout="full">
        
        <?php require "../includes/header.php"?>
        <?php require "../includes/aside.php";?>

        <div class="page-wrapper">
            <div class="page-breadcrumb">
                <div class="row align-items-center">
                    <div class="col-5">
                        <h4 class="page-title">Inventory Management</h4>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="pharmacy_stock.php">Stock</a></li>
                                <li class="breadcrumb-item active">Record Damage</li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h4 class="card-title text-danger"><i class="mdi mdi-alert-octagon"></i> Record Damaged Medicine</h4>
                                <hr>
                                
                                <form class="form-horizontal form-material" action="includes/process_damage.php" method="post">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="fw-bold">Medicine Name & Strength</label>
                                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($item_name); ?>" readonly>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="fw-bold">Current Stock</label>
                                            <input type="text" class="form-control bg-light" value="<?php echo number_format($current_qty); ?>" readonly>
                                        </div>

                                        <input type="hidden" name="price" value="<?php echo $unit_price; ?>">
                                        <input type="hidden" name="item_id" value="<?php echo $id; ?>">
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-md-4 mb-3">
                                            <label class="text-danger fw-bold">Quantity Damaged / Expired</label>
                                            <input type="number" name="qty_damaged" class="form-control border-danger" 
                                                   placeholder="Enter amount to remove" min="1" max="<?php echo $current_qty; ?>" required>
                                            <small class="text-muted">This will be subtracted from total stock.</small>
                                        </div>

                                        <div class="col-md-8 mb-3">
                                            <label class="fw-bold">Reason / Notes</label>
                                            <input type="text" name="notes" class="form-control" placeholder="e.g. Expired, Broken bottle, etc.">
                                        </div>
                                    </div>

                                    <div class="form-group mt-4">
                                        <button class="btn btn-danger text-white px-4" type="submit" name="record_loss">
                                            Confirm Stock Reduction
                                        </button>
                                        <a href="pharmacy_stock.php" class="btn btn-outline-secondary px-4">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="footer text-center">
                &copy; <?php echo date('Y'); ?> Echo Prime Ltd - Pharmacy Management System
            </footer>
        </div>
    </div>

    <script src="assets/libs/jquery/dist/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/waves.js"></script>
    <script src="dist/js/sidebarmenu.js"></script>
    <script src="dist/js/custom.js"></script>
</body>
</html>