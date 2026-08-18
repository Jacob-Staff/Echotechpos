<?php
session_start(); // Ensure session is started for pharmacy_id
require_once '../includes/auth.php'; 
require_once '../includes/conn.php';

// 1. PROTECTION: Ensure only Admin or Manager can access global stats
require_admin();

// Get user info and tenant ID from session
$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;

// Redirect if session is lost to prevent SQL errors
if ($pharmacy_id == 0) {
    header("Location: ../login_inc.php?error=session_expired");
    exit();
}

// --- LOGIC: Fetch Pharmacy Name ---
$pharmacy_name = "PHARMA-JACOBS";
$p_stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$p_stmt->bind_param("i", $pharmacy_id);
$p_stmt->execute();
$p_res = $p_stmt->get_result();
if($p_row = $p_res->fetch_assoc()){
    $pharmacy_name = $p_row['name'];
}

// --- LOGIC: Fetch Global Stats ---
$total_sales = 0;
$total_orders = 0;
$branch_count = 0;

$res1 = $conn->prepare("SELECT SUM(total_amount) as total FROM sales WHERE pharmacy_id = ?");
$res1->bind_param("i", $pharmacy_id);
$res1->execute();
$row1 = $res1->get_result()->fetch_assoc();
$total_sales = $row1['total'] ?? 0;

$res2 = $conn->prepare("SELECT COUNT(id) as total FROM sales WHERE pharmacy_id = ?");
$res2->bind_param("i", $pharmacy_id);
$res2->execute();
$row2 = $res2->get_result()->fetch_assoc();
$total_orders = $row2['total'] ?? 0;

$res3 = $conn->prepare("SELECT COUNT(id) as total FROM branches WHERE pharmacy_id = ? AND is_active = 1");
$res3->bind_param("i", $pharmacy_id);
$res3->execute();
$row3 = $res3->get_result()->fetch_assoc();
$branch_count = $row3['total'] ?? 0;

