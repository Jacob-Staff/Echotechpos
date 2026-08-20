<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Fetch Suppliers securely
$vnd_stmt = $conn->prepare("SELECT * FROM suppliers WHERE pharmacy_id = ? AND branch_id = ? ORDER BY name ASC");
$vnd_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$vnd_stmt->execute();
$VND_DATA = $vnd_stmt->get_result();
$total_vendors = $VND_DATA->num_rows;

require_once "../includes/head.php";
?>

<style>
    .search-box { border-radius: 20px; padding-left: 40px; background: #f8f9fa; border: 1px solid #e9ecef; }
    .search-icon { position: absolute; left: 15px; top: 10px; color: #adb5bd; }
    .vendor-card-icon { width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(30, 41, 59, 0.05); color: #1e293b; font-size: 1.2rem; }
    .table thead th { font-weight: 700; color: #495057; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
    .action-btn { transition: all 0.2s; border-radius: 6px; }
    .action-btn:hover { transform: scale(1.1); }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="page-title">Supplier Management</h4>
                    <div class="d-flex align-items-center">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                <li class="breadcrumb-item active">Suppliers</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="$('#regForm').slideToggle()">
                        <i class="mdi mdi-plus-circle me-1"></i> Register New Vendor
                    </button>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- New Supplier Form (Hidden by default) -->
            <div class="card shadow-sm border-0 mb-4" id="regForm" style="display:none; border-left: 4px solid #28a745 !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <h5 class="fw-bold"><i class="mdi mdi-clipboard-edit-outline me-2 text-success"></i>New Supplier Details</h5>
                        <button type="button" class="btn-close" onclick="$('#regForm').slideUp()"></button>
                    </div>
                    <form id="addSupplierForm">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Company Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Pharmanova LSK" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Mobile / Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="09xxxxxxx" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="vendor@domain.com">
                            </div>
                            <div class="col-md-9">
                                <label class="small text-muted fw-bold">Business Address</label>
                                <input type="text" name="address" class="form-control" placeholder="Street, Building, City">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100 fw-bold">Save Record</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Supplier Directory -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-md-flex align-items-center justify-content-between mb-4">
                        <div>
                            <h4 class="card-title mb-1">Vendor Directory</h4>
                            <h6 class="card-subtitle text-muted">Showing <span class="badge bg-light-info text-info fw-bold"><?php echo $total_vendors; ?></span> registered suppliers</h6>
                        </div>
                        <div class="position-relative mt-3 mt-md-0">
                            <i class="mdi mdi-magnify search-icon"></i>
                            <input type="text" id="vendorSearch" class="form-control search-box" placeholder="Search by name or phone...">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">No.</th>
                                    <th>Supplier Info</th>
                                    <th>Contact Details</th>
                                    <th>Location</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="vendorTableBody">
                                <?php
                                $n = 1;
                                if ($total_vendors > 0) {
                                    while ($row = $VND_DATA->fetch_assoc()) {
                                        ?>
                                        <tr>
                                            <td class="ps-4 text-muted small"><?php echo str_pad($n++, 2, "0", STR_PAD_LEFT); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="vendor-card-icon me-3">
                                                        <i class="mdi mdi-truck-check-outline"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($row['name']); ?></h6>
                                                        <small class="text-muted">Vendor ID: <?php echo $row['id']; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small fw-bold"><i class="mdi mdi-phone-outline me-1"></i><?php echo htmlspecialchars($row['phone']); ?></div>
                                                <div class="small text-muted"><i class="mdi mdi-email-outline me-1"></i><?php echo htmlspecialchars($row['email'] ?: 'No email'); ?></div>
                                            </td>
                                            <td>
                                                <div class="small text-muted">
                                                    <i class="mdi mdi-map-marker-radius-outline me-1 text-danger"></i>
                                                    <?php echo htmlspecialchars($row['address'] ?: 'Address not provided'); ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-danger del-btn action-btn" data-id="<?php echo $row['id']; ?>" title="Delete Vendor">
                                                    <i class="mdi mdi-delete-sweep"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center p-5 text-muted'>No vendors found for this branch.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Live Search Logic
    $(document).ready(function(){
        $("#vendorSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#vendorTableBody tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    });

    // Add Logic
    $('#addSupplierForm').on('submit', function(e){
        e.preventDefault();
        $.post("../includes/add_supplier_inc.php", $(this).serialize(), function(){
            location.reload(); 
        });
    });

    // Delete Logic
    $(document).on('click', '.del-btn', function(){
        if(confirm('Warning: This action cannot be undone. Delete this supplier?')){
            let sid = $(this).data('id');
            $.post("../includes/delete_supplier_inc.php", { id: sid }, function(res){
                if(res.trim() === "success") {
                    location.reload();
                } else {
                    alert("Delete failed. Refresh and try again.");
                }
            });
        }
    });
</script>

</body>
</html>
