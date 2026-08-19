<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$p_id = (int)$_SESSION['pharmacy_id'];
$b_id = (int)$_SESSION['branch_id'];

// 1. Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// 2. Filter Parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter   = $_GET['date_range'] ?? 'all';

$query = "
    SELECT p.*, c.full_name, c.phone 
    FROM prescriptions p 
    INNER JOIN clients c ON p.client_id = c.id 
    WHERE p.pharmacy_id = ? AND p.branch_id = ?
";

$params = [$p_id, $b_id];
$types  = "ii";

// Apply Status Filter
if ($status_filter === 'pending') {
    $query .= " AND LOWER(p.status) = 'pending'";
} elseif ($status_filter === 'ready') {
    $query .= " AND LOWER(p.status) = 'ready'";
}

// Apply Date Range Filter
if ($date_filter === 'today') {
    $query .= " AND DATE(p.uploaded_at) = CURDATE()";
} elseif ($date_filter === 'week') {
    $query .= " AND YEARWEEK(p.uploaded_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($date_filter === 'month') {
    $query .= " AND MONTH(p.uploaded_at) = MONTH(CURDATE()) AND YEAR(p.uploaded_at) = YEAR(CURDATE())";
}

$query .= " ORDER BY p.uploaded_at DESC";

$rx_stmt = $conn->prepare($query);
$rx_stmt->bind_param($types, ...$params);
$rx_stmt->execute();
$rx_res = $rx_stmt->get_result();

$total_requests = $rx_res ? $rx_res->num_rows : 0;

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

require_once "../includes/head.php";
?>

