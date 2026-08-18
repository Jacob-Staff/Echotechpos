<?php
session_start();
require_once '../includes/conn.php';

$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch Branch Details
$branch_res = $conn->query("SELECT * FROM branches WHERE id = $branch_id");
if(!$branch_res || $branch_res->num_rows == 0) {
    die("Branch not found.");
}
$branch = $branch_res->fetch_assoc();

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Statistics Calculation
$sales_res = $conn->query("SELECT SUM(total_amount) as total, COUNT(id) as count FROM sales WHERE branch_id = $branch_id");
$sales_data = $sales_res->fetch_assoc();
$branch_total = $sales_data['total'] ?? 0;
$branch_orders = $sales_data['count'] ?? 0;

$exp_res = $conn->query("SELECT SUM(amount) as total FROM expenses WHERE branch_id = $branch_id");
$branch_expenses = $exp_res->fetch_assoc()['total'] ?? 0;

$low_stock_res = $conn->query("SELECT COUNT(id) as count FROM store_items WHERE branch_id = $branch_id AND quantity < 10");
$low_stock_count = $low_stock_res->fetch_assoc()['count'] ?? 0;

// NEW: Stock Valuation (Modern Feature)
$valuation_res = $conn->query("SELECT SUM(quantity * price) as val FROM store_items WHERE branch_id = $branch_id");
$stock_valuation = $valuation_res->fetch_assoc()['val'] ?? 0;

