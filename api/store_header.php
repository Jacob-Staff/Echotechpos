<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Database Connection Inclusion
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} else {
    die("Database connection file (conn.php) not found.");
}

// Request-aware base path so shared header links work from /api pages and root pages.
$request_dir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$store_base = (basename($request_dir) === 'api') ? '' : 'api/';

// 2. Resolve Branch ID
if (isset($_GET['bid']) && intval($_GET['bid']) > 0) {
    $new_branch = intval($_GET['bid']);
    $_SESSION['current_branch_id'] = $new_branch;
}

$branch_id = isset($_SESSION['current_branch_id']) ? intval($_SESSION['current_branch_id']) : 10;
$_SESSION['current_branch_id'] = $branch_id;

// 3. Multi-Tenant Context Search
$tenant_context = null;
$sql = "SELECT 
            p.id as pharmacy_id,
            p.name as tenant_name, 
            p.logo as tenant_logo,
            b.branch_name,
            b.location,
            b.phone as branch_phone
        FROM branches b
        INNER JOIN pharmacies p ON b.pharmacy_id = p.id
        WHERE b.id = ? AND b.is_active = 1";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenant_context = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

// Fallbacks
$pharmacy_name      = $tenant_context['tenant_name'] ?? 'Echo Prime';
$branch_name        = $tenant_context['branch_name'] ?? 'Main Branch';
$phone              = $tenant_context['branch_phone'] ?? '260974140989';
$parent_pharmacy_id = isset($tenant_context['pharmacy_id']) ? intval($tenant_context['pharmacy_id']) : 0;
$db_logo            = $tenant_context['tenant_logo'] ?? 'default_logo.png';

// ============================================================
// TrueMeds-inspired category navigation.
// These labels/subcategories are navigation only; products still
// come from this pharmacy's own store_items table.
// ============================================================
$store_nav = [
    'Medicines' => [
        'local_cat' => 'Medicines',
        'groups' => [
            'Popular Medicines' => ['All Medicines', 'Prescription Medicines', 'Over-the-Counter Medicines', 'Generic Medicines'],
            'Common Needs' => ['Pain Relief', 'Cold & Cough', 'Digestive Care', 'Allergy Care', 'Anti-Infectives', 'Vitamins & Nutrition']
        ]
    ],
    'Personal Care' => [
        'local_cat' => 'Cosmetics',
        'groups' => [
            'Personal Care' => ['Skin Care', 'Hair Care', 'Baby and Mom Care', 'Sexual Wellness', 'Oral Care', 'Elderly Care'],
            'Skin Care' => ['Skin Cream', 'Sunscreen', 'Face Wash', 'Skin and Body Soap', 'Acne Care', 'Body Lotions', 'Moisturising Lotion', 'Moisturising Cream', 'Mosquito Repellent', 'Moisturising Gel', 'Body Wash'],
            'Hair Care' => ['Hair Oils', 'Hair Shampoo', 'Hair Conditioners', 'Hair Supplements', 'Hair Colour', 'Hair Serum', 'Hair Mask', 'Hair Solutions'],
            'Baby & Mom' => ['Baby Diapers and Wipes', 'Baby Lotion and Moisturising Cream', 'Baby Bath Essentials', 'Baby Skin Care', 'Baby and Infant Food', 'Baby Healthcare', 'Women Multivitamins', 'Ovulation Test Kit and Women Intimate Care', 'Sanitary Pads', 'Nutritional Drinks'],
            'Sexual Wellness' => ['Condoms', 'Lubricants', 'Massage Gels', 'Personal Body Massagers', 'Men Performance Booster', 'Sexual Health Supplements', 'Massage Oils', 'Ayurveda'],
            'Oral & Elderly Care' => ['Tooth Paste', 'Mouth Ulcer Gel', 'Mouthwash', 'Tooth Brush', 'Gargle Solution', 'Orthopaedic Supports', 'Adult Diapers', 'Footwear', 'Mobility and Support Accessories', 'Urinary Support and Care']
        ]
    ],
    'Health Conditions' => [
        'local_cat' => 'Medicines',
        'groups' => [
            'Conditions' => ['Bone and Joint Care', 'Digestive Care', 'Eye Care', 'Pain Relief', 'Smoking Cessation', 'Liver Care', 'Stomach Care', 'Cold and Cough', 'Heart Care', 'Kidney Care', 'Piles, Fissures & Fistula', 'Respiratory Care', 'Mental Wellness', 'Derma Care'],
            'Digestive Care' => ['Pre and Probiotics', 'Acidity', 'Gas', 'Constipation', 'Loose Motion / Diarrhoea', 'Digestive Fibres', 'Digestive Enzymes'],
            'Eye Care' => ['Eye Lubricant Drops', 'Lens Solution', 'Safety Eye Wear', 'Eye Cream', 'Eye Vitamins and Supplements', 'Eye Drops', 'Eye Ointment and Gel'],
            'Cold & Cough' => ['Cough Syrups', 'Chest Rubs and Balms', 'Nasal Spray', 'Lozenges', 'Inhalant Capsules', 'Cold and Cough Tablets']
        ]
    ],
    'Vitamins & Supplements' => [
        'local_cat' => 'Wellness',
        'groups' => [
            'Supplements' => ['Multivitamins, Multiminerals and Antioxidants', 'Calcium & Minerals', 'Vitamin A to Z', 'Protein Supplements', 'Supplement Powder', 'Vitamin B12 and B Complex', 'Mineral Supplements', 'Immunity Boosters', 'Omega and Fish Oil']
        ]
    ],
    'Diabetes Care' => [
        'local_cat' => 'Wellness',
        'groups' => [
            'Diabetes Care' => ['Diabetic Diet', 'Sugar Substitutes', 'Diabetes Ayurvedic Medicines', 'Homeopathy', 'Syringes and Pens', 'Blood Glucose Monitors', 'Test Strips and Lancets']
        ]
    ],
    'Healthcare Devices' => [
        'local_cat' => 'Health Devices',
        'groups' => [
            'Devices' => ['Blood Glucose Monitors', 'Test Strips and Lancets', 'BP Monitors', 'Nebulizers and Vaporizers', 'Supports and Braces']
        ]
    ],
    'Homeopathic Medicine' => [
        'local_cat' => 'Herbal',
        'groups' => [
            'Homeopathy' => ['Homeopathy for Skin Care', 'Homeopathy Digestive Care', 'Homeopathy for Seniors', 'Homeopathy Heart Care', 'Homeopathy Kidney Care', 'Homeopathy Sexual Health', 'Homeopathy for Diabetes Care', 'Homeopathy for Hair Care', 'Homeopathy Cold & Cough']
        ]
    ],
    'Health Guide' => [
        'help' => true,
        'groups' => [
            'Health Resources' => ['Health Articles', 'Diseases & Health Conditions', 'Health Stories', 'Ayurveda', 'Understanding Generic Medicines', 'Health Library'],
            'Popular Topics' => ['Womenâ€™s Health', 'Menâ€™s Health', 'Diabetes Diet', 'Oral & Dental Health', 'Skin & Hair Care', 'Nutrition & Diet', 'General Wellness']
        ]
    ]
];

