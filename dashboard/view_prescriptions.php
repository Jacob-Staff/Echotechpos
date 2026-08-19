<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. INCLUDES FIRST
require_once "../includes/conn.php";[cite: 6]
require_once "../includes/auth.php";[cite: 6]
require_once "../includes/header.php";

date_default_timezone_set('Africa/Lusaka');[cite: 6]

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

// 2. FETCH BRANCH AND PHARMACY NAME FROM DB
$branch_name = "Unknown Branch";
$pharmacy_name = "Pharmacy";

$info_stmt = $conn->prepare("
    SELECT b.branch_name, p.name AS pharmacy_name 
    FROM branches b 
    JOIN pharmacies p ON b.pharmacy_id = p.id 
    WHERE b.id = ? AND b.pharmacy_id = ?
");
if ($info_stmt) {
    $info_stmt->bind_param("ii", $branch_id, $pharmacy_id);
    $info_stmt->execute();
    $info_res = $info_stmt->get_result();
    if ($i_row = $info_res->fetch_assoc()) {
        $branch_name   = $i_row['branch_name'];
        $pharmacy_name = $i_row['pharmacy_name'];
    }
}

// 3. FETCH PRESCRIPTIONS
$rx_stmt = $conn->prepare("
    SELECT p.*, c.full_name, c.phone 
    FROM prescriptions p 
    INNER JOIN clients c ON p.client_id = c.id 
    WHERE p.pharmacy_id = ? AND p.branch_id = ? 
    ORDER BY p.uploaded_at DESC
");
$rx_stmt->bind_param("ii", $pharmacy_id, $branch_id);
$rx_stmt->execute();
$rx_res = $rx_stmt->get_result();

$total_requests = $rx_res ? $rx_res->num_rows : 0;

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
    
<style>
    :root {
        --primary-green: #198754;
        --soft-bg: #f8f9fa;
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    body { font-family: 'Inter', sans-serif; background-color: var(--soft-bg); color: #333; }
    
    .page-wrapper { 
        padding: 20px; 
        min-height: 100vh; 
        margin-left: 240px; 
        padding-top: 85px; 
        transition: all 0.3s ease;
    }

    #main-wrapper[data-sidebartype="mini-sidebar"] .page-wrapper { margin-left: 70px; }

    @media (max-width: 768px) {
        .page-wrapper { 
            margin-left: 0 !important; 
            padding: 12px; 
            padding-top: 75px; 
        }
        .glass-header {
            padding: 15px !important;
        }
        .header-controls {
            width: 100%;
            flex-direction: column;
            gap: 10px !important;
        }
        .search-bar {
            max-width: 100% !important;
        }
        .active-badge-wrapper {
            width: 100%;
            justify-content: center;
        }
    }

    /* Header UI */
    .glass-header { 
        background: #fff; 
        padding: 20px; 
        border-radius: 16px; 
        box-shadow: var(--card-shadow); 
        margin-bottom: 25px; 
        border-bottom: 4px solid var(--primary-green);
    }

    /* Card UI */
    .rx-card { 
        background: #fff; 
        border-radius: 16px; 
        overflow: hidden; 
        border: none; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        position: relative;
    }

    .rx-card:hover { 
        transform: translateY(-5px); 
        box-shadow: var(--card-shadow); 
    }

    .img-container { 
        width: 100%; 
        height: 200px; 
        position: relative;
        background: #f1f1f1;
        overflow: hidden;
    }

    .img-container img { 
        width: 100%; 
        height: 100%; 
        object-fit: cover; 
        transition: 0.5s;
    }

    .rx-card:hover .img-container img { transform: scale(1.05); }

    .status-overlay {
        position: absolute;
        top: 12px;
        left: 12px;
        z-index: 2;
    }

    .badge-pill {
        padding: 6px 14px;
        border-radius: 50px;
        font-weight: 700;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .note-box {
        background: #fdfdfd;
        border: 1px dashed #ddd;
        border-radius: 10px;
        padding: 10px;
        font-size: 13px;
        color: #666;
        margin-bottom: 15px;
        min-height: 60px;
        max-height: 80px;
        overflow-y: auto;
    }

    /* Actions */
    .btn-action {
        border-radius: 10px;
        font-weight: 600;
        padding: 10px;
        transition: 0.2s;
    }

    .btn-whatsapp {
        background-color: #25D366;
        color: white;
        border: none;
    }

    .btn-whatsapp:hover { background-color: #128C7E; color: white; }

    .search-bar {
        border-radius: 50px;
        padding: 10px 20px;
        border: 1px solid #ddd;
        width: 100%;
        max-width: 300px;
    }
</style>

<div class="page-wrapper">
    <div class="container-fluid p-0">
        <div class="glass-header d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
            <div>
                <h3 class="fw-bold text-dark m-0">Prescription Inbox</h3>
                <p class="text-muted small m-0">
                    📍 <?php echo e($branch_name); ?> (<?php echo e($pharmacy_name); ?>)
                </p>
            </div>
            <div class="header-controls d-flex align-items-center gap-2">
                <input type="text" id="rxSearch" class="search-bar shadow-sm" placeholder="Search patient name...">
                <div class="active-badge-wrapper bg-success text-white px-3 py-2 rounded-pill d-flex align-items-center">
                    <span class="fw-bold me-1"><?php echo $total_requests; ?></span> Active
                </div>
            </div>
        </div>

        <div class="row g-3" id="rxContainer">
            <?php 
            if ($rx_res && $total_requests > 0): 
                while ($row = $rx_res->fetch_assoc()): 
                    $file = $row['file_path'] ?? '';
                    $name = $row['full_name'] ?? 'Unknown Patient';
                    $phone = $row['phone'] ?? '';
                    $status = $row['status'] ?? 'Pending';
                    $date = isset($row['uploaded_at']) ? date('d M Y, H:i', strtotime($row['uploaded_at'])) : 'N/A';
                    $isPending = (strtolower($status) == 'pending');
                    
                    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                    $msg = "Hello " . $name . ", this is " . $pharmacy_name . " (" . $branch_name . "). Your prescription request is processed and ready.";
                    $wa_link = "https://wa.me/" . $clean_phone . "?text=" . urlencode($msg);
            ?>
                <div class="col-xl-4 col-lg-6 col-md-6 col-12 rx-item">
                    <div class="card rx-card shadow-sm h-100">
                        <div class="img-container">
                            <div class="status-overlay">
                                <span class="badge-pill <?php echo $isPending ? 'bg-warning text-dark' : 'bg-success text-white'; ?>">
                                    <?php echo e($status); ?>
                                </span>
                            </div>
                            <a href="../api/uploads/prescriptions/<?php echo e($file); ?>" target="_blank">
                                <img src="../api/uploads/prescriptions/<?php echo e($file); ?>" onerror="this.src='dist/img/no-image.png';">
                            </a>
                        </div>

                        <div class="card-body d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="fw-bold text-dark mb-0 patient-name"><?php echo e($name); ?></h5>
                                        <small class="text-primary fw-bold"><?php echo e($phone); ?></small>
                                    </div>
                                    <small class="text-muted" style="font-size: 11px;"><?php echo $date; ?></small>
                                </div>
                                
                                <div class="note-box">
                                    <i class="mdi mdi-message-text-outline me-1"></i>
                                    <?php echo !empty($row['notes']) ? e($row['notes']) : 'No instructions from patient.'; ?>
                                </div>
                            </div>

                            <div class="d-grid gap-2 mt-2">
                                <?php if($isPending): ?>
                                    <a href="update_rx_status.php?id=<?php echo $row['id']; ?>&status=Ready" class="btn btn-success btn-action shadow-sm">
                                        <i class="mdi mdi-check-circle me-1"></i> Processed & Ready
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-light btn-action text-success fw-bold" disabled>
                                        <i class="mdi mdi-check-all me-1"></i> Prescription Ready
                                    </button>
                                <?php endif; ?>

                                <?php if(!empty($clean_phone)): ?>
                                    <a href="<?php echo $wa_link; ?>" target="_blank" class="btn btn-whatsapp btn-action shadow-sm">
                                       <i class="mdi mdi-whatsapp me-1"></i> Notify via WhatsApp
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="mdi mdi-pill text-muted" style="font-size: 60px; opacity: 0.2;"></i>
                    <h4 class="text-muted fw-light mt-3">No prescription requests today.</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function(){
        $("#rxSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $(".rx-item").filter(function() {
                $(this).toggle($(this).find(".patient-name").text().toLowerCase().indexOf(value) > -1)
            });
        });
    });
</script>

<?php require_once "../includes/footer.php"; ?>
