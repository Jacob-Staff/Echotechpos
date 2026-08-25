<?php
/**
 * STORE HEADER
 * Shared public-store header with tenant/branch-safe context.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

/*
 * ---------------------------------------------------------------
 * DATABASE CONNECTION
 * ---------------------------------------------------------------
 * The public-store header is shared by pages in different folders.
 * Always resolve conn.php from the header's real filesystem location
 * and verify that the expected $conn mysqli object actually exists.
 * This prevents the "Undefined variable $conn" failure seen when
 * the header is loaded directly from /api/.
 * ---------------------------------------------------------------
 */
$store_conn_candidates = [
    realpath(__DIR__ . '/../includes/conn.php'),
    realpath(__DIR__ . '/includes/conn.php'),
];

$store_conn_loaded = false;
foreach ($store_conn_candidates as $store_conn_file) {
    if ($store_conn_file && is_file($store_conn_file)) {
        require_once $store_conn_file;
        $store_conn_loaded = true;
        if (isset($conn) && $conn instanceof mysqli) {
            break;
        }
    }
}

if (!$store_conn_loaded || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection unavailable.');
}

$is_header_in_api = basename(__DIR__) === 'api';
$store_web_prefix = $is_header_in_api ? '../' : '';

/*
 * Absolute public URL of the Online Store.
 * The header may be included from ANY public-store page, so the
 * branch selector must never depend on the current page's relative
 * URL. The header file and online_store.php live in the same public
 * store directory in this application.
 */
$document_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$header_real_dir = realpath(__DIR__);
$store_web_dir = '';
if ($document_root && $header_real_dir && str_starts_with($header_real_dir, $document_root)) {
    $store_web_dir = str_replace('\\', '/', substr($header_real_dir, strlen($document_root)));
}
$store_web_dir = '/' . trim($store_web_dir, '/');
$online_store_url = ($store_web_dir === '/')
    ? '/online_store.php'
    : $store_web_dir . '/online_store.php';
$store_base_url = ($store_web_dir === '/') ? '/' : rtrim($store_web_dir, '/') . '/';
$store_switch_url = ($store_web_dir === '/') ? '/switch_branch.php' : $store_web_dir . '/switch_branch.php';


/*
 * ---------------------------------------------------------------
 * BRANCH CONTEXT
 * ---------------------------------------------------------------
 * The public store is branch-driven. A branch selected in this
 * header becomes the active public-store branch and the user is
 * immediately sent to that branch's Online Store page.
 *
 * IMPORTANT: a requested branch is only accepted if it is active.
 * When a branch is already selected, a new branch must belong to
 * the SAME pharmacy. This prevents cross-pharmacy branch switching.
 * ---------------------------------------------------------------
 */
$requested_branch = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
$existing_branch  = isset($_SESSION['current_branch_id']) ? (int)$_SESSION['current_branch_id'] : 0;
$existing_pharmacy = 0;

if ($existing_branch > 0) {
    $stmt = $conn->prepare(
        "SELECT pharmacy_id
         FROM branches
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $existing_branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $existing_pharmacy = (int)($row['pharmacy_id'] ?? 0);
    }
}

if ($requested_branch > 0) {
    if ($existing_pharmacy > 0) {
        // Switching is restricted to branches of the same pharmacy.
        $stmt = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ?
               AND pharmacy_id = ?
               AND is_active = 1
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $requested_branch, $existing_pharmacy);
            $stmt->execute();
            $valid = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($valid) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    } else {
        // First public-store branch selection: accept any active branch.
        $stmt = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ? AND is_active = 1
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $requested_branch);
            $stmt->execute();
            $valid = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($valid) {
                $_SESSION['current_branch_id'] = $requested_branch;
            }
        }
    }
}

$branch_id = isset($_SESSION['current_branch_id']) ? (int)$_SESSION['current_branch_id'] : 0;
$tenant_context = null;

