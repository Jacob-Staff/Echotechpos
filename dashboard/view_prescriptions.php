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

// 2. Fetch Prescriptions
$rx_stmt = $conn->prepare("
    SELECT p.*, c.full_name, c.phone 
    FROM prescriptions p 
    INNER JOIN clients c ON p.client_id = c.id 
    WHERE p.pharmacy_id = ? AND p.branch_id = ? 
    ORDER BY p.uploaded_at DESC
");
$rx_stmt->bind_param("ii", $p_id, $b_id);
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
    margin-bottom: 1.5rem;
}

.rx-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #dee2e6;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.rx-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08) !important;
}

.img-container {
    width: 100%;
    height: 180px;
    background-color: #f1f3f5;
    position: relative;
    overflow: hidden;
}

.img-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.status-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 2;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 5px 12px;
    border-radius: 20px;
}

.note-box {
    background: #f8f9fa;
    border: 1px dashed #ced4da;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
    color: #495057;
    min-height: 55px;
    max-height: 70px;
    overflow-y: auto;
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

.form-control-search {
    border: 1px solid #ced4da;
    border-radius: 50px;
    padding: 8px 18px;
    max-width: 300px;
}

.form-control-search:focus {
    border-color: #198754;
    box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
}
</style>

<div id="main-wrapper">

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper rx-wrapper">
        <div class="container-fluid p-0">
            
            <div class="header-section d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Prescription Inbox</h3>
                    <span class="text-success small">
                        <i class="fas fa-map-marker-alt me-1"></i> Branch: <b><?php echo e($display_bran); ?></b> | <b><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="rxSearch" class="form-control form-control-search" placeholder="Search patient name...">
                    <span class="badge bg-success px-3 py-2 rounded-pill fs-6 fw-normal">
                        <b><?php echo $total_requests; ?></b> Active
                    </span>
                </div>
            </div>

            <div class="row g-3" id="rxContainer">
                <?php if ($rx_res && $total_requests > 0): ?>
                    <?php while ($row = $rx_res->fetch_assoc()): ?>
                        <?php 
                            $file = $row['file_path'] ?? '';
                            $name = $row['full_name'] ?? 'Unknown Patient';
                            $phone = $row['phone'] ?? '';
                            $status = $row['status'] ?? 'Pending';
                            $date = isset($row['uploaded_at']) ? date('d M Y, H:i', strtotime($row['uploaded_at'])) : 'N/A';
                            $isPending = (strtolower($status) === 'pending');
                            
                            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                            $msg = "Hello " . $name . ", this is " . $display_pharm . " (" . $display_bran . "). Your prescription request is processed and ready.";
                            $wa_link = "https://wa.me/" . $clean_phone . "?text=" . urlencode($msg);
                        ?>
                        <div class="col-xl-4 col-lg-6 col-md-6 col-12 rx-item">
                            <div class="card rx-card h-100 shadow-sm">
                                <div class="img-container">
                                    <span class="status-badge <?php echo $isPending ? 'bg-warning text-dark' : 'bg-success text-white'; ?>">
                                        <?php echo e($status); ?>
                                    </span>
                                    <a href="../api/uploads/prescriptions/<?php echo e($file); ?>" target="_blank">
                                        <img src="../api/uploads/prescriptions/<?php echo e($file); ?>" onerror="this.src='dist/img/no-image.png';">
                                    </a>
                                </div>

                                <div class="card-body d-flex flex-column justify-content-between p-3">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h5 class="fw-bold text-dark mb-0 patient-name"><?php echo e($name); ?></h5>
                                                <span class="text-primary small fw-bold"><i class="fas fa-phone-alt me-1"></i><?php echo e($phone); ?></span>
                                            </div>
                                            <span class="text-muted small" style="font-size: 11px;"><?php echo $date; ?></span>
                                        </div>
                                        
                                        <div class="note-box mb-3">
                                            <i class="far fa-comment-dots me-1 text-muted"></i>
                                            <?php echo !empty($row['notes']) ? e($row['notes']) : 'No instructions provided.'; ?>
                                        </div>
                                    </div>

                                    <div class="row g-2">
                                        <div class="col-12 col-sm-6">
                                            <?php if($isPending): ?>
                                                <a href="update_rx_status.php?id=<?php echo $row['id']; ?>&status=Ready" class="btn btn-success btn-sm w-100 py-2 fw-bold">
                                                    <i class="fas fa-check-circle me-1"></i> Mark Ready
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-light btn-sm text-success w-100 py-2 fw-bold" disabled>
                                                    <i class="fas fa-check-double me-1"></i> Ready
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <?php if(!empty($clean_phone)): ?>
                                                <a href="<?php echo $wa_link; ?>" target="_blank" class="btn btn-whatsapp btn-sm w-100 py-2">
                                                    <i class="fab fa-whatsapp me-1"></i> WhatsApp
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-prescription-bottle-alt text-muted display-4 opacity-25"></i>
                        <h5 class="text-muted fw-normal mt-3">No prescription requests available.</h5>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function(){
        $("#rxSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $(".rx-item").filter(function() {
                $(this).toggle($(this).find(".patient-name").text().toLowerCase().indexOf(value) > -1);
            });
        });
    });
</script>
</body>
</html>
