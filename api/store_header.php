<?php
session_start(); 

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
$tenant_context = $result->fetch_assoc();

// Fallback logic
if (!$tenant_context) {
    $pharmacy_name = "Echo Prime";
    $branch_name = "Main Branch";
    $phone = "260974140989";
    $parent_pharmacy_id = 0;
    // ADD THIS LINE so the logo logic doesn't break
    $tenant_context = ['tenant_logo' => 'default_logo.png']; 
} else {
    $pharmacy_name = $tenant_context['tenant_name'];
    $branch_name = $tenant_context['branch_name'];
    $phone = $tenant_context['branch_phone'];
    $parent_pharmacy_id = $tenant_context['pharmacy_id'];
}

$cat_query = $conn->query("SELECT * FROM categories WHERE status = 1 LIMIT 8");

// Define the base path for your project
$project_root = "/pharmacy_v1-master"; 

// Get the logo from your database context
$db_logo = $tenant_context['tenant_logo']; 
$logo_filename = (!empty($db_logo)) ? $db_logo : 'default_logo.png';

// Build the final path
$logo_web_path = $project_root . "/uploads/logos/" . $logo_filename;

// Check for admin responses for the logged-in client
$response_count = 0;
if(isset($_SESSION['client_id'])) {
    $c_id = $_SESSION['client_id'];
    
    // 1. Get the email for this client ID first
    $email_stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
    $email_stmt->bind_param("i", $c_id);
    $email_stmt->execute();
    $c_email = $email_stmt->get_result()->fetch_assoc()['email'] ?? '';

    if(!empty($c_email)) {
        // 2. Count inquiries that are Resolved AND haven't been read yet (is_read_by_client = 0)
        // We filter by pharmacy_id to ensure they only see alerts for the current pharmacy context
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pharmacy_name; ?> | <?php echo $branch_name; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --echo-teal: #003339;    --echo-green: #00b386;   
            --echo-blue: #1a4a7c;    --echo-light: #f5faff;
        }
        body { background-color: #f8f9fa; font-family: 'IBM Plex Sans', sans-serif; margin: 0; }
        .tier-1-utility { background: #fff; border-bottom: 1px solid #eee; padding: 8px 0; }
        .location-selector { font-size: 13px; color: var(--echo-teal); font-weight: 600; cursor: pointer; }
        .apollo-nav-pill { color: #555; text-decoration: none; font-weight: 700; font-size: 14px; margin-right: 20px; transition: 0.3s; }
        .apollo-nav-pill:hover, .apollo-nav-pill.active { color: var(--echo-teal); border-bottom: 3px solid var(--echo-green); padding-bottom: 5px; }
        .tier-2-strip { background: var(--echo-teal); padding: 10px 0; box-shadow: inset 0 -2px 5px rgba(0,0,0,0.1); overflow-x: auto; white-space: nowrap; }
        .strip-link { color: #fff !important; font-size: 12px; font-weight: 700; text-decoration: none; margin: 0 20px; text-transform: uppercase; letter-spacing: 0.5px; }
        .strip-link:hover { color: var(--echo-green) !important; }
        .tier-3-action { background: var(--echo-blue); padding: 15px 0; color: white; }
        .hng-logo-area { display: flex; align-items: center; gap: 10px; }
        .hng-search-container { background: white; border-radius: 4px; display: flex; align-items: center; overflow: hidden; width: 100%; }
        .hng-search-container select { border: none; background: #f1f1f1; padding: 10px; font-size: 13px; font-weight: 600; outline: none; }
        .hng-search-container input { border: none; padding: 10px 15px; width: 100%; outline: none; color: #333; }
        .hng-search-btn { background: #eee; border: none; padding: 10px 20px; color: var(--echo-blue); }
        .hng-nav-icon { color: white; text-decoration: none; text-align: center; font-size: 12px; font-weight: 600; }
        .hng-nav-icon i { background: white; color: var(--echo-blue); border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 4px; font-size: 16px; }
        
        .product-card { background: white; border-radius: 8px; border: 1px solid #eee; padding: 10px; transition: 0.3s; height: 100%; display: flex; flex-direction: column; justify-content: space-between; }
        .product-card:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .product-price { color: var(--echo-green); font-weight: 800; font-size: 1rem; margin-top: 4px; }
        .product-title { font-size: 12px; font-weight: 700; line-height: 1.3; height: 34px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; color: #333; }
        .product-title span.cat-label { font-weight: 400; color: #777; font-size: 11px; }
        .add-to-cart-btn { background: var(--echo-teal); color: white; border: none; width: 100%; padding: 6px; border-radius: 4px; font-weight: 700; font-size: 11px; margin-top: 8px; }
        
        .wa-sticky { position: fixed; bottom: 30px; right: 30px; background: #25d366; color: white; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); z-index: 1000; text-decoration: none; }
        .cat-filter.active { border-bottom: 2px solid var(--echo-green); color: var(--echo-green) !important; padding-bottom: 2px; }

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
            padding: 15px;
            border: 1px solid #eee;
        }
        .user-dropdown:hover .user-menu { display: block; }
        .user-menu h6 { margin: 0; color: var(--echo-teal); font-weight: 700; }
        .user-menu p { margin: 2px 0; font-size: 12px; color: #666; }
        .user-menu hr { margin: 10px 0; opacity: 0.1; }
        .menu-link { display: block; padding: 8px 0; color: #333; text-decoration: none; font-size: 13px; font-weight: 600; }
        .menu-link:hover { color: var(--echo-green); }
        .logout-link { color: #dc3545 !important; }

        /* 🔥 EDGE FULL-WIDTH FIX (NON-DESTRUCTIVE) */
.tier-1-utility > .container,
.tier-2-strip > .container,
.tier-3-action > .container {
    max-width: 100% !important;
    width: 100% !important;
}

/* Optional: keep nice spacing inside */
/* 🔥 BETTER EDGE FIX */
.tier-1-utility .container,
.tier-2-strip .container {
    max-width: 1400px !important; /* Limits width on ultra-wide screens */
    width: 95% !important;
}

.tier-3-action {
    width: 100%;
    /* Keep the blue background edge-to-edge, but content centered */
}
    </style>
</head>
<body>

<div class="tier-1-utility">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h2 class="fw-bold mb-0 me-4" style="color:var(--echo-teal)">
                <?php echo htmlspecialchars($pharmacy_name); ?>
            </h2>
            <div class="dropdown">
                <div class="location-selector dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="mdi mdi-map-marker-outline fs-5"></i> 
                    Location: <span class="text-primary"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>
                <ul class="dropdown-menu shadow border-0">
                    <li class="dropdown-header small text-uppercase fw-bold text-muted">Switch Branch</li>
                    <?php 
                    $br_list = $conn->query("SELECT id, branch_name FROM branches WHERE pharmacy_id = '$parent_pharmacy_id' AND is_active = 1");
                    while($bl = $br_list->fetch_assoc()): ?>
                        <li>
                            <a class="dropdown-item <?php echo ($bl['id'] == $branch_id) ? 'active bg-success' : ''; ?>" href="?bid=<?php echo $bl['id']; ?>">
                                <?php echo $bl['branch_name']; ?>
                            </a>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
        <div class="d-flex align-items-center gap-4">
            <nav class="d-none d-lg-flex">
<?php if(isset($_SESSION['client_id'])): 
    // Check if user is already subscribed to THIS specific branch
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
       <?php echo $is_subscribed ? '✓ Subscribed' : 'Subscribe to ' . htmlspecialchars($branch_name); ?>
    </a>
<?php else: ?>
    <a href="login_client.php" class="apollo-nav-pill">Login to Subscribe</a>
<?php endif; ?>
                <a href="api/pharmacist.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Find Pharmacists</a>
                <a href="api/upload_prescription.php?bid=<?php echo $branch_id; ?>" class="apollo-nav-pill">Prescriptions</a>
            </nav>
            <div class="d-flex align-items-center gap-3 border-start ps-3">
<a href="<?php echo BASE_URL; ?>api/view_cart.php?bid=<?php echo $branch_id; ?>" class="text-dark position-relative text-decoration-none d-inline-block">
    <i class="mdi mdi-cart-outline fs-4"></i>
    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge" style="font-size: 10px;">
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
<a href="javascript:void(0);" onclick="openNotifications()" class="text-dark position-relative text-decoration-none d-inline-block ms-3 me-2">
    <i class="mdi mdi-bell-outline fs-4"></i>
    
    <?php if($response_count > 0): ?>
        <span id="notificationBadge" class="badge bg-success position-absolute top-0 start-100 translate-middle rounded-pill" style="font-size: 10px;">
            <?php echo $response_count; ?>
        </span>
    <?php endif; ?>
</a>
<?php endif; ?>

                </a>
                <div class="user-dropdown">
                    <button class="btn btn-outline-dark btn-sm rounded-pill px-4 fw-bold">
                        <i class="mdi mdi-account-circle-outline me-1"></i>
                        <?php echo isset($_SESSION['client_name']) ? explode(' ', $_SESSION['client_name'])[0] : 'Account'; ?>
                    </button>
                    
                    <?php if(isset($_SESSION['client_id'])): 
                        $uid = $_SESSION['client_id'];
                        $user_data = $conn->query("SELECT id, full_name, phone FROM clients WHERE id = '$uid'")->fetch_assoc();
                    ?>
                    <div class="user-menu">
                        <h6><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></h6>
                        <p>User ID: #<?php echo str_pad($user_data['id'] ?? 0, 5, '0', STR_PAD_LEFT); ?></p>
                        <p><i class="mdi mdi-phone"></i> <?php echo htmlspecialchars($user_data['phone'] ?? ''); ?></p>
                        <hr>
                        <a href="<?php echo BASE_URL; ?>profile.php" class="menu-link"><i class="mdi mdi-cog-outline"></i> Manage Profile</a>
                        <a href="<?php echo BASE_URL; ?>client_orders.php" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> My Orders</a>
                        <a href="<?php echo BASE_URL; ?>logout_client.php" class="menu-link logout-link"><i class="mdi mdi-logout"></i> Logout</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="tier-2-strip">
    <div class="container d-flex justify-content-center">
        <a href="#" class="strip-link cat-filter active" data-category="all">All Products</a>
        <?php 
        $get_cats = $conn->query("SELECT name FROM categories WHERE status = 1 LIMIT 8");
        while($c = $get_cats->fetch_assoc()): ?>
            <a href="#" class="strip-link cat-filter" data-category="<?php echo $c['name']; ?>">
               <?php echo $c['name']; ?>
            </a>
        <?php endwhile; ?>
    </div>
</div>

<div class="tier-3-action shadow-sm" style="width: 100%; background: var(--echo-blue); padding: 20px 0; color: white;">
    <div class="container-fluid px-lg-5"> 
        <div class="row align-items-center">
            
            <div class="col-md-3 col-lg-2">
                <div class="hng-logo-area d-flex align-items-center gap-2">
                    <?php 
                        // Pull logo from tenant context, fallback to default if empty
                        $display_logo = !empty($tenant_context['tenant_logo']) ? $tenant_context['tenant_logo'] : 'default_logo.png';
                    ?>
                    <img src="<?php echo $logo_web_path; ?>" 
                        alt="Logo" 
                        style="height: 45px; width: 45px; object-fit: contain;">
                    <div>
                        <h4 class="mb-0 fw-bold" style="font-size: 1.1rem; line-height: 1;">
                            <?php echo htmlspecialchars($pharmacy_name); ?>
                        </h4>
                        <small class="opacity-75" style="font-size: 10px;">Online Pharmacy</small>
                    </div>
                </div>
            </div>

            <div class="col-md-5 col-lg-6">
                <form action="api/search.php" method="GET" class="hng-search-container">
                    <input type="hidden" name="bid" value="<?php echo $branch_id; ?>">
                    <select name="type">
                        <option value="all">All</option>
                        <option value="rx">Prescription</option>
                    </select>
                    <input type="text" name="q" placeholder="Search medicines in <?php echo htmlspecialchars($branch_name); ?>...">
                    <button type="submit" class="hng-search-btn"><i class="mdi mdi-magnify"></i></button>
                </form>
            </div>

            <div class="col-md-4 col-lg-4 d-flex justify-content-between mt-3 mt-md-0">
                <a href="<?php echo BASE_URL; ?>online_store.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                    <i class="mdi mdi-home"></i>Home
                </a>
                <a href="#" class="hng-nav-icon"><i class="mdi mdi-view-grid"></i>Category</a>
                <a href="api/offers.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                    <i class="mdi mdi-label-percent"></i>Offer
                </a>
                <a href="help.php?bid=<?php echo $branch_id; ?>" class="hng-nav-icon">
                    <i class="mdi mdi-help-circle"></i>Help
                </a>
                <a href="javascript:void(0);" onclick="openBankDetails()" class="hng-nav-icon">
    <i class="mdi mdi-bank"></i>Bank Details
</a>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).on('click', '#subscribeBtn', function(e) {
    e.preventDefault();
    const btn = $(this);
    const currentStatus = btn.data('status'); // 'subscribed' or 'unsubscribed'
    const branchName = "<?php echo $branch_name; ?>";
    
    btn.text('Processing...').css({'opacity': '0.5', 'pointer-events': 'none'});

    $.ajax({
        url: '/pharmacy_v1-master/dashboard/actions/subscribe_to_branch.php',
        type: 'POST',
        data: {
            client_id: btn.data('client'),
            branch_id: btn.data('branch'),
            pharmacy_id: btn.data('pharmacy'),
            action: currentStatus === 'subscribed' ? 'unsubscribe' : 'subscribe'
        },
        dataType: 'json',
        success: function(res) {
            if(res.status === 'success') {
                if(currentStatus === 'unsubscribed') {
                    // Switch to Subscribed state
                    btn.text('✓ Subscribed').data('status', 'subscribed').css({'color': '#00b386', 'font-weight': 'bold', 'opacity': '1', 'pointer-events': 'auto'});
                } else {
                    // Switch back to Unsubscribed state
                    btn.text('Subscribe to ' + branchName).data('status', 'unsubscribed').css({'color': '', 'font-weight': '', 'opacity': '1', 'pointer-events': 'auto'});
                }
            } else {
                alert(res.message);
                btn.css({'opacity': '1', 'pointer-events': 'auto'});
            }
        }
    });
});

function openNotifications() {
    // 1. Show the modal
    $('#notificationModal').modal('show');
    
    // 2. Hide the green badge immediately so it looks "read"
    $('#notificationBadge').fadeOut();

    // 3. Fetch the messages
    $.ajax({
        url: 'api/fetch_client_notifications.php',
        method: 'GET',
        success: function(data) {
            $('#notificationBody').html(data);
        },
        error: function() {
            $('#notificationBody').html('<div class="text-center p-3 text-danger">Could not load notifications.</div>');
        }
    });
}

function openBankDetails() {
    $('#bankDetailsModal').modal('show');
    
    $.ajax({
        url: 'api/fetch_bank_details.php',
        method: 'GET',
        data: { bid: '<?php echo $branch_id; ?>' },
        success: function(data) {
            $('#bankDetailsBody').html(data);
        },
        error: function() {
            $('#bankDetailsBody').html('<div class="alert alert-danger">Unable to load payment details.</div>');
        }
    });
}
</script>

<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="mdi mdi-message-text-outline me-2"></i>Admin Responses</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="notificationBody" style="max-height: 400px; overflow-y: auto;">
                <div class="text-center p-3">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="bankDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" style="color: var(--echo-teal);">
                    <i class="mdi mdi-bank-transfer me-2"></i>Payment Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="bankDetailsBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status"></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
</body>
</html>