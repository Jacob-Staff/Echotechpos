<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);

$pharm_name = "PHARMANOVA"; 
$pharm_res = mysqli_query($conn, "SELECT name FROM pharmacies WHERE id = $p_id LIMIT 1");
if ($pharm_res && $p_row = mysqli_fetch_assoc($pharm_res)) { $pharm_name = $p_row['name']; }

$branch_label = "Pharmanova LSK";
$branch_res = mysqli_query($conn, "SELECT branch_name FROM branches WHERE id = $b_id LIMIT 1");
if ($branch_res && $b_row = mysqli_fetch_assoc($branch_res)) { $branch_label = $b_row['branch_name']; }
?>

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f7fa; }
        .page-wrapper { padding-top: 30px; }
        .identity-banner { background: #fff; padding: 25px; border-radius: 15px; border-left: 8px solid #22a7f0; margin-bottom: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .action-card { background: #fff; border-radius: 15px; padding: 40px 20px; text-align: center; border: 1px solid #eef2f6; transition: 0.3s; cursor: pointer; height: 100%; }
        .action-card:hover { transform: translateY(-5px); border-color: #22a7f0; box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .icon-box { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; }
        .bg-blue { background: #f0f7ff; color: #22a7f0; }
        .bg-green { background: #f0fff4; color: #28a745; }
        .bg-orange { background: #fff7ed; color: #ffb848; }
    </style>

        <div class="container-fluid">
            <div class="identity-banner">
                <h2 class="fw-bold mb-1"><?php echo strtoupper(htmlspecialchars($pharm_name)); ?></h2>
                <p class="text-muted mb-0"><i class="mdi mdi-store-marker me-1 text-primary"></i> <b><?php echo htmlspecialchars($branch_label); ?></b></p>
            </div>

            <div class="row g-4 text-center">
                <div class="col-md-4">
                    <div class="action-card shadow-sm" data-bs-toggle="modal" data-bs-target="#singleItemModal">
                        <div class="icon-box bg-green"><i class="mdi mdi-pill"></i></div>
                        <h5 class="fw-bold">ADD 1 PRODUCT</h5>
                        <p class="text-muted small">Add single item with Strength details.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="action-card shadow-sm" onclick="document.getElementById('excelFile').click()">
                        <div class="icon-box bg-blue"><i class="mdi mdi-upload"></i></div>
                        <h5 class="fw-bold">BULK UPDATE</h5>
                        <p class="text-muted small">Upload CSV for mass updates.</p>
                        <form action="../includes/process_bulk_stock.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                            <input type="file" name="stock_file" id="excelFile" hidden onchange="submitWithLoading()">
                        </form>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="action-card shadow-sm" onclick="downloadCSVTemplate()">
                        <div class="icon-box bg-orange"><i class="mdi mdi-file-download"></i></div>
                        <h5 class="fw-bold">GET FORMAT</h5>
                        <p class="text-muted small">Download correct CSV template.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow text-center p-4">
            <div class="modal-body">
                <i class="mdi mdi-check-circle text-success" style="font-size: 50px;"></i>
                <h3 class="fw-bold mt-3">Success!</h3>
                <p class="text-muted">Stock update has been completed successfully.</p>
                <button type="button" class="btn btn-success px-4 rounded-pill" data-bs-dismiss="modal">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="singleItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header bg-light">
                <h5 class="fw-bold mb-0">Single Product Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="../includes/add_single_item.php" method="POST">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Product Name</label>
                            <input type="text" name="item_name" class="form-control" placeholder="e.g. Panadol" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Strength</label>
                            <input type="text" name="strength" class="form-control" placeholder="e.g. 500mg" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Cost Price (K)</label>
                            <input type="number" step="0.01" name="cost" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Selling Price (K)</label>
                            <input type="number" step="0.01" name="price" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Quantity</label>
                            <input type="number" name="quantity" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Barcode</label>
                            <input type="text" name="barcode" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-primary px-4 rounded-pill">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Auto-show modal if success is in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('status') === 'success') {
            var myModal = new bootstrap.Modal(document.getElementById('successModal'));
            myModal.show();
            // Clean URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    function downloadCSVTemplate() {
        const headers = "Product Name,Strength,Cost Price,Selling Price,Quantity,Category,Barcode,Expiry Date (DD/MM/YYYY)\n";
        const sample = "Panadol,500mg,10.00,15.00,100,Medicine,6001234567,31/12/2026\n";
        const blob = new Blob(["\uFEFF" + headers + sample], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "STOCK_TEMPLATE.csv";
        link.click();
    }
    function submitWithLoading() {
        document.querySelector('.bg-blue').innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
        document.getElementById('uploadForm').submit();
    }
</script>
<<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>