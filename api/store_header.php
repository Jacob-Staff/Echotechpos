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

// Fetch categories safely
$cat_query = $conn->query("SELECT * FROM categories WHERE status = 1 LIMIT 8");

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
        :root{
            --echo-teal:#003339;
            --echo-green:#00a878;
            --echo-blue:#1a4a7c;
            --nav-border:#e7ebef;
            --nav-text:#263746;
            --nav-muted:#687786;
        }
        html,body{margin:0;padding:0;width:100%;overflow-x:hidden;background:#f8f9fa;font-family:Arial,Helvetica,sans-serif;color:#1f2933;}
        body{padding-top:0;}

        /* ========================= TOP HEADER ========================= */
        .store-topbar{background:#fff;border-bottom:1px solid #eceff2;min-height:58px;position:relative;z-index:1200;}
        .store-top-inner{min-height:58px;display:flex;align-items:center;justify-content:space-between;gap:20px;}
        .store-brand{display:flex;align-items:center;gap:10px;min-width:0;}
        .store-brand-name{font-size:29px;line-height:1;font-weight:800;letter-spacing:-.5px;color:var(--echo-teal);white-space:nowrap;}
        .branch-switch{position:relative;}
        .branch-switch-btn{border:1px solid #d9e0e6;background:#fff;color:#1670c5;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:700;min-width:110px;text-align:left;}
        .branch-switch-btn:hover{background:#f6fafc;}
        .branch-menu{min-width:220px;border:1px solid #e4e9ee;border-radius:10px;padding:7px;box-shadow:0 12px 35px rgba(0,0,0,.12);z-index:1500;}
        .branch-menu .dropdown-item{border-radius:7px;font-size:12px;font-weight:600;padding:8px 10px;}
        .branch-menu .dropdown-item.active{background:var(--echo-green)!important;color:#fff!important;}
        .store-top-links{display:flex;align-items:center;gap:18px;white-space:nowrap;}
        .store-top-link{color:#333;text-decoration:none;font-size:13px;font-weight:600;}
        .store-top-link:hover{color:var(--echo-green);}
        .top-cart{font-size:25px;color:#17202a;}
        .top-account{border:1px solid #111;background:#fff;border-radius:22px;padding:7px 15px;font-size:12px;font-weight:700;}
        .top-account:hover{background:#f5f7f8;}

        /* ========================= TRUEMEDS-STYLE CATEGORY NAV ========================= */
        .mega-nav-wrap{background:#fff;border-bottom:1px solid #dfe4e8;position:relative;z-index:1100;}
        .mega-nav{min-height:46px;display:flex;align-items:stretch;justify-content:center;gap:0;overflow:visible;}
        .mega-item{position:static;display:flex;align-items:center;}
        .mega-trigger{height:46px;display:flex;align-items:center;padding:0 17px;color:#5f6f7f;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap;border-bottom:2px solid transparent;transition:.18s ease;}
        .mega-trigger:hover,.mega-trigger.active{color:#1769d1;border-bottom-color:#1769d1;background:#fbfcfd;}
        .mega-trigger .mdi{font-size:14px;margin-left:4px;}

        .mega-panel{position:absolute;left:0;right:0;top:100%;display:none;background:#fff;border-top:1px solid #e7ebef;box-shadow:0 10px 24px rgba(18,38,63,.12);padding:18px 0 20px;}
        .mega-item:hover>.mega-panel,.mega-item:focus-within>.mega-panel{display:block;}
        .mega-panel-inner{max-width:1200px;margin:0 auto;padding:0 22px;display:grid;grid-template-columns:220px 1fr;gap:22px;}
        .mega-side{border-right:1px solid #e9edf1;padding-right:18px;}
        .mega-side-title{font-size:14px;font-weight:800;color:#253746;margin-bottom:10px;}
        .mega-side-link{display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:#586878;font-size:12px;padding:9px 10px;border-radius:7px;}
        .mega-side-link:hover{background:#f2f7fb;color:#1769d1;}
        .mega-side-link .mdi{font-size:16px;}
        .mega-content{min-width:0;}
        .mega-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
        .mega-heading strong{font-size:14px;color:#263746;}
        .mega-viewall{font-size:11px;font-weight:800;color:#1769d1;text-decoration:none;}
        .mega-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));column-gap:24px;row-gap:3px;max-height:310px;overflow:auto;padding-right:5px;}
        .mega-link{display:block;color:#657585;text-decoration:none;font-size:11.5px;padding:6px 5px;border-radius:5px;line-height:1.25;}
        .mega-link:hover{background:#f5f8fa;color:#1769d1;}
        .mega-link .mdi{font-size:12px;margin-right:3px;}

        /* Mobile menu */
        .mobile-category-toggle{display:none;border:0;background:#fff;width:100%;padding:11px 14px;font-size:13px;font-weight:700;color:#34495e;text-align:left;}
        .mobile-category-menu{display:none;background:#fff;border-top:1px solid #e8ecef;padding:5px 12px 14px;}
        .mobile-category-menu.open{display:block;}
        .mobile-nav-link{display:block;text-decoration:none;color:#445565;font-size:12px;font-weight:700;padding:10px 7px;border-bottom:1px solid #f0f2f4;}
        .mobile-subdetails{padding:4px 0 8px 10px;}
        .mobile-subdetails a{display:block;text-decoration:none;color:#687887;font-size:11px;padding:6px 7px;}

        /* ========================= BLUE ACTION BAR ========================= */
        .tier-3-action{background:var(--echo-blue);padding:11px 0;color:#fff;width:100%;}
        .hng-logo-area{display:flex;align-items:center;gap:8px;}
        .hng-search-container{background:#fff;border-radius:7px;display:flex;align-items:center;overflow:hidden;width:100%;border:1px solid #e1e6ea;}
        .hng-search-container select{border:0;background:#f5f6f7;padding:9px 10px;font-size:12px;font-weight:600;outline:none;}
        .hng-search-container input{border:0;padding:9px 12px;width:100%;outline:none;color:#333;font-size:13px;}
        .hng-search-btn{background:#f3f5f7;border:0;padding:9px 16px;color:var(--echo-blue);font-size:16px;}
        .nav-icon-grid{display:flex;justify-content:space-around;width:100%;}
        .hng-nav-icon{color:#fff;text-decoration:none;text-align:center;font-size:10px;font-weight:600;flex:1;}
        .hng-nav-icon i{background:#fff;color:var(--echo-blue);border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin:0 auto 3px;font-size:15px;}
        .hng-nav-icon:hover{color:#fff;opacity:.9;}

        /* ========================= USER MENU ========================= */
        .user-dropdown{position:relative;display:inline-block;}
        .user-menu{display:none;position:absolute;right:0;top:calc(100% + 7px);background:#fff;min-width:230px;box-shadow:0 10px 30px rgba(0,0,0,.14);border-radius:10px;z-index:1600;padding:12px;border:1px solid #e6eaee;}
        .user-dropdown:hover .user-menu,.user-dropdown:focus-within .user-menu{display:block;}
        .user-menu h6{margin:0;color:var(--echo-teal);font-weight:700;}
        .user-menu p{margin:2px 0;font-size:11px;color:#666;}
        .menu-link{display:block;padding:7px 0;color:#333;text-decoration:none;font-size:12px;font-weight:600;}
        .menu-link:hover{color:var(--echo-green);}

        @media (max-width:1100px){
            .mega-trigger{padding:0 11px;font-size:11px;}
            .store-top-links{gap:11px;}
            .store-brand-name{font-size:25px;}
        }
        @media (max-width:991.98px){
            .store-top-inner{padding-top:7px;padding-bottom:7px;}
            .store-top-links{display:none;}
            .mobile-category-toggle{display:block;}
            .mega-nav{display:none;}
            .mega-nav-wrap{border-bottom:1px solid #e1e6ea;}
            .mega-panel{display:none!important;}
            .tier-3-action{padding:10px 0 12px;}
        }
        @media (min-width:992px){
            .mobile-category-toggle,.mobile-category-menu{display:none!important;}
        }
        @media (max-width:576px){
            .store-brand-name{font-size:21px;}
            .branch-switch-btn{min-width:94px;font-size:11px;padding:4px 7px;}
            .tier-3-action .container-fluid{padding-left:10px!important;padding-right:10px!important;}
        }
    </style>
</head>
<body>

<?php
/*
 * The navigation labels/subcategories below reproduce the category structure
 * visible on the public Truemeds navigation, but the markup, styling and
 * routing are native to this pharmacy store. Product links are routed into
 * this store using search terms/sections rather than Truemeds URLs or assets.
 */
$nav = [
    'Medicines' => [
        'icon' => 'mdi-pill',
        'section' => 'medicines',
        'groups' => []
    ],
    'Personal Care' => [
        'icon' => 'mdi-heart-pulse',
        'section' => 'personal-care',
        'groups' => [
            'Skin Care' => ['Skin Cream','Sunscreen','Face Wash','Skin and Body Soap','Acne Care','Body Lotions','Moisturising Lotion','Moisturising Cream','Mosquito Repellent','Moisturising Gel','Body Wash'],
            'Hair Care' => ['Hair Oils','Hair Shampoo','Hair Conditioners','Hair Supplements','Hair Colour','Hair Serum','Hair Mask','Hair Solutions'],
            'Baby and Mom Care' => ['Baby Diapers and Wipes','Baby Lotion and Moisturising Cream','Baby Bath Essentials','Baby Skin Care','Baby and Infant Food','Baby Healthcare'],
            'Sexual Wellness' => ['Women Multivitamins','Ovulation Test Kit and Women Intimate Care','Sanitary Pads','Nutritional Drinks','Condoms','Lubricants','Massage Gels','Personal Body Massagers','Men Performance Booster','Sexual Health Supplements','Massage Oils','Ayurveda'],
            'Oral Care' => ['Tooth Paste','Mouth Ulcer Gel','Mouthwash','Toothache and Gum Pain','Tooth Brush','Gargle Solution'],
            'Elderly Care' => ['Orthopaedic Supports','Adult Diapers','Footwear','Mobility and Support Accessories','Urinary Support and Care']
        ]
    ],
    'Health Conditions' => [
        'icon' => 'mdi-heart-plus-outline',
        'section' => 'health-conditions',
        'groups' => [
            'Common Conditions' => ['Bone and Joint Care','Digestive Care','Eye Care','Pain Relief','Smoking Cessation','Liver Care','Stomach Care','Cold and Cough','Heart Care','Kidney Care','Piles, Fissures & Fistula','Respiratory Care','Mental Wellness','Derma Care','Pre and Probiotics'],
            'Digestive Care' => ['Acidity','Gas','Constipation','Loose Motion/Diarrhoea','Digestive Fibres','Digestive Enzymes'],
            'Eye Care' => ['Eye Lubricant Drops','Lens Solution','Safety Eye Wear','Eye Cream','Eye Vitamins and Supplements','Eye Drops','Eye Ointment and Gel'],
            'Cold, Cough & Smoking' => ['Nicotine Patch','Nicotine Gum','Nicotine Lozenges','Cough Syrups','Chest Rubs and Balms','Nasal Spray','Lozenges','Inhalant Capsules','Cold and Cough Tablets']
        ]
    ],
    'Vitamins & Supplements' => [
        'icon' => 'mdi-pill-multiple',
        'section' => 'vitamins-supplements',
        'groups' => [
            'Shop by Type' => ['Multivitamins, Multiminerals and Antioxidants','Calcium & Minerals','Vitamin A to Z','Protein Supplements','Supplement Powder','Vitamin B12 and B Complex','Mineral Supplements','Immunity Boosters','Omega and Fish Oil']
        ]
    ],
    'Diabetes Care' => [
        'icon' => 'mdi-water-outline',
        'section' => 'diabetes-care',
        'groups' => [
            'Diabetes Essentials' => ['Diabetic Diet','Sugar Substitutes','Diabetes Ayurvedic Medicines','Homeopathy','Syringes and Pens','Blood Glucose Monitors','Test Strips and Lancets']
        ]
    ],
    'Healthcare Devices' => [
        'icon' => 'mdi-medical-bag',
        'section' => 'healthcare-devices',
        'groups' => [
            'Devices & Supports' => ['Blood Glucose Monitors','Test Strips and Lancets','BP Monitors','Nebulizers and Vaporizers','Supports and Braces']
        ]
    ],
    'Homeopathic Medicine' => [
        'icon' => 'mdi-leaf',
        'section' => 'homeopathic-medicine',
        'groups' => [
            'Homeopathy' => ['Homeopathy for Skin Care','Homeopathy Digestive Care','Homeopathy for Seniors','Homeopathy Heart Care','Homeopathy Kidney Care','Homeopathy Sexual Health','Homeopathy for Diabetes Care','Homeopathy for Hair Care','Homeopathy Cold & Cough']
        ]
    ],
    'Health Guide' => [
        'icon' => 'mdi-book-open-page-variant-outline',
        'section' => 'health-guide',
        'groups' => [
            'Health Information' => ['Health Articles','Diseases & Health Conditions','Health Stories','Ayurveda','Understanding Generic Medicines','Health Library']
        ]
    ]
];

$store_base = 'online_store.php';
$make_search_url = static function(string $term, int $bid): string {
    return 'online_store.php?bid=' . $bid . '&q=' . urlencode($term);
};
$make_section_url = static function(string $section, int $bid): string {
    return 'online_store.php?bid=' . $bid . '&section=' . urlencode($section);
};
?>

<!-- ========================= TOP HEADER ========================= -->
<header class="store-topbar">
    <div class="container-fluid px-3 px-md-4">
        <div class="store-top-inner">
            <div class="store-brand">
                <a href="<?php echo $store_base; ?>?bid=<?php echo $branch_id; ?>" class="text-decoration-none">
                    <span class="store-brand-name"><?php echo htmlspecialchars($pharmacy_name); ?></span>
                </a>

                <div class="dropdown branch-switch">
                    <button class="branch-switch-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="mdi mdi-map-marker-outline"></i>
                        <?php echo htmlspecialchars($branch_name); ?>
                    </button>
                    <ul class="dropdown-menu branch-menu">
                        <li><div class="dropdown-header small text-uppercase fw-bold text-muted">Switch Branch</div></li>
                        <?php
                        if ($parent_pharmacy_id > 0 && ($stmt_br = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? AND is_active = 1 ORDER BY branch_name ASC"))) {
                            $stmt_br->bind_param("i", $parent_pharmacy_id);
                            $stmt_br->execute();
                            $br_list = $stmt_br->get_result();
                            while ($bl = $br_list->fetch_assoc()):
                                $is_current = ((int)$bl['id'] === $branch_id);
                        ?>
                            <li>
                                <a class="dropdown-item <?php echo $is_current ? 'active' : ''; ?>"
                                   href="switch_branch.php?bid=<?php echo (int)$bl['id']; ?>">
                                    <?php echo htmlspecialchars($bl['branch_name']); ?>
                                    <?php if ($is_current): ?><i class="mdi mdi-check float-end"></i><?php endif; ?>
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

            <div class="store-top-links">
                <?php
                $is_subscribed = false;
                if (isset($_SESSION['client_id'])) {
                    $cid = (int)$_SESSION['client_id'];
                    if ($check_sub = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND branch_id = ? LIMIT 1")) {
                        $check_sub->bind_param("ii", $cid, $branch_id);
                        $check_sub->execute();
                        $is_subscribed = $check_sub->get_result()->num_rows > 0;
                        $check_sub->close();
                    }
                }
                ?>

                <?php if (isset($_SESSION['client_id'])): ?>
                    <a href="javascript:void(0);" id="subscribeBtn" class="store-top-link toggle-subscribe"
                       data-client="<?php echo (int)$_SESSION['client_id']; ?>"
                       data-branch="<?php echo $branch_id; ?>"
                       data-pharmacy="<?php echo $parent_pharmacy_id; ?>"
                       data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>">
                        <?php echo $is_subscribed ? 'âœ“ Subscribed' : 'Subscribe'; ?>
                    </a>
                <?php else: ?>
                    <a href="login_client.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Login</a>
                <?php endif; ?>

                <a href="pharmacist.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Pharmacists</a>
                <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Prescriptions</a>

                <a href="cart.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none position-relative">
                    <i class="mdi mdi-cart-outline top-cart"></i>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge cart-count" style="font-size:9px;">
                        <?php
                        $cart_count = 0;

                        /* Logged-in customers use the persistent online cart. */
                        if (isset($_SESSION['client_id'])) {
                            $cart_client_id = (int)$_SESSION['client_id'];

                            /* The table is created by cart.php/online_store.php.
                               If it does not exist yet, safely fall back to session. */
                            $table_exists = false;
                            if ($table_result = $conn->query("SHOW TABLES LIKE 'online_cart_items'")) {
                                $table_exists = $table_result->num_rows > 0;
                                $table_result->free();
                            }

                            if ($table_exists) {
                                if ($clean_cart = $conn->prepare(
                                    "DELETE FROM online_cart_items
                                     WHERE client_id = ?
                                       AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
                                )) {
                                    $clean_cart->bind_param('i', $cart_client_id);
                                    $clean_cart->execute();
                                    $clean_cart->close();
                                }

                                if ($cart_stmt = $conn->prepare(
                                    "SELECT COALESCE(SUM(quantity),0) AS total
                                     FROM online_cart_items
                                     WHERE client_id = ?
                                       AND branch_id = ?"
                                )) {
                                    $cart_stmt->bind_param('ii', $cart_client_id, $branch_id);
                                    $cart_stmt->execute();
                                    $cart_row = $cart_stmt->get_result()->fetch_assoc();
                                    $cart_count = (int)($cart_row['total'] ?? 0);
                                    $cart_stmt->close();
                                }
                            }
                        }

                        /* Guest/session fallback. */
                        if ($cart_count === 0 && isset($_SESSION['carts'][$branch_id]) && is_array($_SESSION['carts'][$branch_id])) {
                            foreach ($_SESSION['carts'][$branch_id] as $cart_item) {
                                $cart_count += isset($cart_item['qty']) ? max(1, (int)$cart_item['qty']) : 1;
                            }
                        }

                        echo $cart_count;
                        ?>
                    </span>
                </a>

                <?php if (isset($_SESSION['client_id'])): ?>
                    <a href="javascript:void(0);" onclick="openNotifications()" class="text-decoration-none position-relative" title="Notifications">
                        <i class="mdi mdi-bell-outline" style="font-size:24px;color:#17202a;"></i>
                        <?php if ($response_count > 0): ?>
                            <span id="notificationBadge" class="badge bg-success position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size:9px;">
                                <?php echo (int)$response_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <div class="user-dropdown">
                    <button class="top-account" type="button">
                        <i class="mdi mdi-account-circle-outline"></i>
                        <?php echo isset($_SESSION['client_name']) ? htmlspecialchars(explode(' ', trim($_SESSION['client_name']))[0]) : 'Staff'; ?>
                    </button>
                    <div class="user-menu">
                        <?php if (isset($_SESSION['client_id'])):
                            $uid = (int)$_SESSION['client_id'];
                            $user_data = [];
                            if ($stmt_user = $conn->prepare("SELECT id, full_name, phone FROM clients WHERE id = ?")) {
                                $stmt_user->bind_param("i", $uid);
                                $stmt_user->execute();
                                $user_data = $stmt_user->get_result()->fetch_assoc() ?? [];
                                $stmt_user->close();
                            }
                        ?>
                            <h6><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></h6>
                            <p>User ID: #<?php echo str_pad((string)($user_data['id'] ?? 0), 5, '0', STR_PAD_LEFT); ?></p>
                            <hr class="my-1">
                            <a href="profile.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-account-outline"></i> Profile</a>
                            <a href="client_orders.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> Orders</a>
                            <a href="logout_client.php" class="menu-link text-danger"><i class="mdi mdi-logout"></i> Logout</a>
                        <?php else: ?>
                            <h6>Guest Menu</h6>
                            <hr class="my-1">
                            <a href="login_client.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-login"></i> Login / Register</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ========================= CATEGORY NAVIGATION ========================= -->
<nav class="mega-nav-wrap" aria-label="Store categories">
    <button class="mobile-category-toggle" type="button" id="mobileCategoryToggle" aria-expanded="false">
        <i class="mdi mdi-menu me-1"></i> Browse Categories
        <i class="mdi mdi-chevron-down float-end" id="mobileCategoryChevron"></i>
    </button>

    <div class="mega-nav container-fluid px-2">
        <?php foreach ($nav as $label => $menu): ?>
            <div class="mega-item">
                <a class="mega-trigger" href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">
                    <?php echo htmlspecialchars($label); ?>
                    <?php if (!empty($menu['groups'])): ?><i class="mdi mdi-chevron-down"></i><?php endif; ?>
                </a>

                <?php if (!empty($menu['groups'])): ?>
                    <div class="mega-panel">
                        <div class="mega-panel-inner">
                            <div class="mega-side">
                                <div class="mega-side-title"><?php echo htmlspecialchars($label); ?></div>
                                <?php foreach ($menu['groups'] as $group => $links): ?>
                                    <a class="mega-side-link" href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>">
                                        <span><?php echo htmlspecialchars($group); ?></span>
                                        <i class="mdi mdi-chevron-right"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <div class="mega-content">
                                <div class="mega-heading">
                                    <strong>Shop <?php echo htmlspecialchars($label); ?></strong>
                                    <a class="mega-viewall" href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">VIEW ALL</a>
                                </div>
                                <div class="mega-grid">
                                    <?php foreach ($menu['groups'] as $group => $links): ?>
                                        <?php foreach ($links as $sub): ?>
                                            <a class="mega-link" href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>">
                                                <i class="mdi mdi-chevron-right"></i><?php echo htmlspecialchars($sub); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Mobile category list -->
    <div class="mobile-category-menu" id="mobileCategoryMenu">
        <?php foreach ($nav as $label => $menu): ?>
            <a class="mobile-nav-link" href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">
                <?php echo htmlspecialchars($label); ?>
                <?php if (!empty($menu['groups'])): ?><i class="mdi mdi-arrow-right float-end"></i><?php endif; ?>
            </a>
            <?php if (!empty($menu['groups'])): ?>
                <div class="mobile-subdetails">
                    <?php foreach ($menu['groups'] as $group => $links): ?>
                        <a href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>" class="fw-bold text-dark">
                            <?php echo htmlspecialchars($group); ?>
                        </a>
                        <?php foreach ($links as $sub): ?>
                            <a href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>">
                                <?php echo htmlspecialchars($sub); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>

<!-- ========================= SEARCH / STORE ACTION BAR ========================= -->
<div class="tier-3-action shadow-sm">
    <div class="container-fluid px-2 px-md-4">
        <div class="row align-items-center g-2">
            <div class="col-12 col-md-3 col-lg-3">
                <div class="hng-logo-area">
                    <img src="<?php echo htmlspecialchars($logo_web_path); ?>"
                         alt="<?php echo htmlspecialchars($pharmacy_name); ?> logo"
                         style="height:38px;width:38px;object-fit:contain;"
                         onerror="this.src='uploads/logos/default_logo.png';">
                    <div>
                        <h5 class="mb-0 fw-bold text-white" style="font-size:1rem;line-height:1.1;">
                            <?php echo htmlspecialchars($pharmacy_name); ?>
                        </h5>
                        <small class="opacity-75" style="font-size:9px;">Online Pharmacy</small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-5 col-lg-5">
                <form action="online_store.php" method="GET" class="hng-search-container">
                    <input type="hidden" name="bid" value="<?php echo $branch_id; ?>">
                    <select name="type" aria-label="Search type">
                        <option value="all">All</option>
                        <option value="rx">Rx</option>
                    </select>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" placeholder="Search medicines in <?php echo htmlspecialchars($branch_name); ?>...">
                    <button type="submit" class="hng-search-btn" aria-label="Search"><i class="mdi mdi-magnify"></i></button>
                </form>
            </div>

            <div class="col-12 col-md-4 col-lg-4 mt-2 mt-md-0">
                <div class="nav-icon-grid">
                    <a href="online_store.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-home"></i>Home</a>
                    <a href="categories.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a>
                    <a href="offers.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-label-percent"></i>Offer</a>
                    <a href="help.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon"><i class="mdi mdi-help-circle"></i>Help</a>
                    <a href="javascript:void(0);" onclick="openBankDetails()" class="hng-nav-icon"><i class="mdi mdi-bank"></i>Bank</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const toggle = document.getElementById('mobileCategoryToggle');
    const menu = document.getElementById('mobileCategoryMenu');
    const chevron = document.getElementById('mobileCategoryChevron');
    if(toggle && menu){
        toggle.addEventListener('click', function(){
            const open = menu.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if(chevron){
                chevron.classList.toggle('mdi-chevron-up', open);
                chevron.classList.toggle('mdi-chevron-down', !open);
            }
        });
    }
})();
</script>