$context_sql = "SELECT p.id AS pharmacy_id, p.name AS tenant_name, p.logo AS tenant_logo,
                       b.id AS branch_id, b.branch_name, b.location, b.phone AS branch_phone,
                       b.branch_email, b.bank_details, b.mobile_money_details
                FROM branches b
                INNER JOIN pharmacies p ON p.id = b.pharmacy_id
                WHERE b.id = ? AND b.is_active = 1
                LIMIT 1";
if ($stmt = $conn->prepare($context_sql)) {
    $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $tenant_context = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if (!$tenant_context) {
    // No unsafe default branch. This prevents tenant leakage.
    $pharmacy_name = 'Online Pharmacy';
    $branch_name = 'Select Branch';
    $phone = '';
    $parent_pharmacy_id = 0;
    $db_logo = 'default_logo.png';
    $bank_details = '';
    $mobile_money_details = '';
} else {
    $pharmacy_name = $tenant_context['tenant_name'] ?? 'Online Pharmacy';
    $branch_name = $tenant_context['branch_name'] ?? 'Branch';
    $phone = $tenant_context['branch_phone'] ?? '';
    $parent_pharmacy_id = (int)($tenant_context['pharmacy_id'] ?? 0);
    $db_logo = $tenant_context['tenant_logo'] ?? 'default_logo.png';
    $bank_details = $tenant_context['bank_details'] ?? '';
    $mobile_money_details = $tenant_context['mobile_money_details'] ?? '';
}

$logo_filename = !empty($db_logo) ? basename($db_logo) : 'default_logo.png';
$logo_local_path = ($is_header_in_api ? dirname(__DIR__) : __DIR__) . '/uploads/logos/' . $logo_filename;
$logo_web_path = file_exists($logo_local_path)
    ? $store_web_prefix . 'uploads/logos/' . rawurlencode($logo_filename)
    : $store_web_prefix . 'uploads/logos/default_logo.png';

// Safe filter context for shared header usage.
$category_filter = trim($_GET['cat'] ?? '');
$search_query = trim($_GET['q'] ?? '');
$search_type = (($_GET['type'] ?? 'all') === 'rx') ? 'rx' : 'all';

// Categories belong to the application catalogue. Products are still filtered
// by pharmacy + branch in online_store.php.
$cat_query = $conn->query("SELECT name FROM categories WHERE status = 1 ORDER BY name ASC LIMIT 12");

$response_count = 0;
$is_subscribed = false;
$client_name = 'Account';
$user_data = [];

if (isset($_SESSION['client_id']) && $parent_pharmacy_id > 0 && $branch_id > 0) {
    $client_id = (int)$_SESSION['client_id'];

    if ($stmt = $conn->prepare("SELECT id, full_name, phone FROM clients WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
    $client_name = $user_data['full_name'] ?? ($_SESSION['client_name'] ?? 'Account');

    if ($stmt = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1")) {
        $stmt->bind_param('iii', $client_id, $parent_pharmacy_id, $branch_id);
        $stmt->execute();
        $is_subscribed = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    if ($stmt = $conn->prepare("SELECT COUNT(*) total FROM help_inquiries h WHERE h.client_email = ? AND h.status = 'Resolved' AND h.pharmacy_id = ? AND h.is_read_by_client = 0")) {
        $email = $user_data['email'] ?? '';
        if ($email === '') {
            if ($es = $conn->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1")) {
                $es->bind_param('i', $client_id);
                $es->execute();
                $email_row = $es->get_result()->fetch_assoc() ?: [];
                $email = $email_row['email'] ?? '';
                $es->close();
            }
        }
        $stmt->bind_param('si', $email, $parent_pharmacy_id);
        $stmt->execute();
        $response_count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}

// Handle subscription toggle in this shared file so the buttons are functional
// without depending on an unknown endpoint.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['store_action'] ?? '') === 'toggle_subscription') {
    header('Content-Type: application/json; charset=utf-8');
    $client_id = (int)($_SESSION['client_id'] ?? 0);
    $post_branch = (int)($_POST['branch_id'] ?? 0);
    if (!$client_id || !$post_branch) {
        echo json_encode(['success'=>false,'message'=>'Please log in to subscribe.']);
        exit;
    }

    $ctx = $conn->prepare("SELECT pharmacy_id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$ctx) { echo json_encode(['success'=>false,'message'=>'Unable to verify branch.']); exit; }
    $ctx->bind_param('i', $post_branch); $ctx->execute();
    $ctx_row = $ctx->get_result()->fetch_assoc(); $ctx->close();
    if (!$ctx_row) { echo json_encode(['success'=>false,'message'=>'Invalid branch.']); exit; }
    $post_pharmacy = (int)$ctx_row['pharmacy_id'];

    $check = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1");
    $check->bind_param('iii', $client_id, $post_pharmacy, $post_branch); $check->execute();
    $existing = $check->get_result()->fetch_assoc(); $check->close();

    if ($existing) {
        $del = $conn->prepare("DELETE FROM customers WHERE id = ? LIMIT 1");
        $id = (int)$existing['id']; $del->bind_param('i', $id); $ok = $del->execute(); $del->close();
        echo json_encode(['success'=>$ok,'subscribed'=>false,'message'=>$ok?'Subscription removed.':'Unable to update subscription.']);
        exit;
    }

    $name = $user_data['full_name'] ?? ($_SESSION['client_name'] ?? 'Client');
    $phone_value = $user_data['phone'] ?? '';
    $email_value = '';
    if ($client_id && ($es = $conn->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1"))) {
        $es->bind_param('i', $client_id); $es->execute();
        $email_value = $es->get_result()->fetch_assoc()['email'] ?? ''; $es->close();
    }
    $ins = $conn->prepare("INSERT INTO customers (pharmacy_id, branch_id, client_id, name, phone, email) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param('iiisss', $post_pharmacy, $post_branch, $client_id, $name, $phone_value, $email_value);
    $ok = $ins->execute(); $ins->close();
    echo json_encode(['success'=>$ok,'subscribed'=>$ok,'message'=>$ok?'You are now subscribed to this branch.':'Unable to subscribe right now.']);
    exit;
}
?>
<style>
:root{--store-teal:#003339;--store-green:#00b386;--store-blue:#1a4a7c;--store-light:#f5faff}
html,body{overflow-x:hidden!important;width:100%!important;background:#f8f9fa;font-family:'IBM Plex Sans',Arial,sans-serif;margin:0;padding:0}
.tier-1-utility{background:#fff;border-bottom:1px solid #eee;padding:8px 0;width:100%;position:relative;z-index:1050}
.location-selector{font-size:12px;color:var(--store-teal);font-weight:600;cursor:pointer}
.branch-switch-form{margin:0;padding:0}
.branch-switch-label{display:inline-flex;align-items:center;gap:4px;color:var(--store-teal);font-size:12px;font-weight:700;margin:0;cursor:pointer}
.branch-switch-label>i{font-size:16px}
.branch-switch-select{border:0;background:transparent;color:#0d6efd;font-size:12px;font-weight:700;padding:2px 18px 2px 0;outline:0;cursor:pointer;max-width:190px}
.branch-switch-select:focus{box-shadow:none}
.apollo-nav-pill{color:#555;text-decoration:none;font-weight:700;font-size:13px;margin-right:15px;transition:.2s;white-space:nowrap}
.apollo-nav-pill:hover,.apollo-nav-pill.active{color:var(--store-teal);border-bottom:3px solid var(--store-green);padding-bottom:2px}
.tier-2-strip{background:var(--store-teal);padding:8px 0;overflow-x:auto;white-space:nowrap;width:100%;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.tier-2-strip::-webkit-scrollbar{display:none}
.strip-link{color:#fff!important;font-size:11px;font-weight:700;text-decoration:none;margin:0 10px;text-transform:uppercase;letter-spacing:.5px;display:inline-block;padding-bottom:2px}
.strip-link:hover,.strip-link.active{color:var(--store-green)!important;border-bottom:2px solid var(--store-green)}
.tier-3-action{background:var(--store-blue);padding:12px 0;color:#fff;width:100%;position:relative;z-index:1040}
.hng-logo-area{display:flex;align-items:center;gap:8px}
.hng-search-container{background:#fff;border-radius:7px;display:flex;align-items:center;overflow:hidden;width:100%;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.hng-search-container select{border:0;border-right:1px solid #eee;background:#f7f7f7;padding:8px;font-size:12px;font-weight:600;outline:0}
.hng-search-container input{border:0;padding:9px 12px;width:100%;outline:0;color:#333;font-size:13px;min-width:0}
.hng-search-btn{background:#eee;border:0;padding:9px 15px;color:var(--store-blue)}
.nav-icon-grid{display:flex;justify-content:space-around;width:100%;gap:4px}
.hng-nav-icon{color:#fff;text-decoration:none;text-align:center;font-size:10px;font-weight:600;flex:1;min-width:48px}
.hng-nav-icon i{background:#fff;color:var(--store-blue);border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin:0 auto 2px;font-size:15px}
.hng-nav-icon:hover{color:var(--store-green)}
.hng-nav-icon:hover i{color:var(--store-green)}
.user-dropdown{position:relative;display:inline-block}
.user-menu{display:none;position:absolute;right:0;top:calc(100% + 7px);background:#fff;min-width:230px;box-shadow:0 12px 30px rgba(0,0,0,.15);border-radius:10px;z-index:2000;padding:12px;border:1px solid #eee}
.user-dropdown.open .user-menu,.user-dropdown:hover .user-menu{display:block}
.user-menu h6{margin:0;color:var(--store-teal);font-weight:700}
.user-menu p{margin:2px 0;font-size:11px;color:#666}
.menu-link{display:block;padding:7px 0;color:#333;text-decoration:none;font-size:12px;font-weight:600}
.menu-link:hover{color:var(--store-green)}
.store-toast-container{position:fixed;top:78px;right:16px;z-index:4000;width:min(380px,calc(100vw - 32px))}
.store-toast{background:#fff;border:1px solid #dee2e6;border-left:4px solid var(--store-green);border-radius:12px;box-shadow:0 14px 35px rgba(0,0,0,.14);padding:12px;margin-bottom:10px}
.store-toast.error{border-left-color:#dc3545}.store-toast.info{border-left-color:#0d6efd}
@media(max-width:576px){.brand-header-text{font-size:1.1rem!important}.tier-1-utility{padding:5px 0}.user-dropdown .btn{padding:4px 10px!important;font-size:11px!important}.nav-icon-grid{overflow-x:auto;justify-content:flex-start}.hng-nav-icon{flex:0 0 58px}.store-toast-container{top:65px;right:10px;width:calc(100vw - 20px)}}
</style>

<div class="tier-1-utility">
<div class="container-fluid px-2 px-md-4 d-flex justify-content-between align-items-center gap-2">
<div class="d-flex align-items-center flex-wrap gap-2">
<h3 class="fw-bold mb-0 brand-header-text" style="color:var(--store-teal)"><?php echo htmlspecialchars($pharmacy_name); ?></h3>
<form class="branch-switch-form" method="get" action="<?php echo htmlspecialchars($store_switch_url, ENT_QUOTES, 'UTF-8'); ?>">
    <label class="branch-switch-label" aria-label="Switch pharmacy branch">
        <i class="mdi mdi-map-marker-outline"></i>
        <select name="bid" class="branch-switch-select" onchange="if(this.value){this.form.submit();}" aria-label="Switch branch">
            <?php if ($parent_pharmacy_id > 0 && ($stmt_br=$conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? AND is_active = 1 ORDER BY branch_name ASC"))): ?>
                <?php $stmt_br->bind_param('i',$parent_pharmacy_id); $stmt_br->execute(); $br_list=$stmt_br->get_result(); while($bl=$br_list->fetch_assoc()): ?>
                    <option value="<?php echo (int)$bl['id']; ?>" <?php echo ((int)$bl['id'] === $branch_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($bl['branch_name']); ?></option>
                <?php endwhile; $stmt_br->close(); ?>
            <?php else: ?>
                <option value="<?php echo (int)$branch_id; ?>" selected><?php echo htmlspecialchars($branch_name); ?></option>
            <?php endif; ?>
        </select>
    </label>
</form>
</div>

<div class="d-flex align-items-center gap-2">
<nav class="d-none d-lg-flex align-items-center">
<?php if(isset($_SESSION['client_id'])): ?>
<a href="javascript:void(0)" class="apollo-nav-pill toggle-subscribe <?php echo $is_subscribed?'active':''; ?>" data-branch="<?php echo $branch_id; ?>"><?php echo $is_subscribed?'âœ“ Subscribed':'Subscribe'; ?></a>
<?php else: ?><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>login_client.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Login</a><?php endif; ?>
<a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>pharmacist.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Pharmacists</a>
<a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>upload_prescription.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Prescriptions</a>
</nav>

<a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>view_cart.php?bid=<?php echo $branch_id; ?>" class="text-dark position-relative text-decoration-none" aria-label="Cart">
<i class="mdi mdi-cart-outline fs-4"></i><span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge cart-count" style="font-size:9px"><?php
$cart_count=0;if(isset($_SESSION['carts'][$branch_id])&&is_array($_SESSION['carts'][$branch_id]))foreach($_SESSION['carts'][$branch_id] as $item)$cart_count+=isset($item['qty'])?(int)$item['qty']:1;echo $cart_count;?></span>
</a>
<?php if(isset($_SESSION['client_id'])): ?><a href="javascript:void(0)" onclick="openNotifications()" class="text-dark position-relative text-decoration-none ms-1" aria-label="Notifications"><i class="mdi mdi-bell-outline fs-4"></i><?php if($response_count>0): ?><span id="notificationBadge" class="badge bg-success position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size:9px"><?php echo $response_count;?></span><?php endif;?></a><?php endif; ?>

<div class="user-dropdown ms-1" id="storeUserDropdown">
<button type="button" class="btn btn-outline-dark btn-sm rounded-pill px-2 px-md-3 fw-bold" id="userMenuButton"><i class="mdi mdi-account-circle-outline"></i> <span class="d-none d-sm-inline"><?php echo htmlspecialchars(explode(' ',trim($client_name))[0]??'Account'); ?></span></button>
<div class="user-menu">
<?php if(isset($_SESSION['client_id'])): ?>
<h6><?php echo htmlspecialchars($user_data['full_name']??$client_name); ?></h6><p>User ID: #<?php echo str_pad((int)($_SESSION['client_id']??0),5,'0',STR_PAD_LEFT); ?></p><hr class="my-1">
<a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>profile.php?bid=<?php echo $branch_id;?>" class="menu-link"><i class="mdi mdi-account-outline"></i> Profile</a>
<a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>client_orders.php?bid=<?php echo $branch_id;?>" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> Orders</a>
<?php else: ?><h6>Guest Menu</h6><hr class="my-1"><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>login_client.php?bid=<?php echo $branch_id;?>" class="menu-link"><i class="mdi mdi-login"></i> Login / Register</a><?php endif; ?>
<div class="d-lg-none"><hr class="my-1"><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>pharmacist.php?bid=<?php echo $branch_id;?>" class="menu-link"><i class="mdi mdi-account-group-outline"></i> Find Pharmacists</a><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>upload_prescription.php?bid=<?php echo $branch_id;?>" class="menu-link"><i class="mdi mdi-prescription"></i> Upload Prescription</a><?php if(isset($_SESSION['client_id'])):?><a href="javascript:void(0)" class="menu-link text-success fw-bold toggle-subscribe" data-branch="<?php echo $branch_id;?>"><i class="mdi mdi-bell-ring-outline"></i> <span class="subscribe-label"><?php echo $is_subscribed?'âœ“ Subscribed':'Subscribe';?></span></a><?php endif;?></div>
<?php if(isset($_SESSION['client_id'])):?><hr class="my-1"><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>logout_client.php" class="menu-link text-danger"><i class="mdi mdi-logout"></i> Logout</a><?php endif;?>
</div></div>
</div></div></div>

<div class="tier-2-strip"><div class="container-fluid text-center px-2">
<a href="<?php echo htmlspecialchars($online_store_url, ENT_QUOTES, 'UTF-8'); ?>?bid=<?php echo $branch_id;?>" class="strip-link <?php echo $category_filter===''?'active':'';?>">All Products</a>
<?php if($cat_query):while($c=$cat_query->fetch_assoc()):$cat_name=$c['name']??'';?><a href="<?php echo htmlspecialchars($online_store_url, ENT_QUOTES, 'UTF-8'); ?>?bid=<?php echo $branch_id;?>&cat=<?php echo urlencode($cat_name);?>" class="strip-link <?php echo strcasecmp($category_filter,$cat_name)===0?'active':'';?>"><?php echo htmlspecialchars($cat_name);?></a><?php endwhile;endif;?>
</div></div>

<div class="tier-3-action shadow-sm"><div class="container-fluid px-2 px-md-4"><div class="row align-items-center g-2">
<div class="col-12 col-md-3"><div class="hng-logo-area"><img src="<?php echo htmlspecialchars($logo_web_path);?>" alt="Logo" style="height:38px;width:38px;object-fit:contain" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($store_web_prefix.'uploads/logos/default_logo.png');?>';"><div><h5 class="mb-0 fw-bold text-white" style="font-size:1rem;line-height:1.1"><?php echo htmlspecialchars($pharmacy_name);?></h5><small class="opacity-75" style="font-size:9px">Online Pharmacy</small></div></div></div>
<div class="col-12 col-md-5"><form action="<?php echo htmlspecialchars($online_store_url, ENT_QUOTES, 'UTF-8'); ?>" method="GET" class="hng-search-container"><input type="hidden" name="bid" value="<?php echo $branch_id;?>"><select name="type"><option value="all" <?php echo (($_GET['type']??'all')!=='rx')?'selected':'';?>>All</option><option value="rx" <?php echo (($_GET['type']??'')==='rx')?'selected':'';?>>Rx</option></select><input type="search" name="q" value="<?php echo htmlspecialchars($_GET['q']??'');?>" placeholder="Search medicines in <?php echo htmlspecialchars($branch_name);?>..." autocomplete="off"><button type="submit" class="hng-search-btn" aria-label="Search"><i class="mdi mdi-magnify"></i></button></form></div>
<div class="col-12 col-md-4 mt-2 mt-md-0"><div class="nav-icon-grid"><a href="<?php echo htmlspecialchars($online_store_url, ENT_QUOTES, 'UTF-8'); ?>?bid=<?php echo $branch_id;?>" class="hng-nav-icon"><i class="mdi mdi-home"></i>Home</a><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>categories.php?bid=<?php echo $branch_id;?>" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>offers.php?bid=<?php echo $branch_id;?>" class="hng-nav-icon"><i class="mdi mdi-label-percent"></i>Offer</a><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>help.php?bid=<?php echo $branch_id;?>" class="hng-nav-icon"><i class="mdi mdi-help-circle"></i>Help</a><a href="javascript:void(0)" onclick="openBankDetails()" class="hng-nav-icon"><i class="mdi mdi-bank"></i>Bank</a></div></div>
</div></div></div>

<div class="modal fade" id="bankDetailsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow" style="border-radius:14px;overflow:hidden"><div class="modal-header bg-primary text-white border-0"><h5 class="modal-title fw-bold"><i class="mdi mdi-bank me-2"></i>Payment Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="small text-muted fw-bold">BANK TRANSFER</label><div class="bg-light rounded p-3" style="white-space:pre-wrap"><?php echo htmlspecialchars($bank_details!==''?$bank_details:'Bank details are not currently available.');?></div></div><div><label class="small text-muted fw-bold">MOBILE MONEY</label><div class="bg-light rounded p-3" style="white-space:pre-wrap"><?php echo htmlspecialchars($mobile_money_details!==''?$mobile_money_details:'Mobile money details are not currently available.');?></div></div></div><div class="modal-footer border-0"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow" style="border-radius:14px"><div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="mdi mdi-bell-outline me-2"></i>Notifications</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="text-center py-3"><div class="display-6 mb-2">ðŸ””</div><?php if($response_count>0):?><h6 class="fw-bold">You have <?php echo $response_count;?> new response<?php echo $response_count===1?'':'s';?></h6><p class="text-muted small">The pharmacy has responded to one or more of your inquiries.</p><a href="<?php echo htmlspecialchars($store_base_url, ENT_QUOTES, 'UTF-8'); ?>help.php?bid=<?php echo $branch_id;?>" class="btn btn-success btn-sm">View Responses</a><?php else:?><h6 class="fw-bold">You're all caught up</h6><p class="text-muted mb-0">There are no new notifications.</p><?php endif;?></div></div></div></div></div>
<div class="store-toast-container" id="storeToastContainer" aria-live="polite"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.showStoreToast=function(type,title,message){const box=document.getElementById('storeToastContainer');if(!box)return;const el=document.createElement('div');el.className='store-toast '+(type||'info');el.innerHTML='<strong>'+String(title||'').replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))+'</strong><div class="small text-muted mt-1">'+String(message||'').replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))+'</div>';box.appendChild(el);setTimeout(()=>el.remove(),4200)};
window.openBankDetails=function(){bootstrap.Modal.getOrCreateInstance(document.getElementById('bankDetailsModal')).show()};
window.openNotifications=function(){bootstrap.Modal.getOrCreateInstance(document.getElementById('notificationModal')).show()};
$(function(){
$('#userMenuButton').on('click',function(e){e.stopPropagation();$('#storeUserDropdown').toggleClass('open')});$(document).on('click',function(){$('#storeUserDropdown').removeClass('open')});$('.user-menu').on('click',function(e){e.stopPropagation()});
$(document).on('click','.toggle-subscribe',function(e){e.preventDefault();const btn=$(this),branch=Number(btn.data('branch'));if(!branch)return;const old=btn.text();btn.css('pointer-events','none').text('Updating...');$.ajax({url:<?php echo json_encode($online_store_url); ?>,method:'POST',dataType:'json',data:{store_action:'toggle_subscription',branch_id:branch},timeout:10000}).done(function(res){if(res.success){$('.toggle-subscribe').each(function(){$(this).toggleClass('active',!!res.subscribed);$(this).find('.subscribe-label').text(res.subscribed?'âœ“ Subscribed':'Subscribe');if($(this).find('.subscribe-label').length===0)$(this).text(res.subscribed?'âœ“ Subscribed':'Subscribe')});window.showStoreToast('success',res.subscribed?'Subscribed':'Unsubscribed',res.message||'Subscription updated.')}else{window.showStoreToast('error','Unable to update',res.message||'Please try again.')}}).fail(function(){window.showStoreToast('error','Connection error','Please try again.');}).always(function(){btn.css('pointer-events','auto')})});
});
</script>
