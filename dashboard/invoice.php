<!DOCTYPE html>
<html dir="ltr" lang="en">

<?php 
session_start();
require "../includes/head.php";
require "../includes/conn.php"; // Include connection at the top

// Multi-tenant Session Check
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id) {
    header('location: ../index.php');
    exit();
}

/** * Improved Random Invoice Logic
 * We use bin2hex for a more professional-looking alphanumeric code
 */
function createInvoiceCode() {
    return 'INV-' . strtoupper(substr(md5(microtime()), 0, 8));
}
$finalcode = createInvoiceCode();
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
                        <h4 class="page-title">Purchase Invoices</h4>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Purchase Orders</li>
                            </ol>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-md-flex align-items-center">
                                    <div>
                                        <h4 class="card-title">Manage Purchase Orders</h4>
                                        <p class="card-subtitle">Orders from your suppliers</p>
                                    </div>
                                    <div class="ms-auto">
                                        <a href="create_invoice.php?invoice=<?php echo $finalcode;?>" class="btn btn-warning text-dark fw-bold">
                                            <i class="fas fa-plus"></i> Create New Invoice
                                        </a>
                                        <a href="purchase_report.php" class="btn btn-success text-white">
                                            <i class="fas fa-filter"></i> Filter Reports
                                        </a> 
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table v-middle table-hover">
                                    <thead>
                                        <tr class="bg-light">
                                            <th class="border-top-0">#</th>
                                            <th class="border-top-0">Invoice No</th>
                                            <th class="border-top-0">Medicine Name</th> 
                                            <th class="border-top-0">Total Price</th> 
                                            <th class="border-top-0">Status</th> 
                                            <th class="border-top-0">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="output">
                                        <?php 
                                        // 1. Pagination Logic
                                        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                                        $num_per_page = 10;
                                        $start_from = ($page - 1) * $num_per_page;

                                        // 2. Multi-tenant Query (Crucial fix: Filter by pharmacy_id)
                                        $sql = "SELECT * FROM purchase_order 
                                                WHERE pharmacy_id = ? 
                                                ORDER BY id DESC 
                                                LIMIT ?, ?";
                                        
                                        $stmt = $conn->prepare($sql);
                                        $stmt->bind_param("iii", $pharmacy_id, $start_from, $num_per_page);
                                        $stmt->execute();
                                        $res = $stmt->get_result();

                                        $sn = $start_from + 1;
                                        if ($res->num_rows > 0) {
                                            while ($rows = $res->fetch_assoc()) {
                                                $status = $rows['status'];
                                                $p_no   = htmlspecialchars($rows['purchase_no']);
                                                ?>
                                                <tr>
                                                    <td><?php echo $sn++; ?></td>
                                                    <td class="font-medium"><?php echo $p_no; ?></td>
                                                    <td><?php echo htmlspecialchars($rows['medicine_name']); ?></td>
                                                    <td><strong>K<?php echo number_format($rows['price'], 2); ?></strong></td>
                                                    <td>
                                                        <?php if ($status == 0): ?>
                                                            <span class="label label-danger">Unpaid</span>
                                                        <?php else: ?>
                                                            <span class="label label-success">Paid</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($status == 0): ?>
                                                            <a href="settle_invoice.php?purchase_no=<?php echo $p_no; ?>" class="btn btn-sm btn-outline-success">Pay Now</a>
                                                        <?php else: ?>
                                                            <a href="view_invoice.php?purchase_no=<?php echo $p_no; ?>" class="btn btn-sm btn-outline-info">View</a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        } else {
                                            echo "<tr><td colspan='6' class='text-center text-muted'>No invoices found.</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-center mt-3">
                    <?php 
                    $count_sql = "SELECT COUNT(*) AS total FROM purchase_order WHERE pharmacy_id = ?";
                    $c_stmt = $conn->prepare($count_sql);
                    $c_stmt->bind_param("i", $pharmacy_id);
                    $c_stmt->execute();
                    $total_record = $c_stmt->get_result()->fetch_assoc()['total'];
                    $total_pages = ceil($total_record / $num_per_page);

                    if ($page > 1) {
                        echo "<a href='invoice.php?page=".($page-1)."' class='btn btn-sm btn-outline-warning mx-1'>Previous</a>";
                    }
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $active = ($i == $page) ? "btn-warning" : "btn-outline-success";
                        echo "<a href='invoice.php?page=".$i."' class='btn btn-sm $active mx-1'>$i</a>";
                    }
                    if ($page < $total_pages) {
                        echo "<a href='invoice.php?page=".($page+1)."' class='btn btn-sm btn-outline-warning mx-1'>Next</a>";
                    }
                    ?>
                </div>
            </div>

            <footer class="footer text-center">
                &copy; <?php echo date('Y'); ?> Echo Prime Ltd. All Rights Reserved.
            </footer>
        </div>
    </div>

    <script src="assets/libs/jquery/dist/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/app-style-switcher.js"></script>
    <script src="dist/js/waves.js"></script>
    <script src="dist/js/sidebarmenu.js"></script>
    <script src="dist/js/custom.js"></script>

    <script type="text/javascript">
    $(document).ready(function(){
        // Live Search for Invoices
        $("#search").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#output tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    });
    </script>
</body>
</html>