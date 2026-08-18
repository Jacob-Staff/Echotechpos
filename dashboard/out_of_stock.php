<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";
date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;
$current_date = date('Y-m-d');

$pharmacy_name = "Pharmacy System";
$branch_name   = "Main Branch";

if ($pharmacy_id && $branch_id) {
    $info_sql = "SELECT p.name AS p_name, b.branch_name AS b_name 
                 FROM pharmacies p 
                 JOIN branches b ON p.id = b.pharmacy_id 
                 WHERE p.id = '$pharmacy_id' AND b.id = '$branch_id' LIMIT 1";
    
    $info_res = mysqli_query($conn, $info_sql);
    if ($info_res && $info_row = mysqli_fetch_assoc($info_res)) {
        $pharmacy_name = $info_row['p_name'];
        $branch_name   = $info_row['b_name'];
    }
}

/**
 * THE FIX: Added MAX() for cost, price, and expiry_date 
 * so they are available for the while loop and CSV export.
 */
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
        WHERE pharmacy_id = '$pharmacy_id' 
        AND branch_id = '$branch_id'
        GROUP BY item_name, strength, category, barcode
        HAVING total_qty <= 0
        ORDER BY item_name ASC";

$out_of_stock_res = mysqli_query($conn, $sql);
$export_data = [];
?>
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; }
        .page-wrapper { padding: 30px; min-height: 100vh; }
        .header-section { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px; border-left: 5px solid #dc3545; }
        .table thead th { background-color: #dc3545; color: #fff; text-transform: uppercase; font-size: 11px; padding: 15px; border: none; }
        .badge-qty { background-color: #ffeded; color: #dc3545; font-weight: 700; padding: 5px 12px; border-radius: 6px; border: 1px solid #ffcccc; }
    </style>

        <div class="header-section d-flex justify-content-between align-items-center">
            <div>
                <h2 class="fw-bold text-dark mb-1"><?php echo strtoupper($pharmacy_name); ?></h2>
                <p class="text-muted mb-0">Branch: <strong><?php echo $branch_name; ?></strong> | <?php echo date('d F Y'); ?></p>
            </div>
            <button onclick="downloadCSV()" class="btn btn-dark btn-lg px-4 shadow-sm">
                <i class="mdi mdi-file-download"></i> DOWNLOAD RESTOCK LIST
            </button>
        </div>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th width="60">#</th>
                            <th>Product Description</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $i = 1;
                    if ($out_of_stock_res && mysqli_num_rows($out_of_stock_res) > 0) {
                        while ($row = mysqli_fetch_assoc($out_of_stock_res)) {
                            
                            // Prepare CSV Data using the correct aggregated keys
                            $export_data[] = [
                                $row['item_name'], 
                                $row['strength'], 
                                $row['cost'], 
                                $row['price'],
                                $row['total_qty'], 
                                $row['category'], 
                                $row['barcode'],
                                ($row['latest_expiry'] != '0000-00-00' && !empty($row['latest_expiry']) ? date('d/m/Y', strtotime($row['latest_expiry'])) : 'N/A')
                            ];

                            echo "<tr>
                                    <td>$i</td>
                                    <td>
                                        <div class='fw-bold'>".htmlspecialchars($row['item_name'])."</div>
                                        <div class='text-muted small'>".htmlspecialchars($row['strength'])."</div>
                                    </td>
                                    <td><span class='badge bg-light text-dark border'>".htmlspecialchars($row['category'])."</span></td>
                                    <td><span class='badge-qty'>{$row['total_qty']}</span></td>
                                    <td class='text-center'>
                                        <a href='update_items_stock.php?id={$row['id']}' class='btn btn-success btn-sm px-3'>RESTOCK</a>
                                    </td>
                                  </tr>";
                            $i++;
                        }
                    } else {
                        echo "<tr><td colspan='5' class='text-center p-5 text-muted'>All items are currently in stock for this branch.</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> 
</div>

<script>
function downloadCSV() {
    const data = <?php echo json_encode($export_data); ?>;
    if (data.length === 0) return alert("No items to export!");
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
    link.click();
}
</script>
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>