$esc = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

// Image paths
$logo_filename   = !empty($db_logo) ? $db_logo : 'default_logo.png';
$logo_local_path = __DIR__ . "/uploads/logos/" . $logo_filename;
$logo_web_path   = file_exists($logo_local_path) 
    ? "uploads/logos/" . $logo_filename 
    : "uploads/logos/default_logo.png";

// Notifications
$response_count = 0;
if (isset($_SESSION['client_id'])) {
    $c_id = intval($_SESSION['client_id']);
    if ($email_stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?")) {
        $email_stmt->bind_param("i", $c_id);
        $email_stmt->execute();
        $email_res = $email_stmt->get_result()->fetch_assoc();
        $c_email   = $email_res['email'] ?? '';
        $email_stmt->close();

        if (!empty($c_email)) {
            if ($stmt_res = $conn->prepare("SELECT COUNT(*) as total FROM help_inquiries WHERE client_email = ? AND status = 'Resolved' AND pharmacy_id = ? AND is_read_by_client = 0")) {
                $stmt_res->bind_param("si", $c_email, $parent_pharmacy_id);
                $stmt_res->execute();
                $response_count = $stmt_res->get_result()->fetch_assoc()['total'] ?? 0;
                $stmt_res->close();
            }
        }
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
            --echo-teal: #003339;
            --echo-green: #00b386;   
            --echo-blue: #1a4a7c;
            --echo-light: #f5faff;
        }
        html, body { 
            overflow-x: hidden !important; 
            width: 100% !important; 
            background-color: #f8f9fa; 
            font-family: 'IBM Plex Sans', sans-serif; 
            margin: 0; 
            padding: 0;
        }
        
        .tier-1-utility { background: #fff; border-bottom: 1px solid #eee; padding: 8px 0; width: 100%; }
        .location-selector { font-size: 12px; color: var(--echo-teal); font-weight: 600; cursor: pointer; }
        .apollo-nav-pill { color: #555; text-decoration: none; font-weight: 700; font-size: 13px; margin-right: 15px; transition: 0.3s; white-space: nowrap; }
        .apollo-nav-pill:hover, .apollo-nav-pill.active { color: var(--echo-teal); border-bottom: 3px solid var(--echo-green); padding-bottom: 2px; }
        
        .tier-2-strip { background: var(--echo-teal); padding: 8px 0; overflow-x: auto; white-space: nowrap; width: 100%; -webkit-overflow-scrolling: touch; }
        .strip-link { color: #fff !important; font-size: 11px; font-weight: 700; text-decoration: none; margin: 0 10px; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; }
        .strip-link:hover { color: var(--echo-green) !important; }
        
        .tier-3-action { background: var(--echo-blue); padding: 12px 0; color: white; width: 100%; }
        .hng-logo-area { display: flex; align-items: center; gap: 8px; }
        .hng-search-container { background: white; border-radius: 4px; display: flex; align-items: center; overflow: hidden; width: 100%; }
        .hng-search-container select { border: none; background: #f1f1f1; padding: 8px; font-size: 12px; font-weight: 600; outline: none; }
        .hng-search-container input { border: none; padding: 8px 12px; width: 100%; outline: none; color: #333; font-size: 13px; }
        .hng-search-btn { background: #eee; border: none; padding: 8px 15px; color: var(--echo-blue); }
        
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

        /* =========================================================
           TRUE MEDS-STYLE CATEGORY NAVIGATION
        ========================================================= */
        .tier-2-strip{background:#fff;border-top:1px solid #f1f3f5;border-bottom:1px solid #dfe4e8;padding:0;position:relative;z-index:1100;box-shadow:0 1px 3px rgba(0,0,0,.03)}
        .category-nav{display:flex;align-items:stretch;justify-content:space-between;gap:0;min-height:52px;overflow:visible}
        .category-item{position:static;display:flex;align-items:stretch}
        .category-trigger{display:flex;align-items:center;justify-content:center;gap:5px;position:relative;border:0;background:transparent;color:#607083;padding:0 11px;font-size:12px;font-weight:600;white-space:nowrap;cursor:pointer;transition:.2s}
        .category-trigger:after{content:'âŒ„';font-size:12px;color:#9aa4af;transition:.2s}
        .category-trigger:hover,.category-item.open .category-trigger{color:#1266d6;background:#f7fbff}
        .category-item.open .category-trigger:after{transform:rotate(180deg);color:#1266d6}
        .category-trigger:before{content:'';position:absolute;left:12px;right:12px;bottom:0;height:2px;background:#1266d6;transform:scaleX(0);transition:.2s}
        .category-item.open .category-trigger:before{transform:scaleX(1)}
        .mega-menu{display:none;position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #e0e5ea;border-top:0;box-shadow:0 14px 30px rgba(15,23,42,.13);z-index:1250;padding:18px 0 20px}
        .category-item.open .mega-menu{display:block}
        .mega-inner{max-width:1180px;margin:0 auto;padding:0 20px;display:grid;grid-template-columns:220px 1fr;gap:24px}
        .mega-left{border-right:1px solid #edf0f3;padding-right:18px}
        .mega-left-title{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#9aa3ad;font-weight:800;margin-bottom:7px}
        .mega-left-link{display:flex;align-items:center;justify-content:space-between;padding:9px 10px;border-radius:6px;color:#586574;font-size:13px;font-weight:600}
        .mega-left-link:hover{background:#f2f6fa;color:#1266d6}
        .mega-right{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px 28px;max-height:360px;overflow:auto;padding-right:8px}
        .mega-group-title{font-size:12px;color:#263442;font-weight:800;margin-bottom:8px}
        .mega-link{display:block;color:#657180;font-size:12px;padding:4px 0;line-height:1.35}
        .mega-link:hover{color:#1266d6}
        .mega-view-all{display:inline-flex;align-items:center;gap:5px;margin-top:8px;color:#1266d6;font-size:11px;font-weight:800}
        @media (max-width:1199px){.category-trigger{padding:0 7px;font-size:11px}.mega-right{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:991px){.category-nav{overflow-x:auto;justify-content:flex-start}.category-item{position:relative}.category-trigger{height:48px}.mega-menu{position:fixed;left:8px;right:8px;top:118px;max-height:70vh;overflow:auto;border:1px solid #e0e5ea;border-radius:10px}.mega-inner{grid-template-columns:1fr}.mega-left{border-right:0;border-bottom:1px solid #edf0f3;padding:0 0 10px}.mega-right{max-height:none;grid-template-columns:1fr 1fr}}
        @media (max-width:576px){.category-trigger{font-size:10px;padding:0 8px}.mega-right{grid-template-columns:1fr}.mega-menu{top:110px}}
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
                <div class="location-selector dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="mdi mdi-map-marker-outline fs-6"></i> 
                    <span class="text-primary"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>
                <ul class="dropdown-menu shadow border-0">
                    <li class="dropdown-header small text-uppercase fw-bold text-muted">Switch Branch</li>
                    <?php 
                    if ($stmt_br = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? AND is_active = 1")) {
                        $stmt_br->bind_param("i", $parent_pharmacy_id);
                        $stmt_br->execute();
                        $br_list = $stmt_br->get_result();

                        while ($bl = $br_list->fetch_assoc()): 
                            $switch_url = $store_base . 'switch_branch.php?bid=' . (int)$bl['id'];
                        ?>
                            <li>
                                <a class="dropdown-item <?php echo ($bl['id'] == $branch_id) ? 'active bg-success' : ''; ?>" href="<?php echo htmlspecialchars($switch_url); ?>">
                                    <?php echo htmlspecialchars($bl['branch_name']); ?>
                                </a>
                            </li>
                        <?php 
                        endwhile;
                        $stmt_br->close();
                    } 
                    ?>
                </ul>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <nav class="d-none d-lg-flex">
                <?php if(isset($_SESSION['client_id'])): 
                    $is_subscribed = false;
                    if ($check_sub = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND branch_id = ?")) {
                        $check_sub->bind_param("ii", $_SESSION['client_id'], $branch_id);
                        $check_sub->execute();
                        $is_subscribed = $check_sub->get_result()->num_rows > 0;
                        $check_sub->close();
                    }
                ?>
                    <a href="javascript:void(0);" 
                       id="subscribeBtn" 
                       class="apollo-nav-pill toggle-subscribe <?php echo $is_subscribed ? 'is-active' : ''; ?>" 
                       data-client="<?php echo $_SESSION['client_id']; ?>" 
                       data-branch="<?php echo $branch_id; ?>" 
                       data-pharmacy="<?php echo $parent_pharmacy_id; ?>"
                       data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>"
                       style="<?php echo $is_subscribed ? 'color: #00b386; font-weight: bold;' : ''; ?>">
                       <?php echo $is_subscribed ? 'âœ“ Subscribed' : 'Subscribe'; ?>
                    </a>
                <?php else: ?>
                    <a href="login_client.php" class="apollo-nav-pill">Login</a>
                <?php endif; ?>
                <a href="pharmacist.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Pharmacists</a>
                <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Prescriptions</a>
            </nav>

            <div class="d-flex align-items-center gap-2">
                <a href="view_cart.php?bid=<?php echo $branch_id; ?>" class="text-dark position-relative text-decoration-none">
                    <i class="mdi mdi-cart-outline fs-4"></i>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge cart-count" style="font-size: 9px;">
                        <?php 
                        $cart_count = 0;
                        if (isset($_SESSION['carts'][$branch_id]) && is_array($_SESSION['carts'][$branch_id])) {
                            foreach ($_SESSION['carts'][$branch_id] as $item) {
                                $cart_count += isset($item['qty']) ? intval($item['qty']) : 1;
                            }
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
                        <span class="d-none d-sm-inline"><?php echo isset($_SESSION['client_name']) ? htmlspecialchars(explode(' ', $_SESSION['client_name'])[0]) : 'Account'; ?></span>
                    </button>
                    
                    <div class="user-menu">
                        <?php if(isset($_SESSION['client_id'])): 
                            $uid = intval($_SESSION['client_id']);
                            $user_data = [];
                            if ($stmt_user = $conn->prepare("SELECT id, full_name, phone FROM clients WHERE id = ?")) {
                                $stmt_user->bind_param("i", $uid);
                                $stmt_user->execute();
                                $user_data = $stmt_user->get_result()->fetch_assoc() ?? [];
                                $stmt_user->close();
                            }
                        ?>
                            <h6><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></h6>
                            <p>User ID: #<?php echo str_pad($user_data['id'] ?? 0, 5, '0', STR_PAD_LEFT); ?></p>
                            <hr class="my-1">
                            <a href="profile.php" class="menu-link"><i class="mdi mdi-cog-outline"></i> Profile</a>
                            <a href="client_orders.php" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> Orders</a>
                        <?php else: ?>
                            <h6>Guest Menu</h6>
                            <hr class="my-1">
                            <a href="login_client.php" class="menu-link"><i class="mdi mdi-login"></i> Login / Register</a>
                        <?php endif; ?>

                        <div class="d-lg-none">
                            <hr class="my-1">
                            <a href="pharmacist.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-account-group-outline"></i> Find Pharmacists</a>
                            <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-prescription"></i> Upload Prescription</a>
                            
                            <?php if(isset($_SESSION['client_id'])): ?>
                                <a href="javascript:void(0);" 
                                   id="subscribeBtnMobile" 
                                   class="menu-link text-success fw-bold toggle-subscribe" 
                                   data-client="<?php echo $_SESSION['client_id']; ?>" 
                                   data-branch="<?php echo $branch_id; ?>" 
                                   data-pharmacy="<?php echo $parent_pharmacy_id; ?>"
                                   data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>">
                                   <i class="mdi mdi-bell-ring-outline"></i> <?php echo $is_subscribed ? 'âœ“ Subscribed' : 'Subscribe'; ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if(isset($_SESSION['client_id'])): ?>
                            <hr class="my-1">
                            <a href="logout_client.php" class="menu-link text-danger"><i class="mdi mdi-logout"></i> Logout</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TrueMeds-inspired Category Navigation -->
<div class="tier-2-strip" id="storeCategoryNav">
    <div class="container-fluid px-2 px-md-4">
        <nav class="category-nav" aria-label="Shop categories">
            <?php foreach ($store_nav as $nav_name => $nav):
                $top_href = !empty($nav['help'])
                    ? $store_base . 'help.php?bid=' . $branch_id
                    : $store_base . 'online_store.php?bid=' . $branch_id . '&cat=' . urlencode($nav['local_cat'] ?? $nav_name);
                $menu_id = 'menu_' . md5($nav_name);
            ?>
                <div class="category-item" data-menu="<?php echo $menu_id; ?>">
                    <button type="button" class="category-trigger" data-category-trigger><?php echo $esc($nav_name); ?></button>
                    <div class="mega-menu" id="<?php echo $menu_id; ?>">
                        <div class="mega-inner">
                            <div class="mega-left">
                                <div class="mega-left-title">Explore <?php echo $esc($nav_name); ?></div>
                                <?php foreach ($nav['groups'] as $group_name => $group_items): ?>
                                    <a href="#" class="mega-left-link" data-mega-group="<?php echo md5($nav_name . $group_name); ?>">
                                        <span><?php echo $esc($group_name); ?></span><i class="mdi mdi-chevron-right"></i>
                                    </a>
                                <?php endforeach; ?>
                                <a href="<?php echo $esc($top_href); ?>" class="mega-view-all">View all <?php echo $esc($nav_name); ?> <i class="mdi mdi-arrow-right"></i></a>
                            </div>
                            <div class="mega-right">
                                <?php foreach ($nav['groups'] as $group_name => $group_items): ?>
                                    <section id="group_<?php echo md5($nav_name . $group_name); ?>">
                                        <div class="mega-group-title"><?php echo $esc($group_name); ?></div>
                                        <?php foreach ($group_items as $sub_name):
                                            $sub_href = !empty($nav['help'])
                                                ? $store_base . 'help.php?bid=' . $branch_id . '&topic=' . urlencode($sub_name)
                                                : $store_base . 'online_store.php?bid=' . $branch_id . '&q=' . urlencode($sub_name);
                                        ?>
                                            <a href="<?php echo $esc($sub_href); ?>" class="mega-link"><?php echo $esc($sub_name); ?></a>
                                        <?php endforeach; ?>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
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
                         onerror="this.src='uploads/logos/default_logo.png';">
                    <div>
                        <h5 class="mb-0 fw-bold text-white" style="font-size: 1rem; line-height: 1.1;">
                            <?php echo htmlspecialchars($pharmacy_name); ?>
                        </h5>
                        <small class="opacity-75" style="font-size: 9px;">Online Pharmacy</small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-5 col-lg-5">
                <form action="online_store.php" method="GET" class="hng-search-container">
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
                    <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                        <i class="mdi mdi-home"></i>Home
                    </a>
                    <a href="categories.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a>
                    <a href="offers.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                        <i class="mdi mdi-label-percent"></i>Offer
                    </a>
                    <a href="help.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
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