// 2. Bar Chart Data
$b_names = [];
$b_revenues = [];
$branch_data_query = $conn->prepare("SELECT b.branch_name, IFNULL(SUM(s.total_amount), 0) as rev 
                                     FROM branches b 
                                     LEFT JOIN sales s ON b.id = s.branch_id 
                                     WHERE b.pharmacy_id = ? AND b.is_active = 1 
                                     GROUP BY b.id");
$branch_data_query->bind_param("i", $pharmacy_id);
$branch_data_query->execute();
$branch_res = $branch_data_query->get_result();
while($row = $branch_res->fetch_assoc()){
    $b_names[] = $row['branch_name'];
    $b_revenues[] = (float)$row['rev'];
}

// 3. Payment Doughnut Data
$p_labels = [];
$p_counts = [];
$pay_query = $conn->prepare("SELECT payment_method, COUNT(*) as count FROM sales WHERE pharmacy_id = ? GROUP BY payment_method");
$pay_query->bind_param("i", $pharmacy_id);
$pay_query->execute();
$pay_res = $pay_query->get_result();

if($pay_res && $pay_res->num_rows > 0){
    while($row = $pay_res->fetch_assoc()){
        $p_labels[] = $row['payment_method'] ?: 'Other';
        $p_counts[] = (int)$row['count'];
    }
} else {
    $p_labels = ['No Data']; $p_counts = [1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Master Control | <?php echo htmlspecialchars($pharmacy_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { 
            --sidebar-width: 280px; 
            --accent: #00d2ff; 
            --bg-dark: #0d1117;
            --card-bg: #161b22;
            --border-color: #30363d;
            --text-main: #ffffff;
            --text-dim: #c9d1d9; /* Improved from faint gray */
        }
        
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Inter', sans-serif; overflow-x: hidden; }
        
        /* Sidebar Enhancements */
        .sidebar {
            width: var(--sidebar-width); height: 100vh; position: fixed;
            background: var(--card-bg); border-right: 1px solid var(--border-color); padding: 1.5rem;
            overflow-y: auto; z-index: 1000;
        }
        .sidebar-brand { font-size: 1.3rem; font-weight: 800; color: var(--accent); margin-bottom: 2rem; display: block; text-decoration: none; text-transform: uppercase; }
        .nav-link { color: var(--text-dim); padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; font-weight: 500; }
        .nav-link:hover, .nav-link.active { background: #21262d; color: #fff; border-left: 4px solid var(--accent); }
        .nav-link i { margin-right: 12px; width: 20px; font-size: 1.1rem; }

        .main-content { margin-left: var(--sidebar-width); padding: 2.5rem; }
        
        /* Card Enhancements */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color); border-radius: 16px; padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--accent); box-shadow: 0 8px 24px rgba(0,210,255,0.15); }
        .card-icon { width: 50px; height: 50px; background: rgba(0, 210, 255, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--accent); margin-bottom: 1rem; }
        
        /* Clarity Helpers */
        .text-dimmed { color: var(--text-dim) !important; font-weight: 400; }
        .fw-bold-white { color: #fff !important; font-weight: 700; }
        .form-control::placeholder { color: #484f58; }
        .form-control:focus { background-color: #0d1117; border-color: var(--accent); color: white; box-shadow: none; }
        hr.border-secondary { opacity: 0.2; border-color: var(--border-color) !important; }

        @media (max-width: 992px) {
            .sidebar { width: 80px; padding: 1rem 0.5rem; }
            .sidebar-brand span, .nav-link span, .sidebar small, .mb-4.px-3 { display: none; }
            .main-content { margin-left: 80px; }
            .nav-link i { margin-right: 0; width: 100%; text-align: center; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <a href="#" class="sidebar-brand"><i class="fas fa-capsules"></i> <span><?php echo htmlspecialchars($pharmacy_name); ?></span></a>
        
        <nav class="nav flex-column">
            <div class="mb-4 px-3">
                <p class="mb-0 fw-bold text-white"><?php echo htmlspecialchars($user_display_name); ?></p>
                <span class="badge bg-info text-dark" style="font-size: 0.75rem;"><?php echo $user_role; ?></span>
            </div>

            <small class="text-uppercase fw-bold mb-2" style="color: var(--accent); letter-spacing: 1px; font-size: 0.7rem;">Main Menu</small>
            <a class="nav-link active" href="admin_dashboard.php"><i class="fas fa-chart-line"></i> <span>Global Overview</span></a>
            
            <?php if ($user_role === 'Admin'): ?>
                <a class="nav-link" href="staff_management.php"><i class="fas fa-users"></i> <span>Staff Management</span></a>
                <a class="nav-link" href="manage_setup.php"><i class="fas fa-cog"></i> <span>System Setup</span></a>
            <?php endif; ?>
            
            <hr class="border-secondary my-4">
            
            <small class="text-uppercase fw-bold mb-2" style="color: var(--accent); letter-spacing: 1px; font-size: 0.7rem;">My Branches</small>
            <?php
            $stmt_b = $conn->prepare("SELECT * FROM branches WHERE pharmacy_id = ? AND is_active = 1");
            $stmt_b->bind_param("i", $pharmacy_id);
            $stmt_b->execute();
            $b_res = $stmt_b->get_result();
            if($b_res && $b_res->num_rows > 0) {
                while($b = $b_res->fetch_assoc()) {
                    echo "<a class='nav-link' href='view_branch.php?id=" . $b['id'] . "'>
                            <i class='fas fa-store-alt'></i> <span>" . htmlspecialchars($b['branch_name']) . "</span>
                          </a>";
                }
            } else {
                echo "<p class='small text-dimmed px-3 italic'>No active branches.</p>";
            }
            ?>

            <a href="../includes/logout.php" class="nav-link text-danger mt-4"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </nav>
    </div>

    <div class="main-content">
        <div class="stat-card mb-4">
            <div class="row align-items-center">
                <div class="col-md-2 text-center">
                    <?php 
                    $logo_query = $conn->prepare("SELECT logo FROM pharmacies WHERE id = ?");
                    $logo_query->bind_param("i", $pharmacy_id);
                    $logo_query->execute();
                    $logo_res = $logo_query->get_result()->fetch_assoc();
                    $current_logo = (!empty($logo_res['logo'])) ? $logo_res['logo'] : 'default_logo.png';
                    ?>
                    <img src="../uploads/logos/<?php echo $current_logo; ?>" alt="Logo" class="img-thumbnail bg-dark border-secondary" style="height: 80px; width: 80px; object-fit: contain;">
                </div>
                <div class="col-md-10">
                    <h5 class="fw-bold-white mb-2">Brand Identity</h5>
                    <p class="text-dimmed small">Your logo appears on all branch headers and customer receipts.</p>
                    <form action="upload_logo_action.php" method="POST" enctype="multipart/form-data" class="d-flex gap-2">
                        <input type="file" name="pharmacy_logo" class="form-control form-control-sm bg-dark text-white border-secondary" accept="image/*" required>
                        <button type="submit" class="btn btn-info btn-sm px-4 fw-bold">Update Logo</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h2 class="fw-bold-white mb-0"><?php echo htmlspecialchars($pharmacy_name); ?> Dashboard</h2>
                <p class="text-dimmed">High-level control for <span class="text-white fw-bold"><?php echo (int)$branch_count; ?></span> registered locations</p>
            </div>
            <div>
                <button class="btn btn-outline-light rounded-pill px-4" onclick="location.reload();">
                    <i class="fas fa-sync me-2"></i> Sync Brand Data
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="card-icon"><i class="fas fa-coins fa-lg"></i></div>
                    <span class="text-dimmed small fw-bold uppercase">Group Revenue</span>
                    <h2 class="fw-bold-white mt-1">K<?php echo number_format((float)$total_sales, 2); ?></h2>
                    <span class="text-success small fw-bold"><i class="fas fa-arrow-up"></i> Combined Branch Total</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="card-icon"><i class="fas fa-shopping-cart fa-lg"></i></div>
                    <span class="text-dimmed small fw-bold uppercase">Group Transactions</span>
                    <h2 class="fw-bold-white mt-1"><?php echo number_format((int)$total_orders); ?></h2>
                    <span class="text-info small fw-bold">Network Sales Volume</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="card-icon"><i class="fas fa-pills fa-lg"></i></div>
                    <span class="text-dimmed small fw-bold uppercase">Total Branches</span>
                    <h2 class="fw-bold-white mt-1"><?php echo (int)$branch_count; ?></h2>
                    <span class="text-dimmed small">Active registered outlets</span>
                </div>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col-md-8">
                <div class="stat-card" style="height: 450px;">
                    <h5 class="fw-bold-white mb-4">Branch Performance Comparison</h5>
                    <canvas id="branchChart"></canvas>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="height: 450px;">
                    <h5 class="fw-bold-white mb-4">Network Payment Usage</h5>
                    <canvas id="paymentChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const branchLabels = <?php echo json_encode($b_names); ?>;
        const branchSales = <?php echo json_encode($b_revenues); ?>;
        const payLabels = <?php echo json_encode($p_labels); ?>;
        const payCounts = <?php echo json_encode($p_counts); ?>;

        // Custom Font Color for Charts
        const chartTextColor = '#c9d1d9';

        const ctxBranch = document.getElementById('branchChart').getContext('2d');
        new Chart(ctxBranch, {
            type: 'bar',
            data: {
                labels: branchLabels.length ? branchLabels : ['No Data'],
                datasets: [{
                    label: 'Revenue (K)',
                    data: branchSales.length ? branchSales : [0],
                    backgroundColor: '#00d2ff',
                    borderRadius: 8
                }]
            },
            options: { 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        grid: { color: '#30363d' }, 
                        ticks: { color: chartTextColor, font: { weight: 'bold' } } 
                    },
                    x: { 
                        grid: { display: false }, 
                        ticks: { color: chartTextColor, font: { weight: 'bold' } } 
                    }
                } 
            }
        });

        const ctxPay = document.getElementById('paymentChart').getContext('2d');
        new Chart(ctxPay, {
            type: 'doughnut',
            data: {
                labels: payLabels,
                datasets: [{
                    data: payCounts,
                    backgroundColor: ['#238636', '#f1e05a', '#00d2ff', '#fb8c00', '#7952b3'],
                    borderWidth: 2,
                    borderColor: '#161b22'
                }]
            },
            options: { 
                maintainAspectRatio: false, 
                cutout: '75%',
                plugins: { 
                    legend: { 
                        position: 'bottom', 
                        labels: { 
                            color: chartTextColor, 
                            usePointStyle: true, 
                            padding: 25,
                            font: { size: 12, weight: 'bold' }
                        } 
                    } 
                } 
            }
        });
    </script>
</body>
</html>