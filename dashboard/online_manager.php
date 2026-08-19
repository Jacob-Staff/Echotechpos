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
// 🔒 SAFE INPUT HANDLING
// ==============================
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');

// ==============================
// 📦 FETCH CATEGORIES
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
// 🔔 NOTIFICATIONS
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
// 🔍 BUILD FILTER QUERY (FIXED STRICT MODE)
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
// 📦 FETCH INVENTORY
// ==============================
$sql = "SELECT id, item_name, quantity, price, online_price, is_online, expiry_date, image, category 
        FROM store_items 
        $where
        ORDER BY item_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// ==============================
// 🛠 HELPERS
// ==============================
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v){ return "K".number_format((float)$v,2); }

require_once "../includes/head.php";
?>

<style>
/* Layout fix for fixed header spacing */
#main-wrapper {
    padding-top: 75px !important; /* Pushes page content below top header bar */
}

.page-wrapper-full {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 75px);
    padding: 1.5rem;
    margin-left: 0 !important; /* Header only - no sidebar */
}

.header-section { 
    background: #ffffff;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid #dee2e6;
}

.bulk-action-bar { 
    background: #212529;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 10px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center; 
}

.prod-img { 
    width: 55px; 
    height: 55px; 
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
    padding: 12px; 
}

.table tbody td {
    vertical-align: middle;
    padding: 12px;
}
</style>

<div id="main-wrapper">

    <?php 
    // Included Top Header ONLY (No aside.php / Sidebar)
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    ?>

    <div class="page-wrapper-full">
        <div class="container-fluid p-0">

            <div class="header-section shadow-sm">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                    <div class="mt-2">
                        <h2 class="fw-bold text-dark mb-0">ONLINE INVENTORY MANAGER</h2>
                        <span class="badge bg-light text-dark border mt-1">
                            📍 <?php echo e($_SESSION['branch_name'] ?? 'Main Branch'); ?>
                        </span>
                    </div>

                    <div class="d-flex gap-2 flex-wrap align-items-center pt-2">
                        <a href="view_prescriptions.php" class="btn btn-warning position-relative btn-sm px-3 fw-bold">
                            Prescriptions
                            <?php if($pending_rx): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $pending_rx; ?></span>
                            <?php endif; ?>
                        </a>

                        <a href="view_lab_results.php" class="btn btn-primary position-relative btn-sm px-3 fw-bold">
                            Lab Results
                            <?php if($pending_labs): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $pending_labs; ?></span>
                            <?php endif; ?>
                        </a>

                        <button type="button" class="btn btn-info position-relative text-white btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#helpModal">
                            <i class="fas fa-question-circle me-1"></i> Help
                            <?php if($pending_help): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                                    <?php echo $pending_help; ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <a href="dashboard.php" class="btn btn-dark btn-sm px-3 fw-bold">Dashboard</a>
                    </div>
                </div>

                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control" placeholder="Search item or barcode..." value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php while($c = $cat_res->fetch_assoc()): ?>
                                <option value="<?php echo e($c['category']); ?>" <?php if($category == $c['category']) echo 'selected'; ?>>
                                    <?php echo e($c['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                    </div>
                </form>
            </div>

            <form method="POST" action="process_bulk_online.php" id="bulkForm">
                <div class="bulk-action-bar shadow-sm">
                    <label class="mb-0 cursor-pointer fw-bold"><input type="checkbox" id="selectAll" class="me-2"> Select All Items</label>
                    <div>
                        <button name="action" value="online" class="btn btn-sm btn-success me-2 fw-bold">Bulk Online</button>
                        <button name="action" value="offline" class="btn btn-sm btn-danger fw-bold">Bulk Offline</button>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th width="40"></th>
                                    <th style="width: 80px;" class="text-center">Image</th>
                                    <th>Product Details</th>
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
                                <tr>
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
                                <tr><td colspan="9" class="text-center py-5 text-muted">No inventory found matching your criteria.</td></tr>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-light border-0">
                <h5 class="fw-bold mb-0"><i class="fas fa-envelope-open-text me-2"></i> Client Inquiries</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="helpContent" style="max-height: 70vh; overflow-y: auto; padding: 20px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Clinical Modal -->
<div class="modal fade" id="clinicalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="fw-bold mb-0 text-white"><i class="fas fa-pills me-2"></i> Clinical Info: <span id="clinicalProdName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="clinicalForm">
                <input type="hidden" name="product_id" id="clin_prod_id">
                <div class="modal-body">
                    <div id="clin_loading" class="text-center p-4 d-none">
                        <div class="spinner-border text-primary"></div>
                    </div>
                    <div id="clin_fields">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold">About / Description</label>
                                <textarea name="about_text" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Medical Uses</label>
                                <textarea name="uses" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Directions for Use</label>
                                <textarea name="directions" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Side Effects</label>
                                <textarea name="side_effects" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">How it Works</label>
                                <textarea name="how_it_works" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Storage Info</label>
                                <input type="text" name="storage_info" class="form-control" placeholder="e.g., Store below 30°C">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Image Modal -->
<div class="modal fade" id="uploadImageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
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
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    // 1. SELECT ALL LOGIC
    $('#selectAll').on('click', function(){
        $('.item-checkbox').prop('checked', this.checked);
    });

    // 2. HELP MODAL HANDLING
    $('#helpModal').on('shown.bs.modal', function () {
        loadHelpMessages();
    });

    // 3. CLINICAL FORM SUBMISSION
    $(document).on('submit', '#clinicalForm', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'actions/save_clinical_info.php',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(res) {
                if(res.success) {
                    alert('Clinical information updated successfully!');
                    $('#clinicalModal').modal('hide');
                } else {
                    alert('Error: ' + res.message);
                }
            },
            error: function(xhr) {
                console.error("Submission Error:", xhr.responseText);
                alert('Server error occurred while saving.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Changes');
            }
        });
    });
});

