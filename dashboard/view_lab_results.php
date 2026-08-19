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

$success = $_GET['msg'] ?? '';
$error = '';

// Filter Parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter   = $_GET['date_range'] ?? 'all';

// --- 1. HANDLE UPDATE STATUS TO READY ---
if (isset($_GET['approve_id'])) {
    $aid = (int)$_GET['approve_id'];
    $stmt = $conn->prepare("UPDATE lab_results SET status = 'Ready' WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
    $stmt->bind_param("iii", $aid, $p_id, $b_id);
    if ($stmt->execute()) {
        header("Location: view_lab_results.php?msg=Lab result marked as Ready successfully.");
        exit();
    } else {
        $error = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
}

// --- 2. HANDLE SINGLE DELETE ---
if (isset($_POST['delete_single'])) {
    $lab_id = (int)$_POST['lab_id'];
    
    // Fetch file path to remove file from server storage
    $file_stmt = $conn->prepare("SELECT file_path FROM lab_results WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
    $file_stmt->bind_param("iii", $lab_id, $p_id, $b_id);
    $file_stmt->execute();
    $file_res = $file_stmt->get_result()->fetch_assoc();
    $file_stmt->close();

    $del_stmt = $conn->prepare("DELETE FROM lab_results WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
    $del_stmt->bind_param("iii", $lab_id, $p_id, $b_id);
    if ($del_stmt->execute() && $del_stmt->affected_rows > 0) {
        if (!empty($file_res['file_path'])) {
            $target_file = "../api/uploads/lab_results/" . $file_res['file_path'];
            if (file_exists($target_file)) {
                @unlink($target_file);
            }
        }
        $success = "Lab result deleted successfully.";
    } else {
        $error = "Could not delete record or item not found.";
    }
    $del_stmt->close();
}

// --- 3. HANDLE BULK DELETE / PURGE ---
if (isset($_POST['delete_all'])) {
    $bulk_sql = "DELETE FROM lab_results WHERE pharmacy_id = ? AND branch_id = ?";
    $params = [$p_id, $b_id];
    $types = "ii";

    if ($status_filter === 'pending') {
        $bulk_sql .= " AND LOWER(status) = 'pending'";
    } elseif ($status_filter === 'ready') {
        $bulk_sql .= " AND LOWER(status) = 'ready'";
    }

    if ($date_filter === 'today') {
        $bulk_sql .= " AND DATE(uploaded_at) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $bulk_sql .= " AND YEARWEEK(uploaded_at, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($date_filter === 'month') {
        $bulk_sql .= " AND MONTH(uploaded_at) = MONTH(CURDATE()) AND YEAR(uploaded_at) = YEAR(CURDATE())";
    }

    $bulk_stmt = $conn->prepare($bulk_sql);
    $bulk_stmt->bind_param($types, ...$params);
    if ($bulk_stmt->execute()) {
        $deleted_count = $bulk_stmt->affected_rows;
        $success = "Successfully deleted $deleted_count lab result record(s).";
    } else {
        $error = "Error bulk deleting items: " . $bulk_stmt->error;
    }
    $bulk_stmt->close();
}

// --- 4. FETCH LAB RESULTS WITH FILTERS ---
$query = "
    SELECT lab.*, c.full_name, c.phone 
    FROM lab_results lab 
    LEFT JOIN clients c ON lab.client_id = c.id 
    WHERE lab.pharmacy_id = ? AND lab.branch_id = ?
";

$fetch_params = [$p_id, $b_id];
$fetch_types  = "ii";

if ($status_filter === 'pending') {
    $query .= " AND LOWER(lab.status) = 'pending'";
} elseif ($status_filter === 'ready') {
    $query .= " AND LOWER(lab.status) = 'ready'";
}

if ($date_filter === 'today') {
    $query .= " AND DATE(lab.uploaded_at) = CURDATE()";
} elseif ($date_filter === 'week') {
    $query .= " AND YEARWEEK(lab.uploaded_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($date_filter === 'month') {
    $query .= " AND MONTH(lab.uploaded_at) = MONTH(CURDATE()) AND YEAR(lab.uploaded_at) = YEAR(CURDATE())";
}

$query .= " ORDER BY lab.uploaded_at DESC";

$lab_stmt = $conn->prepare($query);
$lab_stmt->bind_param($fetch_types, ...$fetch_params);
$lab_stmt->execute();
$lab_res = $lab_stmt->get_result();

$total_requests = $lab_res ? $lab_res->num_rows : 0;

// Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

require_once "../includes/head.php";
?>

<style>
.lab-wrapper {
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
    border-left: 5px solid #0d6efd;
    margin-bottom: 1.25rem;
}

.filter-card {
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1.25rem;
}

.lab-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.lab-table {
    width: 100%;
    border-collapse: collapse;
}

.lab-table thead th {
    background: #0d6efd;
    padding: 12px 14px;
    text-align: left;
    font-size: 12px;
    color: #ffffff !important;
    text-transform: uppercase;
    border-bottom: 2px solid #0b5ed7;
    white-space: nowrap;
}

.lab-table tbody td {
    padding: 12px 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.lab-table tbody tr:hover {
    background: #f8f9fa;
}

.form-control-search {
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 6px 12px;
}

.form-control-search:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
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

    <div class="page-wrapper lab-wrapper">
        <div class="container-fluid p-0">
            
            <!-- Header Section -->
            <div class="header-section d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Incoming Lab Results</h3>
                    <span class="text-primary small">
                        <i class="fas fa-map-marker-alt me-1"></i> Branch: <b><?php echo e($display_bran); ?></b> | <b><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary px-3 py-2 rounded-pill fs-6 fw-normal">
                        Total Requests: <b><?php echo $total_requests; ?></b>
                    </span>
                    
                    <!-- Purge/Delete All -->
                    <?php if ($total_requests > 0): ?>
                        <form method="post" onsubmit="return confirm('Are you sure you want to delete ALL matching lab result records? This action cannot be undone.');" class="d-inline">
                            <button type="submit" name="delete_all" class="btn btn-outline-danger btn-sm shadow-sm">
                                <i class="fas fa-trash-alt me-1"></i> Purge All Filtered
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($success)): ?> 
                <div class="alert alert-success border-0 shadow-sm mb-3"><?php echo htmlspecialchars($success); ?></div> 
            <?php endif; ?>
            <?php if (!empty($error)): ?> 
                <div class="alert alert-danger border-0 shadow-sm mb-3"><?php echo htmlspecialchars($error); ?></div> 
            <?php endif; ?>

            <!-- Filters Section -->
            <div class="filter-card shadow-sm">
                <form method="get" id="filterForm" class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <input type="text" id="labSearch" class="form-control form-control-search" placeholder="Search patient name, test type, phone...">
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
                        <a href="view_lab_results.php" class="btn btn-outline-secondary btn-sm w-100">
                            <i class="fas fa-undo me-1"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Table Container -->
            <div class="lab-table-container shadow-sm">
                <div class="table-responsive">
                    <table class="lab-table" id="labTable">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60">File</th>
                                <th>Test Type & Patient</th>
                                <th>Phone</th>
                                <th>Received Date</th>
                                <th>Patient Notes</th>
                                <th>Status</th>
                                <th class="text-center" width="240">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="labTableBody">
                            <?php if ($lab_res && $total_requests > 0): ?>
                                <?php while ($row = $lab_res->fetch_assoc()): ?>
                                    <?php 
                                        $file = $row['file_path'] ?? '';
                                        $filePath = "../api/uploads/lab_results/" . e($file);
                                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                                        $name = !empty($row['full_name']) ? $row['full_name'] : 'Guest/Walk-in';
                                        $phone = $row['phone'] ?? '';
                                        $testType = !empty($row['test_type']) ? $row['test_type'] : 'General Test';
                                        $status = $row['status'] ?? 'Pending';
                                        $date = isset($row['uploaded_at']) ? date('d M Y, H:i', strtotime($row['uploaded_at'])) : 'N/A';
                                        $isPending = (strtolower($status) === 'pending');
                                        
                                        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                                        $msg = "Hello " . $name . ", your lab results (" . $testType . ") from " . $display_pharm . " (" . $display_bran . ") are ready.";
                                        $wa_link = "https://wa.me/" . $clean_phone . "?text=" . urlencode($msg);
                                    ?>
                                    <tr class="lab-row">
                                        <td class="ps-3">
                                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                                <img src="<?php echo $filePath; ?>" 
                                                     class="preview-thumb" 
                                                     onerror="this.src='dist/img/no-image.png';" 
                                                     onclick="openFileModal('<?php echo $filePath; ?>', '<?php echo e($name); ?>', 'image')" 
                                                     title="Click to view image">
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="openFileModal('<?php echo $filePath; ?>', '<?php echo e($name); ?>', 'pdf')" title="Click to view PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-primary text-uppercase" style="font-size: 0.85rem;"><?php echo e($testType); ?></div>
                                            <div class="text-dark patient-name small"><i class="far fa-user me-1"></i><?php echo e($name); ?></div>
                                        </td>
                                        <td>
                                            <span class="text-muted fw-bold small patient-phone"><?php echo e($phone ?: 'N/A'); ?></span>
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
                                                <!-- Preview Button -->
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-secondary" 
                                                        onclick="openFileModal('<?php echo $filePath; ?>', '<?php echo e($name); ?>', '<?php echo in_array($ext, ['jpg','jpeg','png','webp']) ? 'image' : 'pdf'; ?>')" 
                                                        title="View File">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <!-- Status Action -->
                                                <?php if($isPending): ?>
                                                    <a href="view_lab_results.php?approve_id=<?php echo $row['id']; ?>" 
                                                       class="btn btn-sm btn-success fw-bold" 
                                                       title="Mark Ready">
                                                        <i class="fas fa-check"></i> Ready
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light text-success fw-bold" disabled title="Already Ready">
                                                        <i class="fas fa-check-double"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <!-- WhatsApp Notification -->
                                                <?php if(!empty($clean_phone)): ?>
                                                    <a href="<?php echo $wa_link; ?>" 
                                                       target="_blank" 
                                                       class="btn btn-sm btn-whatsapp" 
                                                       title="WhatsApp Notification">
                                                        <i class="fab fa-whatsapp"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <!-- Single Delete -->
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this lab result record permanently?');">
                                                    <input type="hidden" name="lab_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="delete_single" class="btn btn-sm btn-outline-danger" title="Delete Lab Result">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-flask display-6 opacity-25 d-block mb-2"></i>
                                        No lab results found matching your selection.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
        </div>
    </div>
</div>

<!-- File Modal (Image/PDF) -->
<div class="modal fade" id="fileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="fileModalTitle">Lab Result Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-2" style="background-color: #f8f9fa;">
                <div id="imageViewer">
                    <img id="modalImagePreview" src="" class="img-fluid rounded shadow-sm" style="max-height: 75vh; object-fit: contain;">
                </div>
                <div id="pdfViewer" style="display: none;">
                    <iframe id="modalPdfPreview" src="" style="width: 100%; height: 75vh; border: none;"></iframe>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <a id="downloadFileBtn" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i> Open Original File
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
        $("#labSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#labTableBody tr.lab-row").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });
    });

    // File Modal Preview Handler
    function openFileModal(filePath, patientName, type) {
        document.getElementById('fileModalTitle').innerText = 'Lab Result: ' + patientName;
        document.getElementById('downloadFileBtn').href = filePath;

        if (type === 'image') {
            document.getElementById('imageViewer').style.display = 'block';
            document.getElementById('pdfViewer').style.display = 'none';
            document.getElementById('modalImagePreview').src = filePath;
        } else {
            document.getElementById('imageViewer').style.display = 'none';
            document.getElementById('pdfViewer').style.display = 'block';
            document.getElementById('modalPdfPreview').src = filePath;
        }
        
        var modal = new bootstrap.Modal(document.getElementById('fileModal'));
        modal.show();
    }
</script>
</body>
</html>
