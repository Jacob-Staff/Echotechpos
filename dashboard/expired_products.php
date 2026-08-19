<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)$_SESSION['pharmacy_id'];
$b_id = (int)$_SESSION['branch_id'];
$today = date('Y-m-d');

$success = '';
$error = '';

// 1. Handle Clear All Expired Items
if (isset($_POST['clear_expired'])) {
    $delete_sql = "DELETE FROM store_items 
                   WHERE pharmacy_id = ? 
                   AND branch_id = ? 
                   AND expiry_date <= ? 
                   AND id NOT IN (SELECT DISTINCT product_id FROM sales_items WHERE product_id IS NOT NULL)";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("iis", $p_id, $b_id, $today);
    if ($stmt->execute()) {
        $count = $stmt->affected_rows;
        $success = ($count > 0) ? "Successfully purged $count expired items." : "No eligible items found for deletion (some may be linked to sales history).";
    } else { 
        $error = "Error purging items: " . $stmt->error; 
    }
    $stmt->close();
}

// 2. Handle Single Product Disposal
if (isset($_POST['dispose_product'])) {
    $product_id = intval($_POST['product_id']);
    $delete_sql = "DELETE FROM store_items 
                   WHERE id = ? 
                   AND branch_id = ? 
                   AND id NOT IN (SELECT DISTINCT product_id FROM sales_items WHERE product_id IS NOT NULL)";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("ii", $product_id, $b_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) { 
        $success = "Product removed from inventory."; 
    } else { 
        $error = "Could not dispose item. It may be linked to transaction history."; 
    }
    $stmt->close();
}

// 3. Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// 4. Pagination & Query Fetch
$num_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$start_from = ($page - 1) * $num_per_page;

$sql = "SELECT id, item_name, strength, category, quantity, expiry_date 
        FROM store_items 
        WHERE pharmacy_id = ? AND branch_id = ? AND expiry_date < ? 
        ORDER BY expiry_date ASC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iisii", $p_id, $b_id, $today, $start_from, $num_per_page);
$stmt->execute();
$expired_products = $stmt->get_result();

require_once "../includes/head.php";
?>

<style>
.report-wrapper {
    background-color: #ffffff !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.header-section {
    background: #ffffff;
    padding: 1.25rem 1.5rem;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    border-left: 5px solid #dc3545;
    margin-bottom: 1.5rem;
}

.report-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead th {
    background: #dc3545;
    padding: 14px;
    text-align: left;
    font-size: 12px;
    color: #ffffff !important;
    text-transform: uppercase;
    border-bottom: 2px solid #b02a37;
}

.report-table tbody td {
    padding: 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: #f8f9fa;
}

.form-control-search {
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 8px 15px;
}

.form-control-search:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

@media print {
    .no-print { display: none !important; }
    .report-wrapper { padding: 0 !important; }
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper report-wrapper">
        <div class="container-fluid p-0">
            
            <div class="header-section d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                    <span class="text-danger small"><i class="fas fa-exclamation-triangle me-1"></i> Expired Stock | Branch: <b><?php echo htmlspecialchars($display_bran); ?></b> | Date: <b class="text-dark"><?php echo date('d F Y'); ?></b></span>
                </div>
                <div class="no-print">
                    <form method="post" onsubmit="return confirm('Purge all eligible expired items for this branch? This action cannot be undone.')" class="d-inline">
                        <button type="submit" name="clear_expired" class="btn btn-danger btn-sm px-3 me-2 shadow-sm">
                            <i class="fas fa-trash-alt me-1"></i> Purge All
                        </button>
                    </form>
                    <button type="button" class="btn btn-dark btn-sm px-3 shadow-sm" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf me-1"></i> Export PDF
                    </button>
                </div>
            </div>

            <?php if ($success): ?> 
                <div class="alert alert-success border-0 shadow-sm mb-3"><?php echo htmlspecialchars($success); ?></div> 
            <?php endif; ?>
            <?php if ($error): ?> 
                <div class="alert alert-danger border-0 shadow-sm mb-3"><?php echo htmlspecialchars($error); ?></div> 
            <?php endif; ?>

            <div class="card mb-3 border shadow-sm no-print">
                <div class="card-body p-2">
                    <input type="text" class="form-control form-control-search" id="search" placeholder="Search product name, strength, category or ID...">
                </div>
            </div>

            <div class="report-table-container">
                <div class="table-responsive">
                    <table class="report-table" id="expired-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60">#</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Qty Left</th>
                                <th>Expiry Date</th>
                                <th class="text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expired-body">
                            <?php if ($expired_products && $expired_products->num_rows > 0): ?>
                                <?php $c = $start_from + 1; ?>
                                <?php while ($row = $expired_products->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?php echo $c++; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($row['strength'] ?: 'N/A'); ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category'] ?: 'General'); ?></span></td>
                                        <td><span class="badge bg-danger text-white"><?php echo (int)$row['quantity']; ?></span></td>
                                        <td><span class="text-danger fw-bold"><?php echo date('d M Y', strtotime($row['expiry_date'])); ?></span></td>
                                        <td class="text-center no-print">
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to dispose of this product?');">
                                                <input type="hidden" name="product_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="dispose_product" class="btn btn-sm btn-outline-danger" title="Dispose">
                                                    <i class="fas fa-trash me-1"></i> Dispose
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">Excellent! No expired items found in this branch.</td>
                                </tr>
                            <?php endif; ?>
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- PDF Export Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<script>
    // Live Search Logic
    $(document).ready(function(){
        $('#search').on('keyup', function(){
            var value = $(this).val().toLowerCase();
            $("#expired-body tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });
    });

    // PDF Export Logic
    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        const pharmacyName = <?php echo json_encode($display_pharm); ?>;
        const branchName = <?php echo json_encode($display_bran); ?>;
        const reportDate = <?php echo json_encode(date('d-M-Y H:i')); ?>;

        doc.setFontSize(16);
        doc.text(`${pharmacyName.toUpperCase()} - EXPIRED STOCK REPORT`, 14, 20);
        doc.setFontSize(10);
        doc.text(`Branch: ${branchName} | Date: ${reportDate}`, 14, 28);

        doc.autoTable({
            html: '#expired-table',
            startY: 35,
            theme: 'grid',
            headStyles: { fillColor: [220, 53, 69], textColor: [255, 255, 255] },
            columns: [
                { header: '#', dataKey: '0' },
                { header: 'Product Name', dataKey: '1' },
                { header: 'Category', dataKey: '2' },
                { header: 'Qty Left', dataKey: '3' },
                { header: 'Expiry Date', dataKey: '4' }
            ]
        });
        doc.save(`${pharmacyName}_Expired_Stock.pdf`);
    }
</script>
</body>
</html>