<style>
.rx-wrapper {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.header-section {
    background: #ffffff;
    padding: 1.25rem 1.5rem;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    border-left: 5px solid #198754;
    margin-bottom: 1.25rem;
}

.filter-card {
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1.25rem;
}

.rx-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.rx-table {
    width: 100%;
    border-collapse: collapse;
}

.rx-table thead th {
    background: #198754;
    padding: 12px 14px;
    text-align: left;
    font-size: 12px;
    color: #ffffff !important;
    text-transform: uppercase;
    border-bottom: 2px solid #146c43;
    white-space: nowrap;
}

.rx-table tbody td {
    padding: 12px 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.rx-table tbody tr:hover {
    background: #f8f9fa;
}

.form-control-search {
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 6px 12px;
}

.form-control-search:focus {
    border-color: #198754;
    box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
}

.btn-whatsapp {
    background-color: #25D366;
    color: #ffffff;
    border: none;
    font-weight: 600;
}

.btn-whatsapp:hover {
    background-color: #128C7E;
    color: #ffffff;
}

.preview-thumb {
    width: 42px;
    height: 42px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: transform 0.15s ease;
}

.preview-thumb:hover {
    transform: scale(1.1);
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper rx-wrapper">
        <div class="container-fluid p-0">
            
            <!-- Page Header -->
            <div class="header-section d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Prescription Inbox</h3>
                    <span class="text-success small">
                        <i class="fas fa-map-marker-alt me-1"></i> Branch: <b><?php echo e($display_bran); ?></b> | <b><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
                <div>
                    <span class="badge bg-success px-3 py-2 rounded-pill fs-6 fw-normal">
                        Total Requests: <b><?php echo $total_requests; ?></b>
                    </span>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filter-card shadow-sm">
                <form method="get" id="filterForm" class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <input type="text" id="rxSearch" class="form-control form-control-search" placeholder="Search patient name, phone...">
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="status" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit();">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>-- All Statuses --</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                            <option value="ready" <?php echo $status_filter === 'ready' ? 'selected' : ''; ?>>Ready Only</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="date_range" class="form-select form-select-sm" onchange="document.getElementById('filterForm').submit();">
                            <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>-- All Dates --</option>
                            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>This Month</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-end">
                        <a href="view_prescriptions.php" class="btn btn-outline-secondary btn-sm w-100">
                            <i class="fas fa-undo me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Table Container -->
            <div class="rx-table-container shadow-sm">
                <div class="table-responsive">
                    <table class="rx-table" id="rxTable">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60">Image</th>
                                <th>Patient Name</th>
                                <th>Phone</th>
                                <th>Uploaded Date</th>
                                <th>Notes / Instructions</th>
                                <th>Status</th>
                                <th class="text-center" width="220">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="rxTableBody">
                            <?php if ($rx_res && $total_requests > 0): ?>
                                <?php while ($row = $rx_res->fetch_assoc()): ?>
                                    <?php 
                                        $file = $row['file_path'] ?? '';
                                        $filePath = "../api/uploads/prescriptions/" . e($file);
                                        $name = $row['full_name'] ?? 'Unknown Patient';
                                        $phone = $row['phone'] ?? '';
                                        $status = $row['status'] ?? 'Pending';
                                        $date = isset($row['uploaded_at']) ? date('d M Y, H:i', strtotime($row['uploaded_at'])) : 'N/A';
                                        $isPending = (strtolower($status) === 'pending');
                                        
                                        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                                        $msg = "Hello " . $name . ", this is " . $display_pharm . " (" . $display_bran . "). Your prescription request is processed and ready.";
                                        $wa_link = "https://wa.me/" . $clean_phone . "?text=" . urlencode($msg);
                                    ?>
                                    <tr class="rx-row">
                                        <td class="ps-3">
                                            <img src="<?php echo $filePath; ?>" 
                                                 class="preview-thumb" 
                                                 onerror="this.src='dist/img/no-image.png';" 
                                                 onclick="openImageModal('<?php echo $filePath; ?>', '<?php echo e($name); ?>')" 
                                                 title="Click to expand">
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark patient-name"><?php echo e($name); ?></div>
                                        </td>
                                        <td>
                                            <span class="text-primary fw-bold small patient-phone"><?php echo e($phone); ?></span>
                                        </td>
                                        <td class="text-muted small" style="white-space: nowrap;">
                                            <?php echo $date; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo !empty($row['notes']) ? e($row['notes']) : '<i>No notes</i>'; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $isPending ? 'bg-warning text-dark' : 'bg-success text-white'; ?>">
                                                <?php echo e($status); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-secondary" 
                                                        onclick="openImageModal('<?php echo $filePath; ?>', '<?php echo e($name); ?>')" 
                                                        title="View Full Image">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <?php if($isPending): ?>
                                                    <a href="update_rx_status.php?id=<?php echo $row['id']; ?>&status=Ready" 
                                                       class="btn btn-sm btn-success fw-bold" 
                                                       title="Mark Ready">
                                                        <i class="fas fa-check"></i> Ready
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light text-success fw-bold" disabled>
                                                        <i class="fas fa-check-double"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if(!empty($clean_phone)): ?>
                                                    <a href="<?php echo $wa_link; ?>" 
                                                       target="_blank" 
                                                       class="btn btn-sm btn-whatsapp" 
                                                       title="Send WhatsApp Notification">
                                                        <i class="fab fa-whatsapp"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-prescription-bottle-alt display-6 opacity-25 d-block mb-2"></i>
                                        No prescriptions match the selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="imageModalTitle">Prescription Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-2" style="background-color: #f8f9fa;">
                <img id="modalImagePreview" src="" class="img-fluid rounded shadow-sm" style="max-height: 80vh; object-fit: contain;">
            </div>
            <div class="modal-footer justify-content-between">
                <a id="downloadImgBtn" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i> Open Original Image
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Search Filter
    $(document).ready(function(){
        $("#rxSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#rxTableBody tr.rx-row").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });
    });

    // Modal Image Preview Launcher
    function openImageModal(imgSrc, patientName) {
        document.getElementById('imageModalTitle').innerText = 'Prescription: ' + patientName;
        document.getElementById('modalImagePreview').src = imgSrc;
        document.getElementById('downloadImgBtn').href = imgSrc;
        
        var modal = new bootstrap.Modal(document.getElementById('imageModal'));
        modal.show();
    }
</script>
</body>
</html>