// 4. LOAD HELP DATA
function loadHelpMessages() {
    $('#helpContent').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Fetching inquiries...</p></div>');
    $.ajax({
        url: 'actions/fetch_help_messages.php',
        method: 'GET',
        cache: false,
        success: function(data) {
            $('#helpContent').html(data);
        },
        error: function() {
            $('#helpContent').html('<div class="alert alert-danger">Error loading inquiries.</div>');
        }
    });
}

// 5. OPEN CLINICAL MODAL & FETCH DATA
function openClinicalModal(id, name) {
    $('#clinicalProdName').text(name);
    $('#clin_prod_id').val(id);
    
    $('#clinicalForm')[0].reset();
    $('#clin_fields').addClass('d-none');
    $('#clin_loading').removeClass('d-none');
    
    $('#clinicalModal').modal('show');

    $.getJSON('actions/get_clinical_info.php', { product_id: id }, function(data) {
        if(data.success && data.info) {
            const info = data.info;
            $('[name="about_text"]').val(info.about_text);
            $('[name="uses"]').val(info.uses);
            $('[name="directions"]').val(info.directions);
            $('[name="side_effects"]').val(info.side_effects);
            $('[name="how_it_works"]').val(info.how_it_works);
            $('[name="storage_info"]').val(info.storage_info || 'Store below 30°C');
        }
    }).always(function() {
        $('#clin_loading').addClass('d-none');
        $('#clin_fields').removeClass('d-none');
    });
}

// 6. OTHER TOOLS
function toggleOffer(id, name, price, isOffer){
    if(isOffer){
        if(confirm("Remove offer from " + name + "?")){
            location.href = "process_offer.php?id=" + id + "&action=remove";
        }
    } else {
        let val = prompt("Enter new offer price for " + name);
        if(val && !isNaN(val) && parseFloat(val) < price){
            location.href = "process_offer.php?id=" + id + "&action=set&price=" + val;
        } else if (val) {
            alert("Invalid price. Offer must be lower than the original price.");
        }
    }
}

function openImageUpload(id){
    $('#img_product_id').val(id);
    $('#uploadImageModal').modal('show');
}
</script>
</body>
</html>
