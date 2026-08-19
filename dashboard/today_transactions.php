<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$b_id = (int)($_SESSION['branch_id'] ?? 0);
$username = $_SESSION['username'] ?? 'Staff';
$user_role = $_SESSION['role'] ?? 'Pharmacist';

// Capture Date from GET request, default to Today
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$display_date = date('d M Y', strtotime($filter_date));

// 1. Fetch Branding
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Pharmanova LSK';

// 2. Query with prepared statements matching 'iisiisiis'
$sql = "SELECT s.*, u.username as issuer,
        (SELECT GROUP_CONCAT(st.item_name SEPARATOR ', ') 
         FROM sales_items si 
         JOIN store_items st ON si.product_id = st.id 
         WHERE si.sale_id = s.id) as items_sold,
        (SELECT SUM(total_amount) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_total,
        (SELECT COUNT(*) FROM sales WHERE pharmacy_id = ? AND branch_id = ? AND DATE(created_at) = ?) as day_count
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.pharmacy_id = ? 
        AND s.branch_id = ? 
        AND DATE(s.created_at) = ? 
        ORDER BY s.id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iisiisiis", $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date, $p_id, $b_id, $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_revenue = 0; $total_invoices = 0; $sales_data = [];

if($result){
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_revenue = $row['day_total'] ?? 0;
        $total_invoices = $row['day_count'] ?? 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Report | <?php echo htmlspecialchars($display_pharm); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #121824; color: #e2e8f0; margin: 0; padding: 0; overflow-x: hidden; }
        
        /* Layout Structure */
        .wrapper { display: flex; width: 100%; min-height: 100vh; }
        .sidebar { width: 250px; background: #1e293b; flex-shrink: 0; display: flex; flex-direction: column; justify-content: space-between; border-right: 1px solid #334155; }
        .main-content { flex-grow: 1; background: #121824; display: flex; flex-direction: column; }
        
        /* Header */
        .top-navbar { height: 60px; background: #273549; border-bottom: 1px solid #334155; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .brand-title { color: #ffffff; font-weight: 700; font-size: 1.2rem; letter-spacing: 0.5px; margin: 0; }
        .search-box { width: 280px; background: #1e293b; border: 1px solid #334155; color: #fff; border-radius: 6px; padding: 6px 12px; font-size: 0.85rem; }
        .search-box::placeholder { color: #94a3b8; }
        
        /* Sidebar Links */
        .sidebar-brand { padding: 18px 20px; font-weight: 800; font-size: 1.3rem; color: #fff; letter-spacing: 1px; }
        .user-card { background: #273549; margin: 0 15px 15px; padding: 12px; border-radius: 8px; display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 38px; height: 38px; background: #475569; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .nav-list { list-style: none; padding: 0 15px; margin: 0; }
        .nav-item { margin-bottom: 4px; }
        .nav-link-custom { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: #94a3b8; text-decoration: none; border-radius: 6px; font-size: 0.88rem; font-weight: 500; }
        .nav-link-custom:hover, .nav-link-custom.active { background: #334155; color: #fff; }
        .logout-btn { margin: 15px; background: #ff4757; color: white; text-align: center; padding: 10px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        
        /* Cards & Content */
        .page-body { padding: 25px; }
        .card-stat { border: none; border-radius: 8px; color: white; padding: 18px 20px; }
        .card-cyan { background: #00a8ff; }
        .card-orange { background: #fbc531; color: #1e293b; }
        
        /* Table */
        .custom-table-container { background: #1e293b; border-radius: 8px; border: 1px solid #334155; margin-top: 20px; overflow: hidden; }
        .table-dark-custom { width: 100%; margin: 0; color: #e2e8f0; }
        .table-dark-custom thead th { background: #0f172a; color: #f8fafc; padding: 14px 16px; font-size: 0.8rem; text-transform: uppercase; border-bottom: 1px solid #334155; }
        .table-dark-custom tbody td { padding: 14px 16px; border-bottom: 1px solid #334155; font-size: 0.9rem; vertical-align: middle; }

        @media print {
            .sidebar, .top-navbar, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .custom-table-container { border: none; }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- SIDEBAR -->
    <div class="sidebar no-print">
        <div>
            <div class="sidebar-brand"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></div>
            
            <div class="user-card">
                <div class="user-avatar"><i class="bi bi-person-fill text-light"></i></div>
                <div>
                    <div class="fw-bold text-light small">Staff: <?php echo htmlspecialchars($username); ?></div>
                    <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($user_role); ?></div>
                </div>
            </div>

            <ul class="nav-list">
                <li class="nav-item">
                    <a href="sell_now.php" class="nav-link-custom"><i class="bi bi-plus-circle"></i> Top up Pharmacy</a>
                </li>
                <li class="nav-item">
                    <a href="today_transactions.php" class="nav-link-custom active"><i class="bi bi-grid-fill"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a href="stock.php" class="nav-link-custom"><i class="bi bi-box-seam"></i> Pharmacy Stock</a>
                </li>
                <li class="nav-item">
                    <a href="purchases.php" class="nav-link-custom"><i class="bi bi-cart"></i> Purchases-orders</a>
                </li>
                <li class="nav-item">
                    <a href="suppliers.php" class="nav-link-custom"><i class="bi bi-people"></i> Suppliers</a>
                </li>
                <li class="nav-item">
                    <a href="add_product.php" class="nav-link-custom"><i class="bi bi-plus-square"></i> Add Product</a>
                </li>
            </ul>
        </div>

        <a href="../logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- TOP NAVBAR -->
        <div class="top-navbar no-print">
            <input type="text" class="search-box" placeholder="Search products...">
            <div class="d-flex align-items-center gap-3">
                <a href="sell_now.php" class="btn btn-primary btn-sm"><i class="bi bi-house-door-fill"></i></a>
                <span class="badge bg-secondary"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($display_bran); ?></span>
                <span class="small text-light"><?php echo date('d Aug Y'); ?></span>
                <button class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i></button>
                <button class="btn btn-sm btn-outline-light"><i class="bi bi-gear-fill"></i></button>
            </div>
        </div>

        <!-- PAGE CONTENT -->
        <div class="page-body">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-light mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                    <span class="text-muted small">Report for: <b><?php echo $display_date; ?></b></span>
                </div>
                <div class="no-print">
                    <form method="GET" class="d-inline-flex gap-2">
                        <input type="date" name="filter_date" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?php echo $filter_date; ?>" onchange="this.form.submit()">
                        <button type="button" class="btn btn-secondary btn-sm px-3" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="card-stat card-cyan">
                        <div class="small fw-bold text-uppercase opacity-75">Revenue (<?php echo $display_date; ?>)</div>
                        <div class="fs-2 fw-bold">K<?php echo number_format($total_revenue, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-stat card-orange">
                        <div class="small fw-bold text-uppercase opacity-75">Total Invoices</div>
                        <div class="fs-2 fw-bold"><?php echo $total_invoices; ?></div>
                    </div>
                </div>
            </div>

            <div class="custom-table-container">
                <table class="table-dark-custom">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Medicines Sold</th>
                            <th>Time</th>
                            <th>Handled By</th>
                            <th class="text-end">Total (ZMW)</th>
                            <th class="text-center no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($sales_data)): ?>
                            <?php foreach ($sales_data as $row): ?>
                                <tr>
                                    <td class="fw-bold text-info">#<?php echo htmlspecialchars($row['invoice']); ?></td>
                                    <td><?php echo htmlspecialchars($row['items_sold'] ?: 'No items'); ?></td>
                                    <td><?php echo date('h:i A', strtotime($row['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['issuer'] ?? 'System'); ?></td>
                                    <td class="text-end fw-bold">K<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td class="text-center no-print">
                                        <a href="view_invoice.php?id=<?php echo $row['id']; ?>" class="text-info">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No transactions recorded for this date.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
