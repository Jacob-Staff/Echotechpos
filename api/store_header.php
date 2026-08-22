<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure BASE_URL is defined cleanly for root vs subfolder assets
if (!defined('BASE_URL')) {
    define('BASE_URL', '/pharmacy_v1-master/');
}

// 1. Establish connection
require_once(__DIR__ . "/../includes/conn.php");

// 2. Get Branch ID from URL, default to 10
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 10; 

// 3. The "Multi-Tenant" Query
$sql = "SELECT 
            p.id as pharmacy_id,
            p.name as tenant_name, 
            p.logo as tenant_logo,
            b.branch_name,
            b.location,
            b.phone as branch_phone
        FROM branches b
        INNER JOIN pharmacies p ON b.pharmacy_id = p.id
        WHERE b.id = $branch_id AND b.is_active = 1";

$result = $conn->query($sql);
$tenant_context = $result ? $result->fetch_assoc() : null;

// Fallback logic
if (!$tenant_context) {
    $pharmacy_name = "Echo Prime";
    $branch_name = "Main Branch";
    $phone = "260974140989";
    $parent_pharmacy_id = 0;
    $tenant_context = ['tenant_logo' => 'default_logo.png']; 
} else {
    $pharmacy_name = $tenant_context['tenant_name'];
    $branch_name = $tenant_context['branch_name'];
    $phone = $tenant_context['branch_phone'];
    $parent_pharmacy_id = $tenant_context['pharmacy_id'];
}

$cat_query = $conn->query("SELECT * FROM categories WHERE status = 1 LIMIT 8");
$db_logo = $tenant_context['tenant_logo']; 
$logo_filename = (!empty($db_logo)) ? $db_logo : 'default_logo.png';
$logo_web_path = BASE_URL . "uploads/logos/" . $logo_filename;

