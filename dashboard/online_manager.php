<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. DYNAMIC BASE URL FIX
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$base_path = "/pharmacy_v1-master/dashboard/"; 
$base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $base_path;

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$today       = date('Y-m-d');

// ==============================
// FETCH BRANCH NAME FROM DB
// ==============================
$branch_name = "Unknown Branch";
$branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ?");
if ($branch_stmt) {
    $branch_stmt->bind_param("ii", $branch_id, $pharmacy_id);
    $branch_stmt->execute();
    $b_res = $branch_stmt->get_result();
    if ($b_row = $b_res->fetch_assoc()) {
        $branch_name = $b_row['branch_name'];
        $_SESSION['branch_name'] = $branch_name; // Sync back to session
    }
}

// ==============================
// SAFE INPUT HANDLING
// ==============================
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

$is_ajax_inventory = isset($_GET['ajax_inventory']) && $_GET['ajax_inventory'] === '1';

// ==============================
// FETCH CATEGORIES
// ==============================
$cat_stmt = $conn->prepare("
    SELECT DISTINCT category 
    FROM store_items 
    WHERE pharmacy_id = ? AND category != '' 
    ORDER BY category ASC
");
$cat_stmt->bind_param("i", $pharmacy_id);
$cat_stmt->execute();
$cat_res = $cat_stmt->get_result();

// ==============================
//  NOTIFICATIONS
// ==============================
function getCount($conn, $table, $pharmacy_id, $branch_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM $table 
        WHERE pharmacy_id = ? AND branch_id = ? AND status = 'Pending'
    ");
    if (!$stmt) return 0;
    $stmt->bind_param("ii", $pharmacy_id, $branch_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

$pending_rx   = getCount($conn, "prescriptions", $pharmacy_id, $branch_id);
$pending_labs = getCount($conn, "lab_results", $pharmacy_id, $branch_id);
$pending_help = getCount($conn, "help_inquiries", $pharmacy_id, $branch_id);

// ==============================
// BUILD FILTER QUERY
// ==============================
$where = "WHERE pharmacy_id = ? AND branch_id = ? 
          AND quantity > 0 
          AND (expiry_date > ? OR expiry_date IS NULL OR CAST(expiry_date AS CHAR) = '0000-00-00')";

$params = [$pharmacy_id, $branch_id, $today];
$types  = "iis";

if (!empty($search)) {
    $where .= " AND (item_name LIKE ? OR barcode LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if (!empty($category)) {
    $where .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

// ==============================
// FETCH INVENTORY
// ==============================
$sql = "SELECT id, item_name, quantity, price, online_price, is_online, expiry_date, image, category, online_group 
        FROM store_items 
        $where
        ORDER BY item_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// =========================================================
// ONLINE STORE CLASSIFICATION
// Shared category/group configuration used by the store header
// and Online Manager.
// =========================================================
$store_category_config = __DIR__ . '/../includes/store_categories.php';

if (!file_exists($store_category_config)) {
    die(
        'Shared store category configuration not found: ' .
        htmlspecialchars($store_category_config, ENT_QUOTES, 'UTF-8')
    );
}

require_once $store_category_config;


// ==============================
// HELPERS
// ==============================
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v){ return "K".number_format((float)$v,2); }

require_once "../includes/head.php";
?>

<style>
/* Header & Layout spacing fix */
#main-wrapper {
    padding-top: 75px !important;
}

.page-wrapper-full {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 75px);
    padding: 1rem;
    margin-left: 0 !important;
}

.header-section { 
    background: #ffffff;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 15px;
    border: 1px solid #dee2e6;
}

.bulk-action-bar { 
    background: #212529;
    color: #ffffff;
    padding: 12px 15px;
    border-radius: 10px;
    margin-bottom: 15px;
}

.prod-img { 
    width: 50px; 
    height: 50px; 
    object-fit: cover; 
    border-radius: 8px; 
    border: 1px solid #dee2e6; 
    background: #f9f9f9; 
    display: block; 
    margin: 0 auto; 
}

.badge-qty { 
    padding: 5px 10px; 
    border-radius: 6px; 
    font-weight: 700; 
    font-size: 12px; 
}

.qty-low { background: #fff3cd; color: #856404; }
.qty-ok { background: #d1e7dd; color: #0f5132; }

.table thead th { 
    background: #212529; 
    color: #ffffff !important;
    font-size: 11px; 
    text-transform: uppercase; 
    border: none; 
    padding: 10px; 
}

.table tbody td {
    vertical-align: middle;
    padding: 10px;
}


/* =========================================================
   ONLINE MANAGER - LIVE FILTERS + CUSTOM NOTIFICATIONS
========================================================= */
.inventory-filter-status {
    min-height: 20px;
}
.inventory-row.inventory-hidden {
    display: none;
}
.online-manager-toast-container {
    position: fixed;
    top: 82px;
    right: 20px;
    z-index: 4000;
    width: min(390px, calc(100vw - 40px));
}
.online-manager-toast {
    background:#fff;
    border:1px solid #dee2e6;
    border-left:4px solid #198754;
    border-radius:14px;
    box-shadow:0 18px 45px rgba(15,23,42,.16);
    padding:13px 15px;
    margin-bottom:10px;
    display:flex;
    align-items:flex-start;
    gap:11px;
    animation:omToastIn .2s ease both;
}
.online-manager-toast.error { border-left-color:#dc3545; }
.online-manager-toast.info { border-left-color:#0d6efd; }
.om-toast-icon {
    width:35px;height:35px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:#eaf7f0;color:#198754;flex:0 0 35px;
}
.online-manager-toast.error .om-toast-icon {background:#fff0f1;color:#dc3545;}
.online-manager-toast.info .om-toast-icon {background:#eef5ff;color:#0d6efd;}
.om-toast-content {flex:1;}
.om-toast-title {font-weight:700;font-size:.9rem;}
.om-toast-message {font-size:.82rem;color:#6c757d;margin-top:2px;}
.om-toast-close {border:0;background:transparent;color:#adb5bd;}
.inventory-loading {
    opacity:.62;
    pointer-events:none;
}
@keyframes omToastIn {
    from {opacity:0;transform:translateY(-8px) translateX(8px);}
    to {opacity:1;transform:translateY(0) translateX(0);}
}
@media(max-width:767.98px){
    .online-manager-toast-container {top:70px;right:12px;width:calc(100vw - 24px);}
}


/* =========================================================
   PRODUCT CLASSIFICATION
========================================================= */
.btn-classify-product{
    border:1px solid #cfe0f4;
    background:#f4f8ff;
    color:#1769d1;
    border-radius:8px;
    min-width:120px;
    transition:.18s ease;
}
.btn-classify-product:hover{background:#1769d1;color:#fff;border-color:#1769d1;transform:translateY(-1px);}
.classification-mini{font-size:10px;line-height:1.25;max-width:145px;margin-left:auto;margin-right:auto;}
.classification-mini > span:first-child{display:block;font-weight:800;color:#334155;}
.classification-group{display:block;color:#1769d1;margin-top:2px;}
.classification-modal{border:0;border-radius:18px;overflow:hidden;box-shadow:0 24px 70px rgba(15,23,42,.22);}
.classification-header{padding:20px 22px;background:#fff;border-bottom:1px solid #e8edf2;}
.classification-kicker{font-size:10px;font-weight:800;letter-spacing:1.1px;color:#1769d1;margin-bottom:4px;}
.classification-body{display:grid;grid-template-columns:1fr 1fr;min-height:430px;}
.classification-pane{padding:18px;border-right:1px solid #e9eef3;background:#fff;}
.classification-pane:last-child{border-right:0;background:#fbfcfe;}
.classification-pane-title{font-size:14px;font-weight:800;color:#172b3a;}
.classification-pane-help{font-size:11px;color:#7a8792;margin:3px 0 12px;}
.classification-check-list{display:flex;flex-direction:column;gap:7px;max-height:330px;overflow-y:auto;padding-right:4px;}
.classification-check{position:relative;display:block;}
.classification-check input{position:absolute;opacity:0;pointer-events:none;}
.classification-check label{display:flex;align-items:center;gap:10px;padding:10px 11px;border:1px solid #e2e8ee;border-radius:10px;background:#fff;cursor:pointer;font-size:12px;font-weight:650;color:#405160;transition:.15s ease;}
.classification-check label:before{content:' ';width:17px;height:17px;border:1.5px solid #b8c4cf;border-radius:5px;background:#fff;flex:0 0 17px;}
.classification-check input:checked + label{border-color:#1769d1;background:#f0f6ff;color:#1459ad;box-shadow:0 3px 10px rgba(23,105,209,.08);}
.classification-check input:checked + label:before{content:'âœ“';display:flex;align-items:center;justify-content:center;border-color:#1769d1;background:#1769d1;color:#fff;font-size:11px;font-weight:900;}
.classification-check label:hover{border-color:#b7d0ee;background:#f8fbff;}
.classification-groups-pane.disabled{opacity:.55;}
.classification-empty{padding:25px 10px;text-align:center;color:#8996a1;font-size:12px;border:1px dashed #d8e0e7;border-radius:10px;background:#fff;}
.classification-selection{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid #e9eef3;background:#f8fafc;font-size:12px;}
@media(max-width:767.98px){
    .classification-body{grid-template-columns:1fr;}
    .classification-pane{border-right:0;border-bottom:1px solid #e9eef3;}
    .classification-groups-pane{min-height:250px;}
}

@media (max-width: 767.98px) {
    .page-wrapper-full {
        padding: 0.75rem;
    }
    .header-section {
        padding: 15px;
    }
    .page-title {
        font-size: 1.35rem;
    }
    .btn-action-group {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .btn-action-group .btn {
        width: 100%;
    }
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    ?>

    <div class="page-wrapper-full">
        <div class="container-fluid p-0">

            <div class="header-section shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
                    <div>
                        <h2 class="fw-bold text-dark mb-0 page-title">ONLINE INVENTORY MANAGER</h2>
                        <span class="badge bg-light text-dark border mt-1">
                            <?php echo e($branch_name); ?>
                        </span>
                    </div>

                    <div class="btn-action-group">
                        <a href="view_prescriptions.php" class="btn btn-warning position-relative btn-sm fw-bold">
                            Prescriptions
                            <?php if($pending_rx): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $pending_rx; ?></span>
                            <?php endif; ?>
                        </a>

                        <a href="view_lab_results.php" class="btn btn-primary position-relative btn-sm fw-bold">
                            Lab Results
                            <?php if($pending_labs): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $pending_labs; ?></span>
                            <?php endif; ?>
                        </a>

                        <button type="button" class="btn btn-info position-relative text-white btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#helpModal">
                            <i class="fas fa-question-circle me-1"></i> Help
                            <?php if($pending_help): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                                    <?php echo $pending_help; ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <a href="dashboard.php" class="btn btn-dark btn-sm fw-bold">Dashboard</a>
                    </div>
                </div>

                <div class="row g-2" id="inventoryFilters">
                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="inventorySearch" name="search" class="form-control" autocomplete="off" placeholder="Search item or barcode..." value="<?php echo e($search); ?>">
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <select id="inventoryCategory" name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php while($c = $cat_res->fetch_assoc()): ?>
                                <option value="<?php echo e($c['category']); ?>" <?php if($category == $c['category']) echo 'selected'; ?>>
                                    <?php echo e($c['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <button type="button" id="clearInventoryFilters" class="btn btn-light border w-100 fw-bold">
                            <i class="fas fa-rotate-left me-1"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <form method="POST" action="process_bulk_online.php" id="bulkForm">
                <div class="inventory-filter-status small text-muted mb-2 px-1" id="inventoryFilterStatus">
                    Showing current inventory
                </div>

                <div class="bulk-action-bar shadow-sm d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <label class="mb-0 cursor-pointer fw-bold"><input type="checkbox" id="selectAll" class="me-2"> Select All Items</label>
                    <div class="w-100 w-sm-auto d-flex gap-2">
                        <button name="action" value="online" class="btn btn-sm btn-success flex-fill flex-sm-grow-0 fw-bold">Bulk Online</button>
                        <button name="action" value="offline" class="btn btn-sm btn-danger flex-fill flex-sm-grow-0 fw-bold">Bulk Offline</button>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="min-width: 780px;">
                            <thead>
                                <tr>
                                    <th width="40"></th>
                                    <th style="width: 70px;" class="text-center">Image</th>
                                    <th>Product Details</th>
                                    <th style="width: 155px;" class="text-center">Category</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th>Expiry</th>
                                    <th>Clinical Info</th> 
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($res && $res->num_rows > 0): while($row = $res->fetch_assoc()): 
                                    $is_online = $row['is_online'] == 1;
                                    $has_offer = ($row['online_price'] > 0 && $row['online_price'] < $row['price']);
                                    $img = !empty($row['image']) 
                                        ? "../uploads/products/" . $row['image'] 
                                        : "dist/img/no-image.png";
                                ?>
                                <tr class="inventory-row"
                                    data-name="<?php echo e(strtolower($row['item_name'])); ?>"
                                    data-barcode="<?php echo e(strtolower($row['barcode'] ?? '')); ?>"
                                    data-category="<?php echo e(strtolower($row['category'] ?? '')); ?>"
                                    data-group="<?php echo e(strtolower($row['online_group'] ?? '')); ?>">
                                    <td><input type="checkbox" class="item-checkbox" name="item_ids[]" value="<?php echo $row['id']; ?>"></td>
                                    
                                    <td class="text-center">
                                        <img src="<?php echo e($img); ?>" 
                                             class="prod-img" 
                                             onerror="this.onerror=null;this.src='dist/img/no-image.png';">
                                        <button type="button" 
                                                onclick="openImageUpload(<?php echo $row['id']; ?>)" 
                                                class="btn btn-sm btn-outline-secondary w-100 mt-1" 
                                                style="font-size: 10px; padding: 2px;">
                                            <i class="fas fa-image"></i> Edit
                                        </button>
                                    </td>

                                    <td>
                                        <span class="fw-bold text-dark d-block"><?php echo e($row['item_name']); ?></span>
                                        <small class="text-muted"><?php echo e($row['category']); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-sm btn-classify-product fw-bold"
                                                onclick="openClassificationModal(<?php echo (int)$row['id']; ?>, <?php echo htmlspecialchars(json_encode($row['item_name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($row['category'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($row['online_group'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fas fa-layer-group me-1"></i> Classify
                                        </button>
                                        <?php if (!empty($row['category']) || !empty($row['online_group'])): ?>
                                            <div class="classification-mini mt-1">
                                                <span><?php echo e($row['category'] ?: 'Unclassified'); ?></span>
                                                <?php if (!empty($row['online_group'])): ?><span class="classification-group"><?php echo e($row['online_group']); ?></span><?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="classification-mini mt-1 text-muted">Not classified</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-qty <?php echo ($row['quantity'] <= 5 ? 'qty-low' : 'qty-ok'); ?>"><?php echo $row['quantity']; ?></span></td>
                                    <td>
                                        <?php if($has_offer): ?>
                                            <small class="text-muted text-decoration-line-through"><?php echo money($row['price']); ?></small><br>
                                            <b class="text-success"><?php echo money($row['online_price']); ?></b>
                                        <?php else: ?>
                                            <b class="text-dark"><?php echo money($row['price']); ?></b>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo ($row['expiry_date'] == '0000-00-00' || empty($row['expiry_date'])) ? 'N/A' : date('d M Y', strtotime($row['expiry_date'])); ?></small>
                                    </td>
                                    <td>
                                        <button type="button" 
                                            class="btn btn-sm btn-outline-primary w-100"
                                            onclick="openClinicalModal(<?php echo $row['id']; ?>, '<?php echo e(addslashes($row['item_name'])); ?>')">
                                            <i class="fas fa-file-medical me-1"></i> Details
                                        </button>
                                    </td>
                                    <td>
                                        <a href="toggle_online.php?id=<?php echo $row['id']; ?>" class="btn btn-sm <?php echo $is_online ? 'btn-outline-danger' : 'btn-outline-success'; ?> w-100 fw-bold">
                                            <?php echo $is_online ? 'Take Offline' : 'Go Online'; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <button type="button" 
                                            onclick="toggleOffer(<?php echo $row['id']; ?>,'<?php echo addslashes($row['item_name']); ?>',<?php echo $row['price']; ?>,<?php echo $has_offer ? 1 : 0; ?>)" 
                                            class="btn btn-sm <?php echo $has_offer ? 'btn-danger' : 'btn-warning'; ?> w-100 fw-bold">
                                            <?php echo $has_offer ? 'Remove Offer' : 'Set Offer'; ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="10" class="text-center py-5 text-muted">No inventory found matching your criteria.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-light border-0">
                <h5 class="fw-bold mb-0"><i class="fas fa-envelope-open-text me-2"></i> Client Inquiries</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="helpContent" style="max-height: 70vh; overflow-y: auto; padding: 15px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Clinical Modal -->
<div class="modal fade" id="clinicalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="fw-bold mb-0 text-white"><i class="fas fa-pills me-2"></i> Clinical Info: <span id="clinicalProdName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="clinicalForm">
                <input type="hidden" name="product_id" id="clin_prod_id">
                <div class="modal-body p-3">
                    <div id="clin_loading" class="text-center p-4 d-none">
                        <div class="spinner-border text-primary"></div>
                    </div>
                    <div id="clin_fields">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label fw-bold">About / Description</label>
                                <textarea name="about_text" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Medical Uses</label>
                                <textarea name="uses" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Directions for Use</label>
                                <textarea name="directions" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Side Effects</label>
                                <textarea name="side_effects" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">How it Works</label>
                                <textarea name="how_it_works" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Storage Info</label>
                                <input type="text" name="storage_info" class="form-control" placeholder="e.g., Store below 30Ãƒâ€šÃ‚Â°C">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Image Modal -->
<div class="modal fade" id="uploadImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px;">
            <form action="actions/upload_product_image.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Upload Product Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="img_product_id">
                    <div class="mb-3">
                        <label class="form-label">Select Image File</label>
                        <input type="file" name="product_image" class="form-control" required accept="image/*">
                        <small class="text-muted">Allowed formats: JPG, PNG, WEBP</small>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">Upload Now</button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Product Classification Modal -->
<div class="modal fade" id="classificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content classification-modal">
            <div class="modal-header classification-header">
                <div>
                    <div class="classification-kicker"><i class="fas fa-layer-group me-1"></i> PRODUCT CLASSIFICATION</div>
                    <h5 class="modal-title fw-bold mb-0" id="classificationProductName">Classify Product</h5>
                    <small class="text-muted">Choose the online category and group where customers will find this product.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <form id="classificationForm">
                    <input type="hidden" name="product_id" id="classificationProductId">
                    <div class="classification-body">
                        <div class="classification-pane">
                            <div class="classification-pane-title">1. Category</div>
                            <div class="classification-pane-help">Select one main category.</div>
                            <div id="classificationCategories" class="classification-check-list"></div>
                        </div>
                        <div class="classification-pane classification-groups-pane">
                            <div class="classification-pane-title">2. Group</div>
                            <div class="classification-pane-help" id="classificationGroupHelp">Select a category first.</div>
                            <div id="classificationGroups" class="classification-check-list"></div>
                        </div>
                    </div>
                    <div class="classification-selection">
                        <div><span class="text-muted">Selected:</span> <strong id="classificationSelectionText">Not classified</strong></div>
                        <button type="button" class="btn btn-sm btn-light border" id="clearClassificationBtn"><i class="fas fa-eraser me-1"></i> Clear</button>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-bold" id="saveClassificationBtn"><i class="fas fa-save me-1"></i> Save Classification</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Offer Modal -->
<div class="modal fade" id="offerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header bg-warning border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-tag me-2"></i><span id="offerModalTitle">Product Offer</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="offerRemoveView" class="d-none">
                    <p class="mb-1">Remove the current offer from:</p>
                    <h6 class="fw-bold" id="offerRemoveName"></h6>
                    <p class="text-muted small mb-0">The product will return to its normal selling price.</p>
                </div>

                <div id="offerSetView" class="d-none">
                    <p class="text-muted small mb-2">Set an online offer price for:</p>
                    <h6 class="fw-bold mb-3" id="offerSetName"></h6>
                    <div class="mb-2">
                        <label class="form-label fw-bold">Original Price</label>
                        <div class="form-control bg-light" id="offerOriginalPrice"></div>
                    </div>
                    <div>
                        <label class="form-label fw-bold">Offer Price</label>
                        <div class="input-group">
                            <span class="input-group-text">K</span>
                            <input type="number" step="0.01" min="0" class="form-control" id="offerPriceInput" placeholder="Enter lower price">
                        </div>
                        <div class="form-text">Offer price must be lower than the original price.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmOfferBtn" class="btn btn-warning fw-bold">Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom notification -->
<div id="onlineManagerToastContainer" class="online-manager-toast-container" aria-live="polite"></div>

<?php 
if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {

    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function showOMToast(type, title, message, duration = 4200) {
        const icons = {success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle'};
        const id = 'om-toast-' + Date.now() + Math.floor(Math.random() * 1000);

        $('#onlineManagerToastContainer').append(`
            <div class="online-manager-toast ${type}" id="${id}">
                <div class="om-toast-icon"><i class="fas ${icons[type] || icons.info}"></i></div>
                <div class="om-toast-content">
                    <div class="om-toast-title">${esc(title)}</div>
                    <div class="om-toast-message">${esc(message)}</div>
                </div>
                <button type="button" class="om-toast-close"><i class="fas fa-times"></i></button>
            </div>
        `);

        const $toast = $('#' + id);
        $toast.find('.om-toast-close').on('click', function(){
            $toast.fadeOut(150, function(){ $(this).remove(); });
        });
        setTimeout(function(){
            $toast.fadeOut(180, function(){ $(this).remove(); });
        }, duration);
    }

    /* Select all */
    $('#selectAll').on('change', function(){
        $('.inventory-row:visible .item-checkbox').prop('checked', this.checked);
    });

    /* Keep Select All accurate when individual items change */
    $(document).on('change', '.item-checkbox', function(){
        const $visible = $('.inventory-row:visible .item-checkbox');
        const checked = $visible.length > 0 && $visible.filter(':checked').length === $visible.length;
        $('#selectAll').prop('checked', checked);
    });

    /* =====================================================
       TRUE LIVE FILTERING
       Search and category update immediately no Apply.
    ===================================================== */
    function applyLiveInventoryFilter() {
        const search = ($('#inventorySearch').val() || '').toLowerCase().trim();
        const category = ($('#inventoryCategory').val() || '').toLowerCase().trim();

        let visible = 0;
        const total = $('.inventory-row').length;

        $('.inventory-row').each(function(){
            const $row = $(this);
            const name = String($row.data('name') || '');
            const barcode = String($row.data('barcode') || '');
            const rowCategory = String($row.data('category') || '');

            const matchesSearch =
                !search ||
                name.indexOf(search) !== -1 ||
                barcode.indexOf(search) !== -1;

            const matchesCategory =
                !category || rowCategory === category;

            const matches = matchesSearch && matchesCategory;

            $row.toggleClass('inventory-hidden', !matches);
            if (matches) visible++;
        });

        $('#selectAll').prop('checked', false);

        if (!search && !category) {
            $('#inventoryFilterStatus').text('Showing all available inventory');
        } else {
            $('#inventoryFilterStatus').html(
                '<strong>' + visible + '</strong> of <strong>' + total +
                '</strong> products match the current filter'
            );
        }

        let $empty = $('#liveFilterEmpty');
        if (visible === 0 && total > 0) {
            if (!$empty.length) {
                $('table tbody').append(`
                    <tr id="liveFilterEmpty">
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i>
                            No products match the current filter.
                        </td>
                    </tr>
                `);
            }
        } else {
            $empty.remove();
        }
    }

    $('#inventorySearch').on('input', applyLiveInventoryFilter);
    $('#inventoryCategory').on('change', applyLiveInventoryFilter);

    $('#clearInventoryFilters').on('click', function(){
        $('#inventorySearch').val('');
        $('#inventoryCategory').val('');
        applyLiveInventoryFilter();
        $('#inventorySearch').trigger('focus');
    });

    /* =====================================================
       HELP
    ===================================================== */
    $('#helpModal').on('shown.bs.modal', function () {
        loadHelpMessages();
    });

    /* =====================================================
       CLINICAL INFORMATION
    ===================================================== */
    $(document).on('submit', '#clinicalForm', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const original = $btn.html();

        $btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

        $.ajax({
            url: 'actions/save_clinical_info.php',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json'
        })
        .done(function(res) {
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('clinicalModal')).hide();
                showOMToast('success', 'Clinical Information Saved', 'Product information was updated successfully.');
            } else {
                showOMToast('error', 'Unable to Save', res.message || 'The clinical information could not be saved.');
            }
        })
        .fail(function(xhr) {
            console.error('Clinical submission error:', xhr.responseText);
            showOMToast('error', 'Server Error', 'The clinical information could not be saved.');
        })
        .always(function() {
            $btn.prop('disabled', false).html(original);
        });
    });


    /* =====================================================
       PRODUCT CLASSIFICATION
       Category and Group are single-select checkbox lists.
    ===================================================== */
    const classificationTree = <?php echo json_encode($product_classification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let classificationProductId = null;
    let classificationCategory = '';
    let classificationGroup = '';

    function renderClassificationCategories(selectedCategory) {
        const $box = $('#classificationCategories').empty();
        Object.keys(classificationTree).forEach(function(category, index){
            const id = 'class-cat-' + index;
            $box.append(`
                <div class="classification-check">
                    <input type="checkbox" id="${id}" class="classification-category-checkbox" value="${esc(category)}" ${category === selectedCategory ? 'checked' : ''}>
                    <label for="${id}"><span>${esc(category)}</span></label>
                </div>
            `);
        });
        renderClassificationGroups(selectedCategory);
    }

    function renderClassificationGroups(category, selectedGroup = '') {
        const $box = $('#classificationGroups').empty();
        const groups = classificationTree[category] || [];
        $('#classificationGroupHelp').text(category ? 'Select one group under this category.' : 'Select a category first.');
        $('.classification-groups-pane').toggleClass('disabled', !category);

        if (!category) {
            $box.html('<div class="classification-empty"><i class="fas fa-arrow-left mb-2 d-block"></i>Choose a category to see its groups.</div>');
            return;
        }
        if (!groups.length) {
            $box.html('<div class="classification-empty"><i class="fas fa-folder-open mb-2 d-block"></i>This category does not use product groups.</div>');
            return;
        }
        groups.forEach(function(group, index){
            const id = 'class-group-' + index;
            $box.append(`
                <div class="classification-check">
                    <input type="checkbox" id="${id}" class="classification-group-checkbox" value="${esc(group)}" ${group === selectedGroup ? 'checked' : ''}>
                    <label for="${id}"><span>${esc(group)}</span></label>
                </div>
            `);
        });
    }

    function updateClassificationSelection(){
        classificationCategory = $('.classification-category-checkbox:checked').first().val() || '';
        classificationGroup = $('.classification-group-checkbox:checked').first().val() || '';
        let text = classificationCategory || 'Not classified';
        if (classificationGroup) text += ' â€º ' + classificationGroup;
        $('#classificationSelectionText').text(text);
    }

    window.openClassificationModal = function(id, name, category, group){
        classificationProductId = parseInt(id, 10) || 0;
        classificationCategory = category || '';
        classificationGroup = group || '';
        $('#classificationProductId').val(classificationProductId);
        $('#classificationProductName').text(name || 'Classify Product');
        renderClassificationCategories(classificationCategory);
        updateClassificationSelection();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('classificationModal')).show();
    };

    $(document).on('change', '.classification-category-checkbox', function(){
        $('.classification-category-checkbox').not(this).prop('checked', false);
        classificationCategory = $(this).is(':checked') ? $(this).val() : '';
        classificationGroup = '';
        renderClassificationGroups(classificationCategory, '');
        updateClassificationSelection();
    });

    $(document).on('change', '.classification-group-checkbox', function(){
        $('.classification-group-checkbox').not(this).prop('checked', false);
        classificationGroup = $(this).is(':checked') ? $(this).val() : '';
        updateClassificationSelection();
    });

    $('#clearClassificationBtn').on('click', function(){
        $('.classification-category-checkbox, .classification-group-checkbox').prop('checked', false);
        classificationCategory = '';
        classificationGroup = '';
        renderClassificationGroups('', '');
        updateClassificationSelection();
    });

    $('#saveClassificationBtn').on('click', function(){
        const $btn = $(this);
        const productId = parseInt($('#classificationProductId').val(), 10) || 0;
        const category = $('.classification-category-checkbox:checked').first().val() || '';
        const group = $('.classification-group-checkbox:checked').first().val() || '';

        if (!productId) {
            showOMToast('error', 'Invalid Product', 'The product could not be identified.');
            return;
        }
        if (!category) {
            showOMToast('error', 'Category Required', 'Please select a category before saving.');
            return;
        }

        const original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

        $.ajax({
            url: 'actions/save_product_classification.php',
            type: 'POST',
            dataType: 'json',
            data: { product_id: productId, category: category, online_group: group }
        }).done(function(res){
            if (res && res.success) {
                const $row = $('.inventory-row').filter(function(){
                    return String($(this).find('.item-checkbox').val()) === String(productId);
                }).first();
                $row.attr('data-category', category.toLowerCase()).data('category', category.toLowerCase());
                $row.attr('data-group', group.toLowerCase()).data('group', group.toLowerCase());
                const $cell = $row.find('.btn-classify-product').closest('td');
                $cell.find('.classification-mini').remove();
                const mini = $('<div class="classification-mini mt-1"></div>');
                $('<span></span>').text(category).appendTo(mini);
                if (group) $('<span class="classification-group"></span>').text(group).appendTo(mini);
                $cell.append(mini);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('classificationModal')).hide();
                showOMToast('success', 'Classification Saved', res.message || 'Product classification updated successfully.');
            } else {
                showOMToast('error', 'Unable to Save', (res && res.message) || 'The product classification could not be saved.');
            }
        }).fail(function(xhr){
            console.error('Classification save error:', xhr.responseText);
            showOMToast('error', 'Server Error', 'The product classification could not be saved.');
        }).always(function(){
            $btn.prop('disabled', false).html(original);
        });
    });

    /* =====================================================
       CUSTOM OFFER MODAL
    ===================================================== */
    let offerAction = null;
    let offerProductId = null;
    let offerProductName = '';
    let offerOriginalPrice = 0;

    window.toggleOffer = function(id, name, price, isOffer) {
        offerProductId = id;
        offerProductName = name;
        offerOriginalPrice = parseFloat(price) || 0;
        offerAction = isOffer ? 'remove' : 'set';

        if (offerAction === 'remove') {
            $('#offerModalTitle').text('Remove Offer');
            $('#offerRemoveName').text(name);
            $('#offerRemoveView').removeClass('d-none');
            $('#offerSetView').addClass('d-none');
            $('#confirmOfferBtn')
                .removeClass('btn-warning')
                .addClass('btn-danger')
                .html('<i class="fas fa-tag me-1"></i> Remove Offer');
        } else {
            $('#offerModalTitle').text('Set Online Offer');
            $('#offerSetName').text(name);
            $('#offerOriginalPrice').text('K' + offerOriginalPrice.toFixed(2));
            $('#offerPriceInput').val('');
            $('#offerRemoveView').addClass('d-none');
            $('#offerSetView').removeClass('d-none');
            $('#confirmOfferBtn')
                .removeClass('btn-danger')
                .addClass('btn-warning')
                .html('<i class="fas fa-check me-1"></i> Set Offer');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('offerModal')).show();

        if (offerAction === 'set') {
            setTimeout(function(){ $('#offerPriceInput').trigger('focus'); }, 250);
        }
    };

    $('#confirmOfferBtn').on('click', function(){
        const $btn = $(this);

        if (offerAction === 'remove') {
            window.location.href =
                'process_offer.php?id=' + encodeURIComponent(offerProductId) +
                '&action=remove';
            return;
        }

        const value = parseFloat($('#offerPriceInput').val());

        if (!value || isNaN(value) || value <= 0) {
            showOMToast('error', 'Invalid Offer Price', 'Please enter a valid offer price.');
            $('#offerPriceInput').trigger('focus');
            return;
        }

        if (value >= offerOriginalPrice) {
            showOMToast(
                'error',
                'Offer Price Too High',
                'The offer price must be lower than K' + offerOriginalPrice.toFixed(2) + '.'
            );
            $('#offerPriceInput').trigger('focus');
            return;
        }

        $btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

        window.location.href =
            'process_offer.php?id=' + encodeURIComponent(offerProductId) +
            '&action=set&price=' + encodeURIComponent(value.toFixed(2));
    });

    /* =====================================================
       IMAGE UPLOAD
    ===================================================== */
    window.openImageUpload = function(id){
        $('#img_product_id').val(id);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadImageModal')).show();
    };

    /* =====================================================
       CLINICAL MODAL
    ===================================================== */
    window.openClinicalModal = function(id, name) {
        $('#clinicalProdName').text(name);
        $('#clin_prod_id').val(id);

        $('#clinicalForm')[0].reset();
        $('#clin_fields').addClass('d-none');
        $('#clin_loading').removeClass('d-none');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('clinicalModal')).show();

        $.getJSON('actions/get_clinical_info.php', { product_id: id })
            .done(function(data) {
                if(data.success && data.info) {
                    const info = data.info;
                    $('[name="about_text"]').val(info.about_text || '');
                    $('[name="uses"]').val(info.uses || '');
                    $('[name="directions"]').val(info.directions || '');
                    $('[name="side_effects"]').val(info.side_effects || '');
                    $('[name="how_it_works"]').val(info.how_it_works || '');
                    $('[name="storage_info"]').val(info.storage_info || 'Store below 30Ãƒâ€šÃ‚Â°C');
                }
            })
            .fail(function(){
                showOMToast('error', 'Unable to Load', 'Clinical information could not be loaded.');
            })
            .always(function() {
                $('#clin_loading').addClass('d-none');
                $('#clin_fields').removeClass('d-none');
            });
    };

    /* Run initial live filter so URL-loaded values still work. */
    applyLiveInventoryFilter();
});

function loadHelpMessages() {
    $('#helpContent').html(
        '<div class="text-center p-4"><div class="spinner-border text-primary"></div>' +
        '<p class="mt-2 text-muted">Fetching inquiries...</p></div>'
    );

    $.ajax({
        url: 'actions/fetch_help_messages.php',
        method: 'GET',
        cache: false
    })
    .done(function(data) {
        $('#helpContent').html(data);
    })
    .fail(function() {
        $('#helpContent').html('<div class="alert alert-danger">Error loading inquiries.</div>');
    });
}
</script>
</script>
</body>
</html>
