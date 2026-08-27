<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_store_page = basename($_SERVER['PHP_SELF'] ?? 'online_store.php');

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

// =========================================================
// PHARMACY LOGO RESOLUTION
// =========================================================
// IMPORTANT:
// store_header.php is inside /api/, while admin-uploaded pharmacy
// logos are stored in the project-level /uploads/logos/ directory.
// Therefore the filesystem path must go one directory UP from /api/.
//
// The logo is selected from the pharmacy attached to the CURRENT
// ACTIVE BRANCH (b.pharmacy_id -> p.logo). This prevents one pharmacy
// from accidentally displaying another pharmacy's logo.

$logo_filename = 'default_logo.png';

if (!empty($db_logo)) {
    // The admin may store either just the filename or a path such as:
    // uploads/logos/logo.png, /uploads/logos/logo.png, etc.
    // Only keep the filename so the public URL is always constructed
    // from the trusted uploads directory.
    $candidate_logo = trim((string)$db_logo);
    $candidate_logo = str_replace('\\', '/', $candidate_logo);
    $candidate_logo = basename(parse_url($candidate_logo, PHP_URL_PATH) ?: $candidate_logo);

    if ($candidate_logo !== '' && $candidate_logo !== '.' && $candidate_logo !== '..') {
        $logo_filename = $candidate_logo;
    }
}

// Actual filesystem location: /project/uploads/logos/
$logo_upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR;
$logo_local_path = $logo_upload_dir . $logo_filename;

// Public URL from pages inside /api/. Using ../ makes this work both
// locally (XAMPP) and on the deployed Render application.
if (is_file($logo_local_path)) {
    $logo_web_path = '../uploads/logos/' . rawurlencode($logo_filename);
} else {
    // Safe fallback if the admin has not uploaded a logo for this pharmacy.
    $fallback_logo = $logo_upload_dir . 'default_logo.png';
    if (is_file($fallback_logo)) {
        $logo_web_path = '../uploads/logos/default_logo.png';
    } else {
        // Leave a harmless empty value rather than pointing to a non-existent
        // /api/uploads directory. The image element below will use its
        // visual fallback.
        $logo_web_path = '';
    }
}

// Escape once for use in HTML attributes.
$logo_web_path_html = htmlspecialchars($logo_web_path, ENT_QUOTES, 'UTF-8');

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

/* =========================================================
   PERSISTENT ONLINE CART HEADER COUNT
   Keep the original header/layout intact. For logged-in
   customers, the badge reads the same persistent cart used
   by api/cart.php. Guests continue using the session cart.
   ========================================================= */
$header_cart_count = 0;

if (isset($_SESSION['client_id'])) {
    $header_client_id = (int)$_SESSION['client_id'];

    // Read persistent cart rows without doing any SQL date comparison.
    // This avoids MySQL strict-mode failures from legacy 0000-00-00 values.
    $header_cart_sql = "SELECT
                            c.id,
                            c.product_id,
                            c.quantity,
                            c.created_at,
                            si.quantity AS stock_quantity,
                            si.is_online,
                            si.is_active,
                            si.expiry_date,
                            si.pharmacy_id,
                            si.branch_id
                        FROM online_cart_items c
                        INNER JOIN store_items si
                            ON si.id = c.product_id
                        WHERE c.client_id = ?
                          AND c.branch_id = ?
                          AND c.pharmacy_id = ?";

    if ($header_stmt = $conn->prepare($header_cart_sql)) {
        $header_stmt->bind_param("iii", $header_client_id, $branch_id, $parent_pharmacy_id);
        $header_stmt->execute();
        $header_result = $header_stmt->get_result();

        $header_now = new DateTime('now', new DateTimeZone('Africa/Lusaka'));
        $header_cutoff = (clone $header_now)->modify('-1 month');
        $header_remove_ids = [];

        while ($header_item = $header_result->fetch_assoc()) {
            $remove_item = false;

            $created_raw = trim((string)($header_item['created_at'] ?? ''));
            if ($created_raw !== '') {
                try {
                    $created_at = new DateTime($created_raw, new DateTimeZone('Africa/Lusaka'));
                    if ($created_at < $header_cutoff) {
                        $remove_item = true;
                    }
                } catch (Throwable $e) {
                    // Leave malformed timestamps for cart.php to handle safely.
                }
            }

            $stock = (int)($header_item['stock_quantity'] ?? 0);
            if ((int)($header_item['is_online'] ?? 0) !== 1 ||
                (int)($header_item['is_active'] ?? 0) !== 1 ||
                $stock <= 0 ||
                (int)($header_item['pharmacy_id'] ?? 0) !== $parent_pharmacy_id ||
                (int)($header_item['branch_id'] ?? 0) !== $branch_id) {
                $remove_item = true;
            }

            $expiry_raw = trim((string)($header_item['expiry_date'] ?? ''));
            if ($expiry_raw !== '' && $expiry_raw !== '0000-00-00' && $expiry_raw !== '0000-00-00 00:00:00') {
                try {
                    $expiry_date = new DateTime(substr($expiry_raw, 0, 10), new DateTimeZone('Africa/Lusaka'));
                    $today = new DateTime('today', new DateTimeZone('Africa/Lusaka'));
                    if ($expiry_date < $today) {
                        $remove_item = true;
                    }
                } catch (Throwable $e) {
                    // Treat an unreadable legacy expiry as non-blocking here.
                }
            }

            if ($remove_item) {
                $header_remove_ids[] = (int)$header_item['id'];
                continue;
            }

            $header_cart_count += max(1, min((int)$header_item['quantity'], $stock));
        }

        $header_result->free();
        $header_stmt->close();

        // Clean only items that are definitely no longer valid.
        if (!empty($header_remove_ids)) {
            $delete_stmt = $conn->prepare("DELETE FROM online_cart_items WHERE id = ? AND client_id = ?");
            if ($delete_stmt) {
                foreach ($header_remove_ids as $remove_id) {
                    $delete_stmt->bind_param("ii", $remove_id, $header_client_id);
                    $delete_stmt->execute();
                }
                $delete_stmt->close();
            }
        }
    }
} elseif (isset($_SESSION['carts'][$branch_id]) && is_array($_SESSION['carts'][$branch_id])) {
    foreach ($_SESSION['carts'][$branch_id] as $cart_item) {
        $header_cart_count += isset($cart_item['qty']) ? max(1, (int)$cart_item['qty']) : 1;
    }
}


