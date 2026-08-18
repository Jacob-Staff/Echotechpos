<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";
date_default_timezone_set('Africa/Lusaka');
$today = date('Y-m-d');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
$branch_id   = $_SESSION['branch_id'] ?? null;
$branch_name = $_SESSION['branch_name'] ?? 'Main Branch';

// Fetch Pharmacy Name if missing
if (!isset($_SESSION['pharmacy_name'])) {
    $name_sql = "SELECT name FROM pharmacies WHERE id = ? LIMIT 1";
    $stmt_n = $conn->prepare($name_sql);
    $stmt_n->bind_param("i", $pharmacy_id);
    $stmt_n->execute();
    $name_res = $stmt_n->get_result();
    if($row_n = $name_res->fetch_assoc()){
        $_SESSION['pharmacy_name'] = $row_n['name'];
    }
    $stmt_n->close();
}
$pharmacy_display_name = $_SESSION['pharmacy_name'] ?? 'Pharmacy System';

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$success = $error = '';

// ✅ Handle clear all (Logic maintained)
if(isset($_POST['clear_expired'])){
    // ✅ Line 35: Updated for 'Purge All' logic
$delete_sql = "DELETE FROM store_items WHERE pharmacy_id = ? AND branch_id = ? AND expiry_date <= ? AND id NOT IN (SELECT DISTINCT product_id FROM sales_items)";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("iis", $pharmacy_id, $branch_id, $today);
    if($stmt->execute()){
        $count = $stmt->affected_rows;
        $success = ($count > 0) ? "Successfully purged $count expired items." : "No eligible items found for deletion.";
    } else { $error = "Error: " . $stmt->error; }
    $stmt->close();
}

// ✅ Handle disposal (Logic maintained)
if(isset($_POST['dispose_product'])){
    $product_id = intval($_POST['product_id']);
    $delete_sql = "DELETE FROM store_items WHERE id = ? AND branch_id = ? AND id NOT IN (SELECT DISTINCT product_id FROM sales_items)";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("ii", $product_id, $branch_id);
    if($stmt->execute() && $stmt->affected_rows > 0){ $success = "Product removed from inventory."; } 
    else { $error = "Could not dispose item. It may be linked to sales history."; }
    $stmt->close();
}

// ✅ Pagination & Fetching
$num_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($page-1) * $num_per_page;

$sql = "SELECT item_name, category, quantity, expiry_date, id FROM store_items 
        WHERE pharmacy_id = ? AND branch_id = ? AND expiry_date < ? 
        ORDER BY expiry_date ASC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iisii", $pharmacy_id, $branch_id, $today, $start_from, $num_per_page);
$stmt->execute();
$expired_products = $stmt->get_result();
?>

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
        .page-wrapper { padding-top: 15px; }
        
        /* Matching the Stats Style */
        .stat-card { border: none; border-radius: 4px; color: white; margin-bottom: 20px; }
        .bg-matrix-red { background: #ef5350; box-shadow: 0 4px 10px rgba(239, 83, 80, 0.2); }
        .stat-card h2 { font-size: 1.8rem; margin: 0; font-weight: 700; }
        .stat-card p { margin: 0; opacity: 0.85; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }

        /* Table Box Styling */
        .table-box { background: #fff; border-radius: 4px; border: 1px solid #e9ecef; }
        .table thead th { background-color: #1f262d; color: #fff; font-size: 12px; padding: 15px 12px; }
        .table tbody td { font-size: 13.5px; padding: 12px; vertical-align: middle; border-bottom: 1px solid #f8f9fa; }
        
        .form-control-search { border: 1px solid #ced4da; border-radius: 4px; padding: 8px 15px; }
        .form-control-search:focus { border-color: #22a7f0; box-shadow: none; }
    </style>
            
            <div class="row align-items-center mb-4">
                <div class="col-md-6">
                    <h4 class="fw-bold text-dark mb-0"><?php echo strtoupper($pharmacy_display_name); ?></h4>
                    <span class="text-danger small"><i class="mdi mdi-alert-decagram"></i> Expired Stock: <b><?php echo $branch_name; ?></b></span>
                </div>
                <div class="col-md-6 text-end">
                    <form method="post" onsubmit="return confirm('Purge all expired items for this branch? This cannot be undone.')" class="d-inline">
                        <button type="submit" name="clear_expired" class="btn btn-danger btn-sm px-3 me-2 shadow-sm">
                            <i class="mdi mdi-delete-sweep"></i> Purge All
                        </button>
                    </form>
                    <button class="btn btn-dark btn-sm px-3 shadow-sm" onclick="exportToPDF()">
                        <i class="mdi mdi-file-pdf"></i> Export Report
                    </button>
                </div>
            </div>

            <?php if($success): ?> <div class="alert alert-success border-0 shadow-sm"><?php echo $success; ?></div> <?php endif; ?>
            <?php if($error): ?> <div class="alert alert-danger border-0 shadow-sm text-white bg-danger"><?php echo $error; ?></div> <?php endif; ?>

            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-body p-2">
                    <input type="text" class="form-control form-control-search" id="search" placeholder="Search product name, category or ID...">
                </div>
            </div>

            <div class="table-box shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="expired-table">
                        <thead>
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Qty Left</th>
                                <th>Expiry Date</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expired-body">
                            <?php if($expired_products->num_rows > 0): $c = $start_from + 1; while($row = $expired_products->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-3 text-muted"><?php echo $c++; ?></td>
                                    <td><b class="text-dark"><?php echo htmlspecialchars($row['item_name']); ?></b></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category']); ?></span></td>
                                    <td><?php echo $row['quantity']; ?></td>
                                    <td><span class="text-danger fw-bold"><?php echo date('d M Y', strtotime($row['expiry_date'])); ?></span></td>
                                    <td class="text-center">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="product_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="dispose_product" class="btn btn-link btn-sm text-danger p-0" title="Dispose">
                                                <i class="mdi mdi-trash-can-outline fs-5"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">Excellent! No expired items found in this branch.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script>
    // PDF Export Logic (Maintained)
    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        const pharmacyName = "<?php echo addslashes($pharmacy_display_name); ?>";
        const branchName = "<?php echo addslashes($branch_name); ?>";
        const reportDate = "<?php echo date('d-M-Y H:i'); ?>";

        doc.setFontSize(16);
        doc.text(`${pharmacyName.toUpperCase()} - EXPIRED STOCK`, 14, 20);
        doc.setFontSize(10);
        doc.text(`Branch: ${branchName} | Date: ${reportDate}`, 14, 28);

        doc.autoTable({
            html: '#expired-table',
            startY: 35,
            theme: 'grid',
            headStyles: { fillColor: [31, 38, 45] },
            columns: [
                { header: '#', dataKey: '0' },
                { header: 'Product Name', dataKey: '1' },
                { header: 'Category', dataKey: '2' },
                { header: 'Qty', dataKey: '3' },
                { header: 'Expiry Date', dataKey: '4' }
            ]
        });
        doc.save(`${pharmacyName}_Expired_Stock.pdf`);
    }

    // Live Search
    $(document).ready(function(){
        $('#search').on('keyup', function(){
            var value = $(this).val().toLowerCase();
            $("#expired-body tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    });
</script>
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>