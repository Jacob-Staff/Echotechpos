<?php
require "../includes/conn.php";

$limit = 10; // results per page
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$offset = ($page - 1) * $limit;
$query = isset($_POST['query']) ? trim($_POST['query']) : "";

$all_results = [];

// 🔍 1. Search Pharmacy Stock
$sql1 = "SELECT 'stock' AS src, id, medicine_name AS title, CONCAT('Qty: ', pharmacy_Qty) AS subtitle, expiry_date AS extra 
         FROM pharmacy_stock 
         WHERE medicine_name LIKE ? OR type LIKE ? LIMIT ?, ?";
$stmt = $conn->prepare($sql1);
$like = "%$query%";
$stmt->bind_param("ssii", $like, $like, $offset, $limit);
$stmt->execute();
$res1 = $stmt->get_result();
while($r = $res1->fetch_assoc()) $all_results[] = $r;

// 🔍 2. Store Items
$sql2 = "SELECT 'store' AS src, id, item_name AS title, category AS subtitle, capacity AS extra 
         FROM store_items 
         WHERE item_name LIKE ? OR category LIKE ? LIMIT ?, ?";
$stmt = $conn->prepare($sql2);
$stmt->bind_param("ssii", $like, $like, $offset, $limit);
$stmt->execute();
$res2 = $stmt->get_result();
while($r = $res2->fetch_assoc()) $all_results[] = $r;

// 🔍 3. Suppliers
$sql3 = "SELECT 'supplier' AS src, id, name AS title, phone AS subtitle, address AS extra 
         FROM suppliers 
         WHERE name LIKE ? OR phone LIKE ? OR address LIKE ? LIMIT ?, ?";
$stmt = $conn->prepare($sql3);
$stmt->bind_param("sssii", $like, $like, $like, $offset, $limit);
$stmt->execute();
$res3 = $stmt->get_result();
while($r = $res3->fetch_assoc()) $all_results[] = $r;

// 🔍 4. Sales (optional: adapt fields based on schema)
$sql4 = "SELECT 'sales' AS src, id, invoice_no AS title, customer_name AS subtitle, total_amount AS extra 
         FROM sales 
         WHERE invoice_no LIKE ? OR customer_name LIKE ? LIMIT ?, ?";
$stmt = $conn->prepare($sql4);
$stmt->bind_param("ssii", $like, $like, $offset, $limit);
$stmt->execute();
$res4 = $stmt->get_result();
while($r = $res4->fetch_assoc()) $all_results[] = $r;

// Total count (for pagination) - simplified: sum of each table
$total_count = 0;
foreach (["pharmacy_stock"=>"medicine_name", "store_items"=>"item_name", "suppliers"=>"name", "sales"=>"invoice_no"] as $tbl=>$col) {
    $count_sql = "SELECT COUNT(*) AS c FROM $tbl WHERE $col LIKE ?";
    $st = $conn->prepare($count_sql);
    $st->bind_param("s", $like);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $total_count += $res['c'];
}
$total_pages = ceil($total_count / $limit);

?>

<?php if (count($all_results) > 0): ?>
    <ul class="list-group">
        <?php foreach ($all_results as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <strong><?php echo htmlspecialchars($r['title']); ?></strong><br>
                    <small class="text-muted"><?php echo htmlspecialchars($r['subtitle']); ?></small>
                </div>
                <span class="badge bg-secondary">
                    <?php echo ucfirst($r['src']); ?>: <?php echo htmlspecialchars($r['extra']); ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Pagination -->
    <nav class="mt-3">
        <ul class="pagination">
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <li class="page-item <?php if($i==$page) echo 'active'; ?>">
                    <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>

<?php else: ?>
    <div class="alert alert-warning text-center">No results found for "<?php echo htmlspecialchars($query); ?>"</div>
<?php endif; ?>