// Pending Tasks count
$presc_res = $conn->query("SELECT COUNT(id) as count FROM prescriptions WHERE branch_id = $branch_id AND status != 'Ready'");
$pending_presc = $presc_res->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($branch['branch_name']); ?> | Admin Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { 
            --accent: #00d2ff; 
            --bg-dark: #05070a; 
            --card-bg: #11141d; 
            --border-color: #2d333b;
            --text-main: #ffffff;
            --text-dim: #e6edf3; 
        }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Inter', sans-serif; }
        
        .stat-card { 
            background: var(--card-bg); 
            border: 1px solid var(--border-color); 
            border-radius: 12px; 
            padding: 1.5rem; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            height: 100%;
        }
        .text-white-dim { color: var(--text-dim) !important; }
        .label-white { color: #ffffff; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        
        .nav-pills .nav-link { color: #ffffff; background: #1c2128; border: 1px solid var(--border-color); margin-right: 8px; }
        .nav-pills .nav-link.active { background: var(--accent); color: #000; font-weight: bold; }
        
        .table-custom { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; }
        .table thead th { border-bottom: 2px solid var(--accent); color: var(--accent); font-weight: bold; background: #1c2128; }
        .table td { color: #ffffff; vertical-align: middle; border-color: var(--border-color); }
        
        .form-control, .form-select { 
            background-color: #0d1117; 
            border: 1px solid var(--border-color); 
            color: white; 
        }
        .form-control:focus { background-color: #161b22; color: white; border-color: var(--accent); }
        
        .btn-accent { background: var(--accent); color: #000; font-weight: bold; border: none; }
        .btn-accent:hover { background: #00b4db; color: #000; }

        .action-box { border-left: 3px solid var(--accent); padding-left: 15px; }
    </style>
</head>
<body>
    <div class="container-fluid p-4">
        <div class="row align-items-center mb-4">
            <div class="col-md-7">
                <a href="admin_dashboard.php" class="btn btn-sm btn-outline-light mb-2">
                    <i class="fas fa-arrow-left"></i> Fleet Control
                </a>
                <h1 class="fw-bold mb-1"><?php echo htmlspecialchars($branch['branch_name']); ?> <span class="badge bg-primary fs-6 align-middle">Active</span></h1>
                <p class="text-white-dim"><i class="fas fa-id-badge me-2 text-accent"></i>Branch ID: <?php echo $branch['id']; ?> | <i class="fas fa-phone me-2 text-accent"></i><?php echo $branch['phone']; ?></p>
            </div>
            <div class="col-md-5 text-end">
                <div class="stat-card d-inline-block py-2 px-4">
                    <span class="label-white d-block">Current Stock Valuation</span>
                    <h4 class="fw-bold text-accent mb-0">K<?php echo number_format($stock_valuation, 2); ?></h4>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4 text-center">
            <div class="col-md-3">
                <div class="stat-card">
                    <span class="label-white">Total Revenue</span>
                    <h3 class="fw-bold text-info mt-2">K<?php echo number_format($branch_total, 2); ?></h3>
                    <div class="badge bg-success"><?php echo $branch_orders; ?> Sales recorded</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <span class="label-white">Branch Expenses</span>
                    <h3 class="fw-bold text-danger mt-2">K<?php echo number_format($branch_expenses, 2); ?></h3>
                    <span class="text-white-dim small">Operating Costs</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <span class="label-white">Net Profitability</span>
                    <h3 class="fw-bold text-success mt-2">K<?php echo number_format($branch_total - $branch_expenses, 2); ?></h3>
                    <span class="text-white-dim small">Sales - Expenses</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-warning">
                    <span class="label-white">Stock Alerts</span>
                    <h3 class="fw-bold text-warning mt-2"><?php echo $low_stock_count; ?></h3>
                    <span class="text-white-dim small">Items need restock</span>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-9">
                <ul class="nav nav-pills mb-3" id="mainTabs">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#stock"><i class="fas fa-capsules me-2"></i>Inventory</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#ops"><i class="fas fa-file-prescription me-2"></i>Operations (<?php echo $pending_presc; ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#ledger"><i class="fas fa-file-invoice-dollar me-2"></i>Daily Ledger</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#staff"><i class="fas fa-user-shield me-2"></i>Staff</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="stock">
                        <div class="table-custom p-3">
                            <div class="d-flex justify-content-between mb-3">
                                <h5 class="fw-bold">Stock Repository</h5>
                                <div class="input-group w-25">
                                    <input type="text" class="form-control form-control-sm" placeholder="Search item...">
                                </div>
                            </div>
                            <table class="table table-dark table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Product Details</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Qty</th>
                                        <th>Expiry</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $items = $conn->query("SELECT * FROM store_items WHERE branch_id = $branch_id ORDER BY quantity ASC LIMIT 15");
                                    while($i = $items->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-white"><?php echo htmlspecialchars($i['item_name']); ?></div>
                                            <small class="text-accent"><?php echo $i['strength'] ?? 'Standard'; ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo $i['category']; ?></span></td>
                                        <td>K<?php echo number_format($i['price'], 2); ?></td>
                                        <td class="fw-bold"><?php echo $i['quantity']; ?></td>
                                        <td class="text-white-dim"><?php echo date('M Y', strtotime($i['expiry_date'])); ?></td>
                                        <td class="text-center">
                                            <?php if($i['quantity'] < 10): ?>
                                                <span class="badge bg-danger">LOW STOCK</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">STABLE</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="ops">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="table-custom">
                                    <div class="p-3 bg-primary fw-bold text-white"><i class="fas fa-clock me-2"></i>Active Laybys</div>
                                    <table class="table table-dark small mb-0">
                                        <thead><tr><th>Customer</th><th>Balance</th><th>Due</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $laybys = $conn->query("SELECT * FROM laybys WHERE branch_id = $branch_id AND status='Pending' LIMIT 5");
                                            while($l = $laybys->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo $l['customer_name']; ?></td>
                                                <td class="text-danger fw-bold">K<?php echo $l['balance_due']; ?></td>
                                                <td class="text-white-dim"><?php echo $l['due_date']; ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-custom">
                                    <div class="p-3 bg-info fw-bold text-dark"><i class="fas fa-notes-medical me-2"></i>Pending Prescriptions</div>
                                    <table class="table table-dark small mb-0">
                                        <thead><tr><th>Client ID</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $presc = $conn->query("SELECT * FROM prescriptions WHERE branch_id = $branch_id AND status != 'Ready' LIMIT 5");
                                            while($pr = $presc->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-bold text-accent">#<?php echo $pr['client_id']; ?></td>
                                                <td><span class="badge bg-warning text-dark"><?php echo $pr['status']; ?></span></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="ledger">
                        <div class="table-custom p-3">
                            <h5 class="fw-bold mb-3">Combined Sales & Expense Logs</h5>
                            <table class="table table-dark small mb-0">
                                <thead><tr><th>Date</th><th>Description</th><th>Flow</th><th>Amount</th></tr></thead>
                                <tbody>
                                    <?php
                                    $ledger = $conn->query("(SELECT sale_date as dt, 'Customer Sale' as des, 'IN' as flow, total_amount as amt FROM sales WHERE branch_id = $branch_id) UNION (SELECT expense_date, name, 'OUT', amount FROM expenses WHERE branch_id = $branch_id) ORDER BY dt DESC LIMIT 20");
                                    while($l = $ledger->fetch_assoc()): ?>
                                    <tr>
                                        <td class="text-white-dim"><?php echo date('d M, H:i', strtotime($l['dt'])); ?></td>
                                        <td><?php echo $l['des']; ?></td>
                                        <td><span class="badge <?php echo $l['flow'] == 'IN' ? 'bg-success' : 'bg-danger'; ?>"><?php echo $l['flow']; ?></span></td>
                                        <td class="fw-bold">K<?php echo number_format($l['amt'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="staff">
                        <div class="row g-3">
                            <?php
                            $users = $conn->query("SELECT * FROM users WHERE branch_id = $branch_id");
                            while($u = $users->fetch_assoc()): ?>
                            <div class="col-md-4">
                                <div class="stat-card text-center">
                                    <img src="../assets/img/<?php echo $u['profile_pic'] ?? 'default_avatar.png'; ?>" class="rounded-circle mb-2 border border-accent p-1" width="70" height="70">
                                    <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($u['username']); ?></h6>
                                    <span class="badge bg-dark text-accent border border-accent mt-1"><?php echo strtoupper($u['role']); ?></span>
                                    <hr class="border-secondary">
                                    <div class="d-flex justify-content-between small">
                                        <span class="text-white-dim">Status:</span>
                                        <span class="text-success fw-bold"><?php echo $u['status']; ?></span>
                                    </div>
                                    <button class="btn btn-sm btn-outline-info w-100 mt-3">View Logs</button>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-md-3">
                <div class="stat-card mb-3">
                    <h6 class="fw-bold text-accent mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                    <button class="btn btn-accent w-100 mb-2 text-start" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="fas fa-plus-circle me-2"></i> New Item Entry
                    </button>
                    <a href="actions/export_report.php?id=<?php echo $branch_id; ?>" class="btn btn-outline-light w-100 mb-2 text-start">
                        <i class="fas fa-print me-2"></i> Export Monthly Report
                    </a>
                    <button class="btn btn-outline-danger w-100 text-start">
                        <i class="fas fa-exclamation-triangle me-2"></i> Log Branch Loss
                    </button>
                </div>

                <div class="stat-card">
                    <h6 class="fw-bold text-white mb-3"><i class="fas fa-history me-2"></i>Branch Health</h6>
                    <div class="action-box mb-3">
                        <small class="d-block text-white">System Connectivity</small>
                        <span class="text-success small">● Database Online</span>
                    </div>
                    <div class="action-box">
                        <small class="d-block text-white">Latest Activity</small>
                        <span class="text-white-dim small">New sale recorded recently</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-white border-secondary shadow-lg">
                <div class="modal-header border-secondary bg-black">
                    <h5 class="modal-title text-accent">Initialize New Inventory Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/add_product.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        <div class="row g-3">
                            <div class="col-md-12"><label class="label-white">Product Name</label><input type="text" name="name" class="form-control" placeholder="Item name" required></div>
                            <div class="col-md-6"><label class="label-white">Category</label>
                                <select name="category" class="form-select">
                                    <option value="Medicines">Medicines</option>
                                    <option value="Cosmetics">Cosmetics</option>
                                    <option value="General">General</option>
                                </select>
                            </div>
                            <div class="col-md-6"><label class="label-white">Current Quantity</label><input type="number" name="qty" class="form-control" required></div>
                            <div class="col-md-6"><label class="label-white">Cost Price (K)</label><input type="number" step="0.01" name="cost" class="form-control" required></div>
                            <div class="col-md-6"><label class="label-white">Selling Price (K)</label><input type="number" step="0.01" name="price" class="form-control" required></div>
                            <div class="col-md-12"><label class="label-white">Expiry Date</label><input type="date" name="expiry" class="form-control" required></div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="submit" class="btn btn-accent px-5">Commit Entry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>