$response_count = 0;
if(isset($_SESSION['client_id'])) {
    $c_id = $_SESSION['client_id'];
    $email_stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
    $email_stmt->bind_param("i", $c_id);
    $email_stmt->execute();
    $c_email = $email_stmt->get_result()->fetch_assoc()['email'] ?? '';

    if(!empty($c_email)) {
        $stmt_res = $conn->prepare("SELECT COUNT(*) as total FROM help_inquiries WHERE client_email = ? AND status = 'Resolved' AND pharmacy_id = ? AND is_read_by_client = 0");
        $stmt_res->bind_param("si", $c_email, $parent_pharmacy_id);
        $stmt_res->execute();
        $response_count = $stmt_res->get_result()->fetch_assoc()['total'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($pharmacy_name); ?> | <?php echo htmlspecialchars($branch_name); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --echo-teal: #003339;    --echo-green: #00b386;   
            --echo-blue: #1a4a7c;    --echo-light: #f5faff;
        }
        html, body { 
            overflow-x: hidden !important; 
            width: 100% !important; 
            background-color: #f8f9fa; 
            font-family: 'IBM Plex Sans', sans-serif; 
            margin: 0; 
            padding: 0;
        }
        
        /* Tier 1 Adjustments */
        .tier-1-utility { background: #fff; border-bottom: 1px solid #eee; padding: 8px 0; width: 100%; }
        .location-selector { font-size: 12px; color: var(--echo-teal); font-weight: 600; cursor: pointer; }
        .apollo-nav-pill { color: #555; text-decoration: none; font-weight: 700; font-size: 13px; margin-right: 15px; transition: 0.3s; white-space: nowrap; }
        .apollo-nav-pill:hover, .apollo-nav-pill.active { color: var(--echo-teal); border-bottom: 3px solid var(--echo-green); padding-bottom: 2px; }
        
        /* Tier 2 Category Strip */
        .tier-2-strip { background: var(--echo-teal); padding: 8px 0; overflow-x: auto; white-space: nowrap; width: 100%; -webkit-overflow-scrolling: touch; }
        .strip-link { color: #fff !important; font-size: 11px; font-weight: 700; text-decoration: none; margin: 0 10px; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; }
        .strip-link:hover { color: var(--echo-green) !important; }
        
        /* Tier 3 Action Area */
        .tier-3-action { background: var(--echo-blue); padding: 12px 0; color: white; width: 100%; }
        .hng-logo-area { display: flex; align-items: center; gap: 8px; }
        .hng-search-container { background: white; border-radius: 4px; display: flex; align-items: center; overflow: hidden; width: 100%; }
        .hng-search-container select { border: none; background: #f1f1f1; padding: 8px; font-size: 12px; font-weight: 600; outline: none; }
        .hng-search-container input { border: none; padding: 8px 12px; width: 100%; outline: none; color: #333; font-size: 13px; }
        .hng-search-btn { background: #eee; border: none; padding: 8px 15px; color: var(--echo-blue); }
        
        /* Mobile Icon Strip */
        .nav-icon-grid { display: flex; justify-content: space-around; width: 100%; }
        .hng-nav-icon { color: white; text-decoration: none; text-align: center; font-size: 10px; font-weight: 600; flex: 1; }
        .hng-nav-icon i { background: white; color: var(--echo-blue); border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2px; font-size: 14px; }
        
        .user-dropdown { position: relative; display: inline-block; }
        .user-menu {
            display: none;
            position: absolute;
            right: 0;
            background-color: white;
            min-width: 220px;
            box-shadow: 0px 8px 16px rgba(0,0,0,0.15);
            border-radius: 8px;
            z-index: 1001;
            padding: 12px;
            border: 1px solid #eee;
        }
        .user-dropdown:hover .user-menu { display: block; }
        .user-menu h6 { margin: 0; color: var(--echo-teal); font-weight: 700; }
        .user-menu p { margin: 2px 0; font-size: 11px; color: #666; }
        .menu-link { display: block; padding: 6px 0; color: #333; text-decoration: none; font-size: 12px; font-weight: 600; }
        .menu-link:hover { color: var(--echo-green); }
        
        @media (max-width: 576px) {
            .brand-header-text { font-size: 1.1rem !important; }
            .tier-1-utility { padding: 5px 0; }
            .user-dropdown .btn { padding: 4px 10px !important; font-size: 11px !important; }
        }
    </style>
</head>
<body>

<!-- Tier 1 Top Bar -->
<div class="tier-1-utility">
    <div class="container-fluid px-2 px-md-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <h3 class="fw-bold mb-0 brand-header-text" style="color:var(--echo-teal)">
                <?php echo htmlspecialchars($pharmacy_name); ?>
            </h3>
            <div class="dropdown">
                <div class="location-selector dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="mdi mdi-map-marker-outline fs-6"></i> 
                    <span class="text-primary"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>
                <ul class="dropdown-menu shadow border-0">
                    <li class="dropdown-header small text-uppercase fw-bold text-muted">Switch Branch</li>
                    <?php 
                    // Calculate precise current URI to preserve path & query across directories
                    $parsed_url = parse_url($_SERVER['REQUEST_URI']);
                    $current_path = $parsed_url['path'];
                    $query_params = [];
                    if (isset($parsed_url['query'])) {
                        parse_str($parsed_url['query'], $query_params);
                    }

                    $br_list = $conn->query("SELECT id, branch_name FROM branches WHERE pharmacy_id = '$parent_pharmacy_id' AND is_active = 1");
                    if($br_list):
                        while($bl = $br_list->fetch_assoc()): 
                            $query_params['bid'] = $bl['id'];
                            $branch_url = $current_path . '?' . http_build_query($query_params);
                        ?>
                            <li>
                                <a class="dropdown-item <?php echo ($bl['id'] == $branch_id) ? 'active bg-success text-white' : ''; ?>" href="<?php echo htmlspecialchars($branch_url); ?>">
                                    <?php echo htmlspecialchars($bl['branch_name']); ?>
                                </a>
                            </li>
                        <?php endwhile;
                    endif; ?>
                </ul>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Desktop Header Pills -->
            <nav class="d-none d-lg-flex">
                <?php if(isset($_SESSION['client_id'])): 
                    $check_sub = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND branch_id = ?");
                    $check_sub->bind_param("ii", $_SESSION['client_id'], $branch_id);
                    $check_sub->execute();
                    $is_subscribed = $check_sub->get_result()->num_rows > 0;
                ?>
                    <a href="javascript:void(0);" 
                       id="subscribeBtn" 
                       class="apollo-nav-pill <?php echo $is_subscribed ? 'is-active' : ''; ?>" 
                       data-client="<?php echo $_SESSION['client_id']; ?>" 
                       data-branch="<?php echo $branch_id; ?>" 
                       data-pharmacy="<?php echo $parent_pharmacy_id; ?>"
                       data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>"
                       style="<?php echo $is_subscribed ? 'color: #00b386; font-weight: bold;' : ''; ?>">
                       <?php echo $is_subscribed ? '✓ Subscribed' : 'Subscribe'; ?>
                    </a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>login_client.php" class="apollo-nav-pill">Login</a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>api/pharmacist.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Pharmacists</a>
                <a href="<?php echo BASE_URL; ?>api/upload_prescription.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Prescriptions</a>
            </nav>

            <div class="d-flex align-items-center gap-2">
                <a href="<?php echo BASE_URL; ?>api/view_cart.php?bid=<?php echo $branch_id; ?>" class="text-dark position-relative text-decoration-none">
                    <i class="mdi mdi-cart-outline fs-4"></i>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge" style="font-size: 9px;">
                        <?php 
                        $cart_count = 0;
                        if(isset($_SESSION['cart'])) {
                            foreach($_SESSION['cart'] as $item) { $cart_count += $item['qty']; }
                        }
                        echo $cart_count; 
                        ?>
                    </span>
                </a>

                <?php if(isset($_SESSION['client_id'])): ?>
                <a href="javascript:void(0);" onclick="openNotifications()" class="text-dark position-relative text-decoration-none ms-1">
                    <i class="mdi mdi-bell-outline fs-4"></i>
                    <?php if($response_count > 0): ?>
                        <span id="notificationBadge" class="badge bg-success position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 9px;">
                            <?php echo $response_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <div class="user-dropdown ms-1">
                    <button class="btn btn-outline-dark btn-sm rounded-pill px-2 px-md-3 fw-bold">
                        <i class="mdi mdi-account-circle-outline"></i>
                        <span class="d-none d-sm-inline"><?php echo isset($_SESSION['client_name']) ? explode(' ', $_SESSION['client_name'])[0] : 'Account'; ?></span>
                    </button>
                    
                    <div class="user-menu">
                        <?php if(isset($_SESSION['client_id'])): 
                            $uid = $_SESSION['client_id'];
                            $user_data = $conn->query("SELECT id, full_name, phone FROM clients WHERE id = '$uid'")->fetch_assoc();
                        ?>
                            <h6><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></h6>
                            <p>User ID: #<?php echo str_pad($user_data['id'] ?? 0, 5, '0', STR_PAD_LEFT); ?></p>
                            <hr class="my-1">
                            <a href="<?php echo BASE_URL; ?>profile.php" class="menu-link"><i class="mdi mdi-cog-outline"></i> Profile</a>
                            <a href="<?php echo BASE_URL; ?>client_orders.php" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> Orders</a>
                        <?php else: ?>
                            <h6>Guest Menu</h6>
                            <hr class="my-1">
                            <a href="<?php echo BASE_URL; ?>login_client.php" class="menu-link"><i class="mdi mdi-login"></i> Login / Register</a>
                        <?php endif; ?>

                        <!-- Mobile Fallback Links -->
                        <div class="d-lg-none">
                            <hr class="my-1">
                            <a href="<?php echo BASE_URL; ?>api/pharmacist.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-account-group-outline"></i> Find Pharmacists</a>
                            <a href="<?php echo BASE_URL; ?>api/upload_prescription.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-prescription"></i> Upload Prescription</a>
                            
                            <?php if(isset($_SESSION['client_id'])): 
                                $check_sub = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND branch_id = ?");
                                $check_sub->bind_param("ii", $_SESSION['client_id'], $branch_id);
                                $check_sub->execute();
                                $is_subscribed = $check_sub->get_result()->num_rows > 0;
                            ?>
                                <a href="javascript:void(0);" 
                                   id="subscribeBtnMobile" 
                                   class="menu-link text-success fw-bold" 
                                   data-client="<?php echo $_SESSION['client_id']; ?>" 
                                   data-branch="<?php echo $branch_id; ?>" 
                                   data-pharmacy="<?php echo $parent_pharmacy_id; ?>"
                                   data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>">
                                   <i class="mdi mdi-bell-ring-outline"></i> <?php echo $is_subscribed ? '✓ Subscribed' : 'Subscribe'; ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if(isset($_SESSION['client_id'])): ?>
                            <hr class="my-1">
                            <a href="<?php echo BASE_URL; ?>logout_client.php" class="menu-link text-danger"><i class="mdi mdi-logout"></i> Logout</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tier 2 Category Bar -->
<div class="tier-2-strip">
    <div class="container-fluid text-center px-2">
        <a href="#" class="strip-link cat-filter active" data-category="all">All Products</a>
        <?php 
        $get_cats = $conn->query("SELECT name FROM categories WHERE status = 1 LIMIT 8");
        if($get_cats):
            while($c = $get_cats->fetch_assoc()): ?>
                <a href="#" class="strip-link cat-filter" data-category="<?php echo htmlspecialchars($c['name']); ?>">
                   <?php echo htmlspecialchars($c['name']); ?>
                </a>
            <?php endwhile;
        endif; ?>
    </div>
</div>

<!-- Tier 3 Search and Icon Bar -->
<div class="tier-3-action shadow-sm">
    <div class="container-fluid px-2 px-md-4"> 
        <div class="row align-items-center g-2">
            
            <div class="col-12 col-md-3 col-lg-3">
                <div class="hng-logo-area">
                    <img src="<?php echo $logo_web_path; ?>" 
                         alt="Logo" 
                         style="height: 38px; width: 38px; object-fit: contain;"
                         onerror="this.src='<?php echo BASE_URL; ?>assets/img/default_logo.png';">
                    <div>
                        <h5 class="mb-0 fw-bold text-white" style="font-size: 1rem; line-height: 1.1;">
                            <?php echo htmlspecialchars($pharmacy_name); ?>
                        </h5>
                        <small class="opacity-75" style="font-size: 9px;">Online Pharmacy</small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-5 col-lg-5">
                <form action="<?php echo BASE_URL; ?>api/search.php" method="GET" class="hng-search-container">
                    <input type="hidden" name="bid" value="<?php echo $branch_id; ?>">
                    <select name="type">
                        <option value="all">All</option>
                        <option value="rx">Rx</option>
                    </select>
                    <input type="text" name="q" placeholder="Search medicines in <?php echo htmlspecialchars($branch_name); ?>...">
                    <button type="submit" class="hng-search-btn"><i class="mdi mdi-magnify"></i></button>
                </form>
            </div>

            <div class="col-12 col-md-4 col-lg-4 mt-2 mt-md-0">
                <div class="nav-icon-grid">
                    <a href="<?php echo BASE_URL; ?>online_store.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                        <i class="mdi mdi-home"></i>Home
                    </a>
                    <a href="#" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a>
                    <a href="<?php echo BASE_URL; ?>api/offers.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                        <i class="mdi mdi-label-percent"></i>Offer
                    </a>
                    <a href="<?php echo BASE_URL; ?>help.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                        <i class="mdi mdi-help-circle"></i>Help
                    </a>
                    <a href="javascript:void(0);" onclick="openBankDetails()" class="hng-nav-icon">
                        <i class="mdi mdi-bank"></i>Bank
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle to guarantee dropdown functionality everywhere -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
