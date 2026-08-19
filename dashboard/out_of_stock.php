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

// 1. Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// 2. Fetch Out of Stock Items (Grouped by item_name, strength, category, barcode)
$sql = "SELECT 
            MAX(id) as id, 
            item_name, 
            strength, 
            category, 
            SUM(quantity) as total_qty, 
            barcode, 
            MAX(cost) as cost, 
            MAX(price) as price,
            MAX(expiry_date) as latest_expiry
        FROM store_items 
        WHERE pharmacy_id = ? 
        AND branch_id = ?
        GROUP BY item_name, strength, category, barcode
        HAVING total_qty <= 0
        ORDER BY item_name ASC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($stmt);
$out_of_stock_res = mysqli_stmt_get_result($stmt);

$export_data = [];

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

.badge-qty {
    background-color: #ffeded;
    color: #dc3545;
    font-weight: 700;
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid #ffcccc;
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
                    <span class="text-muted small">Branch: <b><?php echo htmlspecialchars($display_bran); ?></b> | Date: <b class="text-dark"><?php echo date('d F Y'); ?></b></span>
                </div>
                <div class="no-print">
                    <button onclick="downloadCSV()" class="btn btn-dark btn-sm px-3 shadow-sm">
                        <i class="fas fa-file-download me-1"></i> Download Restock List
                    </button>
                </div>
            </div>

            <div class="report-table-container">
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60">#</th>
                                <th>Product Description</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th class="text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            if ($out_of_stock_res && mysqli_num_rows($out_of_stock_res) > 0) {
                                while ($row = mysqli_fetch_assoc($out_of_stock_res)) {
                                    
                                    // Collect data for CSV Export
                                    $export_data[] = [
                                        $row['item_name'], 
                                        $row['strength'], 
                                        $row['cost'], 
                                        $row['price'],
                                        $row['total_qty'], 
                                        $row['category'], 
                                        $row['barcode'],
                                        (!empty($row['latest_expiry']) && $row['latest_expiry'] !== '0000-00-00' ? date('d/m/Y', strtotime($row['latest_expiry'])) : 'N/A')
                                    ];

                                    ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-muted"><?php echo $i; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($row['strength'] ?: 'N/A'); ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category'] ?: 'General'); ?></span></td>
                                        <td><span class="badge-qty"><?php echo (int)$row['total_qty']; ?></span></td>
                                        <td class="text-center no-print">
                                            <a href="update_items_stock.php?id=<?php echo $row['id']; ?>" class="btn btn-success btn-sm px-3">
                                                <i class="fas fa-plus-circle me-1"></i> RESTOCK
                                            </a>
                                        </td>
                                    </tr>
                                    <?php
                                    $i++;
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center py-5 text-muted'>All items are currently in stock for this branch.</td></tr>";
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function downloadCSV() {
    const data = <?php echo json_encode($export_data); ?>;
    if (!data || data.length === 0) {
        alert("No items to export!");
        return;
    }
    const headers = ["Product Name", "Strength", "Cost Price", "Selling Price", "Quantity", "Category", "Barcode", "Expiry Date (DD/MM/YYYY)"];
    let csvContent = headers.join(",") + "\n";
    data.forEach(row => {
        let cleanRow = row.map(val => `"${(val || "").toString().replace(/"/g, '""')}"`);
        csvContent += cleanRow.join(",") + "\n";
    });
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.setAttribute("download", "RESTOCK_<?php echo date('d_m_Y'); ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>