/* =========================================================
   SUBSCRIPTION / CUSTOMER ENGAGEMENT

   IMPORTANT BUSINESS RULE:
   - A customer is subscribed when a matching row exists in
     customers for the CURRENT pharmacy + branch.
   - Match priority: client_id, then exact email, then exact phone.
   - When an existing manual customer is matched, it is linked to
     the logged-in client_id instead of creating a duplicate.
   - Unsubscribe removes ONLY the matched subscription/customer row.
   ========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (string)($_POST['store_action'] ?? '') === 'toggle_subscription'
) {
    header('Content-Type: application/json; charset=utf-8');

    $sid = (int)($_SESSION['client_id'] ?? 0);
    $sbid = (int)($_POST['branch_id'] ?? 0);

    if ($sid <= 0) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Please log in before subscribing.']);
        exit;
    }
    if ($sbid <= 0) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Invalid branch.']);
        exit;
    }

    $st = $conn->prepare("SELECT id, pharmacy_id, branch_name FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$st) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Unable to verify the branch.']);
        exit;
    }
    $st->bind_param('i', $sbid);
    $st->execute();
    $branch = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$branch) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'The selected branch is unavailable.']);
        exit;
    }

    $spid = (int)$branch['pharmacy_id'];

    $st = $conn->prepare("SELECT id, full_name, phone, email FROM clients WHERE id = ? LIMIT 1");
    if (!$st) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Unable to load your account.']);
        exit;
    }
    $st->bind_param('i', $sid);
    $st->execute();
    $client = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$client) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Your customer account could not be found.']);
        exit;
    }

    $sname  = trim((string)($client['full_name'] ?? 'Client'));
    $sphone = trim((string)($client['phone'] ?? ''));
    $semail = strtolower(trim((string)($client['email'] ?? '')));
    if ($sname === '') $sname = 'Client';

    /*
       Find an existing customer record.
       First use client_id. If an older/manual customer has no client_id,
       match the same account by exact email or phone and link it.
    */
    $existing = null;

    $st = $conn->prepare("SELECT id, client_id, name, phone, email
                          FROM customers
                          WHERE pharmacy_id = ? AND branch_id = ? AND client_id = ?
                          LIMIT 1");
    if ($st) {
        $st->bind_param('iii', $spid, $sbid, $sid);
        $st->execute();
        $existing = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if (!$existing && $semail !== '') {
        $st = $conn->prepare("SELECT id, client_id, name, phone, email
                              FROM customers
                              WHERE pharmacy_id = ? AND branch_id = ?
                                AND LOWER(TRIM(COALESCE(email,''))) = ?
                              LIMIT 1");
        if ($st) {
            $st->bind_param('iis', $spid, $sbid, $semail);
            $st->execute();
            $existing = $st->get_result()->fetch_assoc();
            $st->close();
        }
    }

    if (!$existing && $sphone !== '') {
        $st = $conn->prepare("SELECT id, client_id, name, phone, email
                              FROM customers
                              WHERE pharmacy_id = ? AND branch_id = ?
                                AND TRIM(COALESCE(phone,'')) = ?
                              LIMIT 1");
        if ($st) {
            $st->bind_param('iis', $spid, $sbid, $sphone);
            $st->execute();
            $existing = $st->get_result()->fetch_assoc();
            $st->close();
        }
    }

    /* Already subscribed -> remove that subscription row. */
    if ($existing) {
        $rid = (int)$existing['id'];
        $st = $conn->prepare("DELETE FROM customers
                              WHERE id = ? AND pharmacy_id = ? AND branch_id = ?
                              LIMIT 1");
        if (!$st) {
            echo json_encode(['success'=>false,'subscribed'=>true,'message'=>'Unable to unsubscribe right now.']);
            exit;
        }
        $st->bind_param('iii', $rid, $spid, $sbid);
        $ok = $st->execute();
        $st->close();

        if (!$ok) {
            echo json_encode(['success'=>false,'subscribed'=>true,'message'=>'Unable to unsubscribe right now.']);
            exit;
        }

        echo json_encode([
            'success'=>true,
            'subscribed'=>false,
            'customer_id'=>$rid,
            'message'=>'You are no longer subscribed to ' . (string)$branch['branch_name'] . '.'
        ]);
        exit;
    }

    /* Not subscribed -> create a customer record linked to this client. */
    $st = $conn->prepare("INSERT INTO customers
                          (pharmacy_id, branch_id, client_id, name, phone, email)
                          VALUES (?, ?, ?, ?, ?, ?)");
    if (!$st) {
        echo json_encode(['success'=>false,'subscribed'=>false,'message'=>'Unable to create your subscription.']);
        exit;
    }
    $st->bind_param('iiisss', $spid, $sbid, $sid, $sname, $sphone, $semail);
    $ok = $st->execute();
    $rid = (int)$st->insert_id;
    $err = (string)$st->error;
    $st->close();

    if (!$ok) {
        echo json_encode([
            'success'=>false,
            'subscribed'=>false,
            'message'=>$err !== '' ? 'Unable to subscribe: ' . $err : 'Unable to subscribe right now.'
        ]);
        exit;
    }

    echo json_encode([
        'success'=>true,
        'subscribed'=>true,
        'customer_id'=>$rid,
        'branch_id'=>$sbid,
        'pharmacy_id'=>$spid,
        'message'=>'Subscribed to ' . (string)$branch['branch_name'] . '. You are now in this pharmacy\'s customer list.'
    ]);
    exit;
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
        .mega-item{position:relative;display:flex;align-items:center;}
        .mega-trigger{height:46px;display:flex;align-items:center;padding:0 17px;color:#5f6f7f;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap;border-bottom:2px solid transparent;transition:.18s ease;}
        .mega-trigger:hover,.mega-trigger.active{color:#1769d1;border-bottom-color:#1769d1;background:#fbfcfd;}
        .mega-trigger .mdi{font-size:14px;margin-left:4px;}

        .mega-panel{
            position:absolute;
            left:0;
            top:100%;
            width:390px;
            display:none;
            background:#fff;
            border:1px solid #e5e9ed;
            border-top:0;
            border-radius:0 0 7px 7px;
            box-shadow:0 8px 20px rgba(20,35,50,.16);
            overflow:hidden;
            z-index:2500;
        }
        .mega-item:hover>.mega-panel,
        .mega-item:focus-within>.mega-panel,
        .mega-item.menu-open>.mega-panel{display:block;}

        .mega-panel-inner{
            display:grid;
            grid-template-columns:210px 180px;
            gap:0;
            width:100%;
            min-height:200px;
            max-height:430px;
        }
        .mega-side{
            border-right:1px solid #edf0f3;
            padding:9px 0;
            background:#fff;
            overflow-y:auto;
            max-height:430px;
        }
        .mega-side-title{display:none;}
        .mega-side-link{
            display:flex;
            align-items:center;
            justify-content:space-between;
            text-decoration:none;
            color:#5f6f7f;
            font-size:12px;
            font-weight:500;
            line-height:1.2;
            padding:9px 18px 9px 20px;
            border-radius:0;
            min-height:36px;
            transition:background .12s ease,color .12s ease;
        }
        .mega-side-link:hover,
        .mega-side-link.active{
            background:#f0f3f7;
            color:#3d4c5a;
        }
        .mega-side-link .mdi{font-size:15px;color:#333;}
        .mega-content{
            min-width:0;
            background:#f4f7fa;
            padding:9px 0 12px;
            overflow-y:auto;
            max-height:430px;
        }
        .mega-heading{
            display:none;
        }
        .mega-group-content{display:none;}
        .mega-group-content.active{display:block;}
        .mega-group-title{
            display:none;
        }
        .mega-grid{
            display:block;
            max-height:none;
            overflow:visible;
            padding:0;
        }
        .mega-link{
            display:block;
            color:#657585;
            text-decoration:none;
            font-size:12px;
            font-weight:500;
            padding:8px 18px;
            line-height:1.2;
            border-radius:0;
            white-space:normal;
        }
        .mega-link:hover{background:#eaf0f5;color:#1769d1;}
        .mega-link .mdi{display:none;}

        /* Smart edge-aware dropdown positioning.
           Default: open toward the right.
           When the full 390px panel would cross the viewport's right edge,
           JavaScript adds .mega-open-left and the panel opens toward the left. */
        .mega-item > .mega-panel{left:0;right:auto;}
        .mega-item > .mega-panel.mega-open-left{left:auto;right:0;}

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


        /* ========================= MOBILE TRUEMEDS-STYLE LAYOUT ========================= */
        .tm-mobile-shell{display:none;}
        .tm-mobile-mainbar{height:58px;background:#fff;border-bottom:1px solid #e8edf1;display:flex;align-items:center;padding:0 12px;gap:10px;position:relative;z-index:2200;}
        .tm-mobile-menu-btn,.tm-mobile-icon-btn{border:0;background:transparent;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#173b59;font-size:22px;flex:0 0 38px;}
        .tm-mobile-menu-btn:active,.tm-mobile-icon-btn:active{background:#f0f4f7;}
        .tm-mobile-brand{min-width:0;flex:1;text-decoration:none;color:var(--echo-teal);display:flex;align-items:center;gap:7px;}
        .tm-mobile-brand img{width:31px;height:31px;object-fit:contain;}
        .tm-mobile-brand strong{font-size:18px;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:145px;}
        .tm-mobile-cart{position:relative;}
        .tm-mobile-cart .cart-count{font-size:8px!important;min-width:15px;line-height:15px;padding:0 3px;top:2px!important;}
        .tm-mobile-search-row{background:#fff;padding:8px 12px 10px;border-bottom:1px solid #e7ecef;position:relative;z-index:2150;}
        .tm-mobile-search{height:43px;background:#f5f7f9;border:1px solid #dfe5e9;border-radius:9px;display:flex;align-items:center;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
        .tm-mobile-search input{border:0;background:transparent;outline:0;flex:1;min-width:0;padding:0 10px;font-size:13px;color:#253746;}
        .tm-mobile-search button{border:0;background:#1769d1;color:#fff;width:43px;height:43px;display:flex;align-items:center;justify-content:center;font-size:19px;}
        .tm-mobile-location{margin-top:7px;display:flex;align-items:center;gap:5px;color:#506170;font-size:11px;white-space:nowrap;overflow:hidden;}
        .tm-mobile-location strong{color:#1769d1;overflow:hidden;text-overflow:ellipsis;}
        .tm-mobile-category-strip{display:none;gap:8px;overflow-x:auto;padding:9px 12px;background:#fff;border-bottom:1px solid #e7ecef;scrollbar-width:none;position:relative;z-index:2100;}
        .tm-mobile-category-strip::-webkit-scrollbar{display:none;}
        .tm-mobile-category-chip{flex:0 0 auto;border:1px solid #e1e7eb;background:#fff;border-radius:18px;padding:7px 12px;text-decoration:none;color:#435565;font-size:11px;font-weight:700;white-space:nowrap;}
        .tm-mobile-category-chip:active{background:#eef5ff;color:#1769d1;border-color:#c9ddf8;}
        .tm-mobile-quick-strip{display:flex;gap:10px;overflow-x:auto;padding:10px 12px;background:#f8fafb;scrollbar-width:none;}
        .tm-mobile-quick-strip::-webkit-scrollbar{display:none;}
        .tm-mobile-quick-card{flex:0 0 148px;background:#fff;border:1px solid #e3e9ed;border-radius:10px;padding:10px;text-decoration:none;display:flex;align-items:center;gap:8px;box-shadow:0 2px 5px rgba(25,45,65,.05);}
        .tm-mobile-quick-card i{font-size:22px;}
        .tm-mobile-quick-card strong{display:block;font-size:11px;line-height:1.15;color:#253746;}
        .tm-mobile-quick-card span{display:block;font-size:9px;color:#778693;margin-top:2px;}
        .tm-mobile-drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.42);z-index:5000;}
        .tm-mobile-drawer{position:fixed;top:0;left:0;bottom:0;width:min(86vw,360px);background:#fff;transform:translateX(-105%);transition:transform .24s ease;z-index:5100;box-shadow:8px 0 28px rgba(0,0,0,.16);overflow-y:auto;}
        .tm-mobile-drawer.open{transform:translateX(0);}
        .tm-mobile-drawer-backdrop.open{display:block;}
        .tm-mobile-drawer-head{padding:16px 14px 13px;background:#fff;border-bottom:1px solid #e6ebef;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:2;}
        .tm-mobile-drawer-logo{width:38px;height:38px;object-fit:contain;}
        .tm-mobile-drawer-title{flex:1;min-width:0;}
        .tm-mobile-drawer-title strong{display:block;color:var(--echo-teal);font-size:18px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .tm-mobile-drawer-title span{display:block;color:#7a8791;font-size:10px;margin-top:2px;}
        .tm-mobile-drawer-close{border:0;background:#f2f5f7;border-radius:50%;width:34px;height:34px;font-size:20px;color:#34495e;}
        .tm-mobile-drawer-account{margin:12px;border:1px solid #e2e8ec;border-radius:10px;padding:11px;text-decoration:none;display:flex;align-items:center;gap:9px;color:#253746;}
        .tm-mobile-drawer-account i{font-size:25px;color:#1769d1;}
        .tm-mobile-drawer-account strong{font-size:12px;display:block;}
        .tm-mobile-drawer-account span{font-size:10px;color:#7b8892;display:block;margin-top:2px;}
        .tm-mobile-drawer-section-title{padding:9px 14px 6px;color:#8a959e;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;}
        .tm-mobile-drawer-link{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #f0f2f4;text-decoration:none;color:#314555;font-size:13px;font-weight:700;}
        .tm-mobile-drawer-link .mdi:first-child{font-size:19px;width:26px;color:#1769d1;}
        .tm-mobile-accordion{border-bottom:1px solid #f0f2f4;}
        .tm-mobile-accordion-btn{width:100%;border:0;background:#fff;padding:12px 14px;display:flex;align-items:center;gap:8px;text-align:left;color:#314555;font-size:13px;font-weight:700;}
        .tm-mobile-accordion-btn .label{flex:1;}
        .tm-mobile-accordion-content{display:none;background:#f8fafb;padding:4px 0 8px;}
        .tm-mobile-accordion.open .tm-mobile-accordion-content{display:block;}
        .tm-mobile-accordion.open .tm-mobile-accordion-btn{color:#1769d1;background:#f5f9fe;}
        .tm-mobile-subgroup-btn{width:100%;border:0;background:transparent;padding:10px 18px 9px 40px;text-align:left;display:flex;justify-content:space-between;color:#526575;font-size:12px;font-weight:700;}
        .tm-mobile-subitems{display:none;padding:0 16px 7px 52px;}
        .tm-mobile-subgroup.open .tm-mobile-subitems{display:block;}
        .tm-mobile-subitems a{display:block;padding:6px 0;text-decoration:none;color:#71808b;font-size:11px;}
        .tm-mobile-branch-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:5200;}
        .tm-mobile-branch-backdrop.open{display:block;}
        .tm-mobile-branch-sheet{position:fixed;left:50%;bottom:0;transform:translate(-50%,105%);width:min(100%,430px);background:#fff;border-radius:16px 16px 0 0;z-index:5250;box-shadow:0 -10px 35px rgba(0,0,0,.18);transition:transform .22s ease;padding:14px 14px 18px;}
        .tm-mobile-branch-sheet.open{transform:translate(-50%,0);}
        .tm-mobile-branch-sheet-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid #edf0f2;}
        .tm-mobile-branch-sheet-head strong{font-size:15px;color:#243746;}
        .tm-mobile-branch-sheet-close{border:0;background:#f1f4f6;width:32px;height:32px;border-radius:50%;font-size:18px;color:#425463;}
        .tm-mobile-branch-option{width:100%;border:1px solid #e3e8ec;background:#fff;border-radius:9px;padding:11px 12px;margin-top:8px;display:flex;align-items:center;justify-content:space-between;text-align:left;color:#344958;font-size:12px;font-weight:700;}
        .tm-mobile-branch-option.active{border-color:#1769d1;background:#f3f8ff;color:#1769d1;}

        .tm-mobile-bottom-nav{display:none;}

        @media (max-width:767.98px){
            html,body{background:#f7f9fa;}
            .store-topbar,.mega-nav-wrap{display:none!important;}
            .tm-mobile-shell{display:block;}
            .tier-3-action{display:none!important;}
            body{padding-bottom:65px;}
            .container{max-width:100%;}
            .tm-mobile-bottom-nav{position:fixed;display:flex;left:0;right:0;bottom:0;height:62px;background:#fff;border-top:1px solid #dfe5e9;z-index:4200;box-shadow:0 -4px 14px rgba(25,45,65,.08);padding-bottom:env(safe-area-inset-bottom);}
            .tm-mobile-bottom-nav a{flex:1;text-decoration:none;color:#657481;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:9px;font-weight:700;gap:2px;}
            .tm-mobile-bottom-nav a.active,.tm-mobile-bottom-nav a:active{color:#1769d1;}
            .tm-mobile-bottom-nav i{font-size:21px;line-height:1;}
            .tm-mobile-bottom-nav .bottom-cart{position:relative;}
            .tm-mobile-bottom-nav .bottom-cart .cart-count{position:absolute!important;top:0!important;left:50%;margin-left:6px;font-size:8px!important;min-width:15px;line-height:15px;padding:0 3px;}
            .feature-card{min-height:74px;border-radius:9px!important;}
            .feature-card i{font-size:25px!important;margin-right:7px!important;}
            .feature-title{font-size:11px!important;line-height:1.15;}
            .feature-sub{font-size:9px!important;}
            .product-card{border-radius:9px!important;padding:8px!important;min-height:100%;}
            .product-card img{height:105px!important;max-width:100%;}
            .product-title{font-size:11px!important;line-height:1.2!important;min-height:39px;}
            .product-price{font-size:14px!important;}
            .add-to-cart-btn{font-size:10px!important;padding:7px 5px!important;border-radius:6px!important;}
            .wa-sticky{bottom:72px!important;right:10px!important;margin:0!important;width:48px;height:48px;padding:0!important;display:flex;align-items:center;justify-content:center;}
            .wa-sticky i{font-size:25px!important;}
            .tm-mobile-quick-strip + .container{margin-top:8px!important;}
        }
        @media (min-width:768px){
            .tm-mobile-shell,.tm-mobile-bottom-nav{display:none!important;}
        }

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
    ],
    'Agrovert' => [
        'icon' => 'mdi-sprout',
        'section' => 'agrovert',
        'groups' => [
            'Agrovet Products' => [
                'Veterinary Medicines',
                'Animal Health',
                'Livestock Care',
                'Poultry Care',
                'Pet Care',
                'Animal Supplements',
                'Dewormers',
                'Flea and Tick Control',
                'Vaccines',
                'Antiseptics and Disinfectants'
            ]
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

<!-- ========================= MOBILE STORE SHELL ========================= -->
<div class="tm-mobile-shell">
    <div class="tm-mobile-mainbar">
        <button type="button" class="tm-mobile-menu-btn" id="tmMobileMenuOpen" aria-label="Open menu">
            <i class="mdi mdi-menu"></i>
        </button>
        <a class="tm-mobile-brand" href="online_store.php?bid=<?php echo $branch_id; ?>">
            <img src="<?php echo $logo_web_path_html; ?>" alt="">
            <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong>
        </a>
        <a class="tm-mobile-icon-btn tm-mobile-cart" href="cart.php?bid=<?php echo $branch_id; ?>" aria-label="Cart">
            <i class="mdi mdi-cart-outline"></i>
            <span class="badge bg-danger position-absolute rounded-pill cart-count">
                <?php echo $header_cart_count; ?>
            </span>
        </a>
        <button type="button" class="tm-mobile-icon-btn" id="tmMobileAccountOpen" aria-label="Account">
            <i class="mdi mdi-account-circle-outline"></i>
        </button>
    </div>

    <div class="tm-mobile-search-row">
        <form action="online_store.php" method="GET" class="tm-mobile-search">
            <input type="hidden" name="bid" value="<?php echo $branch_id; ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" placeholder="Search medicines, products and health care..." autocomplete="off">
            <button type="submit" aria-label="Search"><i class="mdi mdi-magnify"></i></button>
        </form>
        <div class="tm-mobile-location">
            <i class="mdi mdi-map-marker-outline"></i>
            <span>Shopping in</span>
            <strong><?php echo htmlspecialchars($branch_name); ?></strong>
            <span>Ã‚Â·</span>
            <a href="javascript:void(0);" id="tmMobileBranchOpen" style="color:#1769d1;text-decoration:none;font-weight:700;">Change</a>
        </div>
    </div>

    <div class="tm-mobile-category-strip" aria-label="Categories">
        <?php foreach ($nav as $label => $menu): ?>
            <a class="tm-mobile-category-chip" href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">
                <?php echo htmlspecialchars($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="tm-mobile-drawer-backdrop" id="tmMobileBackdrop"></div>
    <aside class="tm-mobile-drawer" id="tmMobileDrawer" aria-label="Mobile navigation">
        <div class="tm-mobile-drawer-head">
            <img class="tm-mobile-drawer-logo" src="<?php echo $logo_web_path_html; ?>" alt="">
            <div class="tm-mobile-drawer-title">
                <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong>
                <span>Online Pharmacy Ã‚Â· <?php echo htmlspecialchars($branch_name); ?></span>
            </div>
            <button type="button" class="tm-mobile-drawer-close" id="tmMobileMenuClose" aria-label="Close menu"><i class="mdi mdi-close"></i></button>
        </div>

        <a class="tm-mobile-drawer-account" href="<?php echo isset($_SESSION['client_id']) ? 'profile.php?bid='.$branch_id : 'login_client.php?bid='.$branch_id; ?>">
            <i class="mdi mdi-account-circle-outline"></i>
            <span><strong><?php echo isset($_SESSION['client_id']) ? htmlspecialchars($_SESSION['client_name'] ?? 'My Account') : 'Login / Register'; ?></strong><span>Manage account and orders</span></span>
            <i class="mdi mdi-chevron-right" style="font-size:19px;color:#84919b;width:auto;"></i>
        </a>

        <div class="tm-mobile-drawer-section-title">Shop</div>
        <a class="tm-mobile-drawer-link" href="online_store.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-home-outline"></i> Home</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="categories.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-view-grid-outline"></i> All Categories</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="offers.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-tag-outline"></i> Offers</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="cart.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-cart-outline"></i> My Cart</span><i class="mdi mdi-chevron-right"></i></a>

        <div class="tm-mobile-drawer-section-title">Categories</div>
        <?php foreach ($nav as $label => $menu): ?>
            <?php if (empty($menu['groups'])): ?>
                <a class="tm-mobile-drawer-link" href="<?php echo htmlspecialchars($make_section_url($menu['section'], $branch_id)); ?>">
                    <span><i class="mdi <?php echo htmlspecialchars($menu['icon']); ?>"></i> <?php echo htmlspecialchars($label); ?></span>
                    <i class="mdi mdi-chevron-right"></i>
                </a>
            <?php else: ?>
                <div class="tm-mobile-accordion">
                    <button type="button" class="tm-mobile-accordion-btn">
                        <i class="mdi <?php echo htmlspecialchars($menu['icon']); ?>" style="font-size:19px;width:26px;color:#1769d1;"></i>
                        <span class="label"><?php echo htmlspecialchars($label); ?></span>
                        <i class="mdi mdi-chevron-down accordion-chevron"></i>
                    </button>
                    <div class="tm-mobile-accordion-content">
                        <?php foreach ($menu['groups'] as $group => $links): ?>
                            <div class="tm-mobile-subgroup">
                                <button type="button" class="tm-mobile-subgroup-btn">
                                    <span><?php echo htmlspecialchars($group); ?></span>
                                    <i class="mdi mdi-chevron-down"></i>
                                </button>
                                <div class="tm-mobile-subitems">
                                    <a href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>">View <?php echo htmlspecialchars($group); ?></a>
                                    <?php foreach ($links as $sub): ?>
                                        <a href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>"><?php echo htmlspecialchars($sub); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="tm-mobile-drawer-section-title">Help & Services</div>
        <a class="tm-mobile-drawer-link" href="upload_prescription.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-prescription"></i> Upload Prescription</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="pharmacist.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-account-tie-outline"></i> Pharmacists</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="help.php?bid=<?php echo $branch_id; ?>"><span><i class="mdi mdi-help-circle-outline"></i> Help & Support</span><i class="mdi mdi-chevron-right"></i></a>
        <a class="tm-mobile-drawer-link" href="javascript:void(0);" id="tmMobileBranchLink"><span><i class="mdi mdi-map-marker-outline"></i> Switch Branch</span><i class="mdi mdi-chevron-right"></i></a>
    </aside>

    <div class="tm-mobile-branch-backdrop" id="tmMobileBranchBackdrop"></div>
    <div class="tm-mobile-branch-sheet" id="tmMobileBranchSheet" role="dialog" aria-modal="true" aria-label="Switch branch">
        <div class="tm-mobile-branch-sheet-head">
            <strong>Choose branch</strong>
            <button type="button" class="tm-mobile-branch-sheet-close" id="tmMobileBranchClose"><i class="mdi mdi-close"></i></button>
        </div>
        <?php
        if ($parent_pharmacy_id > 0 && ($stmt_mbr = $conn->prepare("SELECT id, branch_name FROM branches WHERE pharmacy_id = ? AND is_active = 1 ORDER BY branch_name ASC"))) {
            $stmt_mbr->bind_param("i", $parent_pharmacy_id);
            $stmt_mbr->execute();
            $mbr_list = $stmt_mbr->get_result();
            while ($mbr = $mbr_list->fetch_assoc()):
                $mbr_id = (int)$mbr['id'];
                $mbr_active = ($mbr_id === $branch_id);
        ?>
            <button type="button" class="tm-mobile-branch-option <?php echo $mbr_active ? 'active' : ''; ?>" data-branch-url="switch_branch.php?bid=<?php echo $mbr_id; ?>">
                <span><i class="mdi mdi-map-marker-outline me-1"></i><?php echo htmlspecialchars($mbr['branch_name']); ?></span>
                <?php if ($mbr_active): ?><i class="mdi mdi-check-circle"></i><?php else: ?><i class="mdi mdi-chevron-right"></i><?php endif; ?>
            </button>
        <?php
            endwhile;
            $stmt_mbr->close();
        }
        ?>
    </div>

    <nav class="tm-mobile-bottom-nav" aria-label="Mobile quick navigation">
        <a class="<?php echo $current_store_page === 'online_store.php' ? 'active' : ''; ?>" href="online_store.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-home-outline"></i><span>Home</span></a>
        <a class="<?php echo $current_store_page === 'categories.php' ? 'active' : ''; ?>" href="categories.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-view-grid-outline"></i><span>Categories</span></a>
        <a class="<?php echo $current_store_page === 'upload_prescription.php' ? 'active' : ''; ?>" href="upload_prescription.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-prescription"></i><span>Prescription</span></a>
        <a class="bottom-cart <?php echo in_array($current_store_page, ['cart.php', 'view_cart.php'], true) ? 'active' : ''; ?>" href="cart.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-cart-outline"></i><span>Cart</span><span class="badge bg-danger rounded-pill cart-count"><?php echo $header_cart_count; ?></span></a>
        <a class="<?php echo in_array($current_store_page, ['profile.php', 'login_client.php'], true) ? 'active' : ''; ?>" href="<?php echo isset($_SESSION['client_id']) ? 'profile.php?bid='.$branch_id : 'login_client.php?bid='.$branch_id; ?>"><i class="mdi mdi-account-outline"></i><span>Account</span></a>
    </nav>
</div>

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
                $header_sub_client_id = (int)($_SESSION['client_id'] ?? 0);
                $header_sub_email = '';
                $header_sub_phone = '';

                if ($header_sub_client_id > 0) {
                    /* Load the logged-in account identity. */
                    if ($cs = $conn->prepare("SELECT email, phone FROM clients WHERE id = ? LIMIT 1")) {
                        $cs->bind_param('i', $header_sub_client_id);
                        $cs->execute();
                        $cr = $cs->get_result()->fetch_assoc() ?: [];
                        $header_sub_email = strtolower(trim((string)($cr['email'] ?? '')));
                        $header_sub_phone = trim((string)($cr['phone'] ?? ''));
                        $cs->close();
                    }

                    /* First: an already-linked app subscriber. */
                    $linked_id = 0;
                    if ($cs = $conn->prepare("SELECT id FROM customers WHERE pharmacy_id = ? AND branch_id = ? AND client_id = ? LIMIT 1")) {
                        $cs->bind_param('iii', $parent_pharmacy_id, $branch_id, $header_sub_client_id);
                        $cs->execute();
                        $linked = $cs->get_result()->fetch_assoc();
                        $linked_id = (int)($linked['id'] ?? 0);
                        $cs->close();
                    }

                    /*
                       Second: if an older/manual customer record already represents
                       this account by email or phone, link it to client_id. This
                       makes the Customers page identify it as a Subscribed App User.
                    */
                    if ($linked_id <= 0 && $header_sub_email !== '') {
                        if ($cs = $conn->prepare("SELECT id FROM customers
                                                 WHERE pharmacy_id = ? AND branch_id = ?
                                                   AND LOWER(TRIM(COALESCE(email,''))) = ?
                                                 LIMIT 1")) {
                            $cs->bind_param('iis', $parent_pharmacy_id, $branch_id, $header_sub_email);
                            $cs->execute();
                            $match = $cs->get_result()->fetch_assoc();
                            $linked_id = (int)($match['id'] ?? 0);
                            $cs->close();
                        }
                    }

                    if ($linked_id <= 0 && $header_sub_phone !== '') {
                        if ($cs = $conn->prepare("SELECT id FROM customers
                                                 WHERE pharmacy_id = ? AND branch_id = ?
                                                   AND TRIM(COALESCE(phone,'')) = ?
                                                 LIMIT 1")) {
                            $cs->bind_param('iis', $parent_pharmacy_id, $branch_id, $header_sub_phone);
                            $cs->execute();
                            $match = $cs->get_result()->fetch_assoc();
                            $linked_id = (int)($match['id'] ?? 0);
                            $cs->close();
                        }
                    }

                    if ($linked_id > 0) {
                        /* Link the existing customer row to the real app account. */
                        if ($cs = $conn->prepare("UPDATE customers
                                                 SET client_id = ?, name = ?, phone = ?, email = ?
                                                 WHERE id = ? AND pharmacy_id = ? AND branch_id = ?
                                                 LIMIT 1")) {
                            $sync_name = 'Client';
                            if ($ns = $conn->prepare("SELECT full_name FROM clients WHERE id = ? LIMIT 1")) {
                                $ns->bind_param('i', $header_sub_client_id);
                                $ns->execute();
                                $nr = $ns->get_result()->fetch_assoc() ?: [];
                                $sync_name = trim((string)($nr['full_name'] ?? 'Client')) ?: 'Client';
                                $ns->close();
                            }
                            $cs->bind_param('isssiii', $header_sub_client_id, $sync_name, $header_sub_phone, $header_sub_email, $linked_id, $parent_pharmacy_id, $branch_id);
                            $cs->execute();
                            $cs->close();
                        }
                        $is_subscribed = true;
                    }
                }

                ?>

                <?php if (isset($_SESSION['client_id'])): ?>
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline m-0 subscription-form">
                        <input type="hidden" name="store_action" value="toggle_subscription">
                        <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
                        <button type="submit" id="subscribeBtn" class="store-top-link toggle-subscribe border-0 bg-transparent p-0"
                                data-client="<?php echo (int)$_SESSION['client_id']; ?>"
                                data-branch="<?php echo (int)$branch_id; ?>"
                                data-pharmacy="<?php echo (int)$parent_pharmacy_id; ?>"
                                data-status="<?php echo $is_subscribed ? 'subscribed' : 'unsubscribed'; ?>">
                            <?php echo $is_subscribed ? 'âœ“ Subscribed' : 'Subscribe'; ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login_client.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Login</a>
                <?php endif; ?>

                <a href="pharmacist.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Pharmacists</a>
                <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="store-top-link">Prescriptions</a>

                <a href="cart.php?bid=<?php echo $branch_id; ?>" class="text-decoration-none position-relative">
                    <i class="mdi mdi-cart-outline top-cart"></i>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill cart-badge cart-count" style="font-size:9px;">
                        <?php echo $header_cart_count; ?>
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
                            <a href="my_orders.php?bid=<?php echo $branch_id; ?>" class="menu-link"><i class="mdi mdi-package-variant-closed"></i> Orders</a>
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
                    <div class="mega-panel" role="menu">
                        <div class="mega-panel-inner">
                            <div class="mega-side">
                                <?php $group_index = 0; ?>
                                <?php foreach ($menu['groups'] as $group => $links): ?>
                                    <a href="<?php echo htmlspecialchars($make_search_url($group, $branch_id)); ?>"
                                       class="mega-side-link <?php echo $group_index === 0 ? 'active' : ''; ?>"
                                       data-mega-group="<?php echo htmlspecialchars($menu['section'] . '-' . $group_index); ?>">
                                        <span><?php echo htmlspecialchars($group); ?></span>
                                        <i class="mdi mdi-chevron-right"></i>
                                    </a>
                                    <?php $group_index++; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="mega-content">
                                <?php $group_index = 0; ?>
                                <?php foreach ($menu['groups'] as $group => $links): ?>
                                    <div class="mega-group-content <?php echo $group_index === 0 ? 'active' : ''; ?>"
                                         data-mega-content="<?php echo htmlspecialchars($menu['section'] . '-' . $group_index); ?>">
                                        <div class="mega-grid">
                                            <?php foreach ($links as $sub): ?>
                                                <a class="mega-link" href="<?php echo htmlspecialchars($make_search_url($sub, $branch_id)); ?>">
                                                    <?php echo htmlspecialchars($sub); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php $group_index++; ?>
                                <?php endforeach; ?>
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
                    <img src="<?php echo $logo_web_path_html; ?>"
                         alt="<?php echo htmlspecialchars($pharmacy_name); ?> logo"
                         style="height:38px;width:38px;object-fit:contain;"
                         onerror="this.src='../uploads/logos/default_logo.png';">
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
    /* Mobile categories */
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

    /* =========================================================
       DESKTOP MEGA MENU â€” SMART VIEWPORT EDGE DETECTION
       The panel is 390px wide.  Before opening, measure the actual
       trigger position and switch sides when the right edge is too close.
       This fixes Health Guide / Agrovert and any future right-edge item.
       ========================================================= */
    document.querySelectorAll('.mega-item').forEach(function(item){
        const panel = item.querySelector('.mega-panel');
        if(!panel) return;

        const sideLinks = panel.querySelectorAll('.mega-side-link');
        const contents = panel.querySelectorAll('.mega-group-content');

        function activate(key){
            sideLinks.forEach(function(link){
                link.classList.toggle('active', link.dataset.megaGroup === key);
            });
            contents.forEach(function(content){
                content.classList.toggle('active', content.dataset.megaContent === key);
            });
        }

        function positionPanel(){
            if(window.innerWidth < 992) return;

            // Force the panel to be measurable while it is being positioned.
            panel.classList.remove('mega-open-left');

            const trigger = item.querySelector('.mega-trigger');
            if(!trigger) return;

            const triggerRect = trigger.getBoundingClientRect();
            const panelWidth = panel.getBoundingClientRect().width || 390;
            const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
            const edgeGap = 12;

            // First choice: left edge of panel aligns with the category.
            const rightEdge = triggerRect.left + panelWidth;

            // If that would overflow, anchor the panel's RIGHT edge to the
            // category's RIGHT edge so the entire panel opens to the left.
            if(rightEdge > viewportWidth - edgeGap){
                panel.classList.add('mega-open-left');
            }
        }

        function openMenu(){
            item.classList.add('menu-open');
            const current = panel.querySelector('.mega-side-link.active') || sideLinks[0];
            if(current) activate(current.dataset.megaGroup);

            // The panel becomes display:block after menu-open, so measure it
            // on the next frame using its real rendered width.
            requestAnimationFrame(positionPanel);
        }

        sideLinks.forEach(function(link){
            link.addEventListener('mouseenter', function(){
                activate(link.dataset.megaGroup);
            });
            link.addEventListener('focus', function(){
                activate(link.dataset.megaGroup);
            });
        });

        item.addEventListener('mouseenter', openMenu);

        item.addEventListener('mouseleave', function(){
            item.classList.remove('menu-open');
        });

        const trigger = item.querySelector('.mega-trigger');
        if(trigger && sideLinks.length){
            trigger.addEventListener('click', function(e){
                if(window.innerWidth >= 992){
                    e.preventDefault();
                    const wasOpen = item.classList.contains('menu-open');

                    document.querySelectorAll('.mega-item.menu-open').forEach(function(other){
                        if(other !== item){
                            other.classList.remove('menu-open');
                            const otherPanel = other.querySelector('.mega-panel');
                            if(otherPanel) otherPanel.classList.remove('mega-open-left');
                        }
                    });

                    if(wasOpen){
                        item.classList.remove('menu-open');
                        panel.classList.remove('mega-open-left');
                    }else{
                        openMenu();
                    }
                }
            });
        }

        // Recalculate when the browser is resized while this menu is open.
        window.addEventListener('resize', function(){
            if(item.classList.contains('menu-open')) positionPanel();
        });
    });


    /* Mobile drawer + accordion navigation */
    const mobileDrawer = document.getElementById('tmMobileDrawer');
    const mobileBackdrop = document.getElementById('tmMobileBackdrop');
    const mobileOpen = document.getElementById('tmMobileMenuOpen');
    const mobileClose = document.getElementById('tmMobileMenuClose');

    function openMobileDrawer(){
        if(!mobileDrawer || !mobileBackdrop) return;
        mobileDrawer.classList.add('open');
        mobileBackdrop.classList.add('open');
        document.body.style.overflow='hidden';
    }
    function closeMobileDrawer(){
        if(!mobileDrawer || !mobileBackdrop) return;
        mobileDrawer.classList.remove('open');
        mobileBackdrop.classList.remove('open');
        document.body.style.overflow='';
    }
    if(mobileOpen) mobileOpen.addEventListener('click', openMobileDrawer);
    if(mobileClose) mobileClose.addEventListener('click', closeMobileDrawer);
    const mobileAccountOpen = document.getElementById('tmMobileAccountOpen');
    if(mobileAccountOpen) mobileAccountOpen.addEventListener('click', openMobileDrawer);
    if(mobileBackdrop) mobileBackdrop.addEventListener('click', closeMobileDrawer);

    document.querySelectorAll('.tm-mobile-accordion-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            const parent = btn.closest('.tm-mobile-accordion');
            const wasOpen = parent.classList.contains('open');
            document.querySelectorAll('.tm-mobile-accordion.open').forEach(function(other){
                if(other !== parent) other.classList.remove('open');
            });
            parent.classList.toggle('open', !wasOpen);
            const icon = btn.querySelector('.accordion-chevron');
            if(icon) icon.className = 'mdi ' + (parent.classList.contains('open') ? 'mdi-chevron-up accordion-chevron' : 'mdi-chevron-down accordion-chevron');
        });
    });

    document.querySelectorAll('.tm-mobile-subgroup-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            const group = btn.closest('.tm-mobile-subgroup');
            const wasOpen = group.classList.contains('open');
            const parent = group.closest('.tm-mobile-accordion-content');
            if(parent){
                parent.querySelectorAll('.tm-mobile-subgroup.open').forEach(function(other){
                    if(other !== group) other.classList.remove('open');
                });
            }
            group.classList.toggle('open', !wasOpen);
            const icon = btn.querySelector('.mdi');
            if(icon) icon.className = 'mdi ' + (group.classList.contains('open') ? 'mdi-chevron-up' : 'mdi-chevron-down');
        });
    });

    const mobileBranchSheet = document.getElementById('tmMobileBranchSheet');
    const mobileBranchBackdrop = document.getElementById('tmMobileBranchBackdrop');
    const mobileBranchClose = document.getElementById('tmMobileBranchClose');
    function openBranchSheet(){
        if(!mobileBranchSheet || !mobileBranchBackdrop) return;
        mobileBranchSheet.classList.add('open');
        mobileBranchBackdrop.classList.add('open');
        closeMobileDrawer();
        document.body.style.overflow='hidden';
    }
    function closeBranchSheet(){
        if(!mobileBranchSheet || !mobileBranchBackdrop) return;
        mobileBranchSheet.classList.remove('open');
        mobileBranchBackdrop.classList.remove('open');
        document.body.style.overflow='';
    }
    function showBranchSelector(){ openBranchSheet(); }
    if(mobileBranchClose) mobileBranchClose.addEventListener('click', closeBranchSheet);
    if(mobileBranchBackdrop) mobileBranchBackdrop.addEventListener('click', closeBranchSheet);
    document.querySelectorAll('.tm-mobile-branch-option').forEach(function(btn){
        btn.addEventListener('click', function(){
            const url = btn.getAttribute('data-branch-url');
            if(url) window.location.href = url;
        });
    });
    const mobileBranchOpen = document.getElementById('tmMobileBranchOpen');
    const mobileBranchLink = document.getElementById('tmMobileBranchLink');
    if(mobileBranchOpen) mobileBranchOpen.addEventListener('click', showBranchSelector);
    if(mobileBranchLink) mobileBranchLink.addEventListener('click', showBranchSelector);


    /* =========================================================
       SUBSCRIBE / UNSUBSCRIBE
       Sends the action back to this same PHP header so the
       subscriber is inserted into the pharmacy's customers table.
    ========================================================= */
    function showSubscriptionToast(message, success){
        let toast = document.getElementById('subscriptionToast');

        if(!toast){
            toast = document.createElement('div');
            toast.id = 'subscriptionToast';
            toast.style.cssText = [
                'position:fixed',
                'top:78px',
                'right:20px',
                'z-index:99999',
                'max-width:360px',
                'padding:13px 16px',
                'border-radius:12px',
                'background:#fff',
                'border:1px solid #e3e8ec',
                'box-shadow:0 12px 35px rgba(0,0,0,.16)',
                'font:600 13px Arial,Helvetica,sans-serif',
                'transition:opacity .2s ease,transform .2s ease',
                'opacity:0',
                'transform:translateY(-8px)'
            ].join(';');
            document.body.appendChild(toast);
        }

        toast.style.borderLeft = '4px solid ' + (success ? '#00a878' : '#dc3545');
        toast.style.color = '#263746';
        toast.textContent = message;

        requestAnimationFrame(function(){
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        clearTimeout(window.__subscriptionToastTimer);
        window.__subscriptionToastTimer = setTimeout(function(){
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
        }, 3500);
    }

    /* =========================================================
       SUBSCRIBE â€” isolated delegated handler.
       This is deliberately independent of the mega-menu code so a
       menu error cannot prevent subscription from working.
       ========================================================= */
    document.addEventListener('submit', function(e){
        const form = e.target.closest('.subscription-form');
        if(!form) return;

        const btn = form.querySelector('.toggle-subscribe');
        if(!btn || btn.dataset.busy === '1') return;

        e.preventDefault();

        const currentlySubscribed = btn.getAttribute('data-status') === 'subscribed';
        if(currentlySubscribed && !window.confirm('Unsubscribe from this pharmacy?')) return;

        const originalText = btn.textContent.trim();
        btn.dataset.busy = '1';
        btn.disabled = true;
        btn.textContent = currentlySubscribed ? 'Unsubscribing...' : 'Subscribing...';

        const body = new URLSearchParams(new FormData(form));

        fetch(form.action || window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body.toString()
        })
        .then(async function(response){
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); }
            catch(err){ throw new Error('Server did not return a valid subscription response.'); }
            if(!response.ok || !data.success){
                throw new Error(data.message || 'Subscription update failed.');
            }
            return data;
        })
        .then(function(data){
            const subscribed = data.subscribed === true;
            btn.dataset.status = subscribed ? 'subscribed' : 'unsubscribed';
            btn.textContent = subscribed ? 'âœ“ Subscribed' : 'Subscribe';
            showSubscriptionToast(data.message || (subscribed ? 'Subscribed.' : 'Unsubscribed.'), true);
        })
        .catch(function(err){
            btn.textContent = originalText;
            showSubscriptionToast(err.message || 'Unable to update subscription.', false);
        })
        .finally(function(){
            btn.dataset.busy = '0';
            btn.disabled = false;
        });
    });

    document.addEventListener('click', function(e){
        if(!e.target.closest('.mega-item')){
            document.querySelectorAll('.mega-item.menu-open').forEach(function(item){
                item.classList.remove('menu-open');
            });
        }
    });
})();
</script>
