<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

// Multi-tenant Security
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Fetch Dynamic Pharmacy & Branch Information
$display_pharmacy_name = "Pharmacy";
$display_branch_name   = "Main Branch";

$pharm_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$pharm_query->bind_param("i", $pharmacy_id);
$pharm_query->execute();
$pharm_res = $pharm_query->get_result();
if ($row = $pharm_res->fetch_assoc()) {
    $display_pharmacy_name = $row['name'];
}
$pharm_query->close();

$branch_query = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
$branch_query->bind_param("ii", $branch_id, $pharmacy_id);
$branch_query->execute();
$branch_res = $branch_query->get_result();
if ($row = $branch_res->fetch_assoc()) {
    $display_branch_name = $row['branch_name'];
}
$branch_query->close();

// Auto-generate Patient Reference Number
$patient_no = 'PAT-' . mt_rand(100000, 999999);

// Fetch Dashboard Metrics
$totalPatients = 0;
$emergencyPatients = 0;

$stmt1 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE pharmacy_id = ? AND branch_id = ?");
$stmt1->bind_param("ii", $pharmacy_id, $branch_id);
$stmt1->execute();
$totalPatients = $stmt1->get_result()->fetch_assoc()['total'] ?? 0;
$stmt1->close();

$stmt2 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE patient_condation = 'Yes' AND pharmacy_id = ? AND branch_id = ?");
$stmt2->bind_param("ii", $pharmacy_id, $branch_id);
$stmt2->execute();
$emergencyPatients = $stmt2->get_result()->fetch_assoc()['total'] ?? 0;
$stmt2->close();

require_once "../includes/head.php";
?>

<style>
:root {
    --primary-color: #0284c7;
    --primary-hover: #0369a1;
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
}

body {
    background-color: var(--bg-page) !important;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.patient-wrapper {
    padding: 2rem 1.5rem;
    min-height: calc(100vh - 70px);
}

.stat-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    transition: all 0.25s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.form-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.03);
    overflow: hidden;
}

.form-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    padding: 1.25rem 2rem;
}

.form-body {
    padding: 2rem;
}

.form-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.input-group-text {
    background-color: #f8fafc;
    border-color: var(--border-color);
    color: #94a3b8;
}

.form-control {
    border-color: var(--border-color);
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    border-radius: 8px;
    transition: all 0.2s;
}

.form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.1);
}

/* Priority Selector Tiles */
.priority-option {
    border: 2px solid var(--border-color);
    border-radius: 12px;
    padding: 0;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #ffffff;
    display: block;
}

.priority-option input[type="radio"] {
    display: none;
}

.priority-option input[type="radio"]:checked + .priority-card-routine {
    border-color: #10b981 !important;
    background-color: #f0fdf4 !important;
}

.priority-option input[type="radio"]:checked + .priority-card-emergency {
    border-color: #ef4444 !important;
    background-color: #fef2f2 !important;
}

.priority-option input[type="radio"]:checked + div .check-icon {
    opacity: 1;
    transform: scale(1);
}

.check-icon {
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s ease;
}

.btn-primary-custom {
    background: var(--primary-color);
    border: none;
    color: white;
    font-weight: 600;
    padding: 0.85rem 1.75rem;
    border-radius: 8px;
    transition: all 0.2s;
}

.btn-primary-custom:hover {
    background: var(--primary-hover);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper patient-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <!-- Top Action Navigation -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">
                        Patient Registration
                    </h3>
                    <p class="text-muted mb-0 small">
                        <i class="fas fa-building text-primary me-1"></i>
                        <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong> &bull; <?= htmlspecialchars($display_branch_name) ?>
                    </p>
                </div>
                <div>
                    <a href="patients.php" class="btn btn-outline-secondary fw-semibold rounded-2 px-3">
                        <i class="fas fa-arrow-left me-2"></i> Patient Directory
                    </a>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <a href="patients.php" class="text-decoration-none">
                        <div class="stat-card d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase">Total Patients</span>
                                <h3 class="fw-bold text-dark mb-0 mt-1"><?= number_format($totalPatients) ?></h3>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="patients.php?filter=emergency" class="text-decoration-none">
                        <div class="stat-card d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small fw-semibold text-uppercase">Emergency Cases</span>
                                <h3 class="fw-bold text-danger mb-0 mt-1"><?= number_format($emergencyPatients) ?></h3>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="fas fa-ambulance"></i>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Main Form Card -->
            <div class="row justify-content-center">
                <div class="col-12 col-xl-10">
                    <div class="form-card">
                        
                        <!-- Card Header -->
                        <div class="form-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                    <i class="fas fa-user-plus fa-sm"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">New Patient Entry</h5>
                            </div>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary fw-bold px-3 py-2 rounded-pill border">
                                Ref: <?= $patient_no ?>
                            </span>
                        </div>

                        <!-- Card Form Body -->
                        <div class="form-body">
                            <form id="addPatientForm" autocomplete="off">
                                <input type="hidden" name="invoice_number" value="<?= $patient_no ?>">

                                <div class="row g-4">
                                    <!-- First Name -->
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" name="first_name" class="form-control" placeholder="John" required>
                                        </div>
                                    </div>

                                    <!-- Last Name -->
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" name="last_name" class="form-control" placeholder="Doe" required>
                                        </div>
                                    </div>

                                    <!-- Contact Number -->
                                    <div class="col-12">
                                        <label class="form-label">Phone / Contact Number <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-phone-alt"></i></span>
                                            <input type="text" name="contact_number" class="form-control" placeholder="0971234567" required>
                                        </div>
                                        <small class="text-muted fs-7">Used for patient lookup, notifications, and prescription tracking.</small>
                                    </div>

                                    <!-- Triage Priority Selection -->
                                    <div class="col-12 mt-4">
                                        <label class="form-label d-block mb-3">Triage / Care Priority</label>
                                        <div class="row g-3">
                                            <!-- Routine Option -->
                                            <div class="col-12 col-md-6">
                                                <label class="priority-option w-100">
                                                    <input type="radio" name="patient_condation" value="No" checked>
                                                    <div class="priority-card-routine p-3 rounded-3 border h-100 d-flex align-items-center justify-content-between">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <div class="bg-success bg-opacity-10 text-success p-3 rounded-circle">
                                                                <i class="fas fa-notes-medical fa-lg"></i>
                                                            </div>
                                                            <div>
                                                                <h6 class="fw-bold mb-0 text-dark">Routine Consultation</h6>
                                                                <small class="text-muted">Standard walk-in or appointment</small>
                                                            </div>
                                                        </div>
                                                        <div class="check-icon text-success">
                                                            <i class="fas fa-check-circle fa-lg"></i>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>

                                            <!-- Emergency Option -->
                                            <div class="col-12 col-md-6">
                                                <label class="priority-option w-100">
                                                    <input type="radio" name="patient_condation" value="Yes">
                                                    <div class="priority-card-emergency p-3 rounded-3 border h-100 d-flex align-items-center justify-content-between">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <div class="bg-danger bg-opacity-10 text-danger p-3 rounded-circle">
                                                                <i class="fas fa-bolt fa-lg"></i>
                                                            </div>
                                                            <div>
                                                                <h6 class="fw-bold mb-0 text-dark">Urgent / Emergency</h6>
                                                                <small class="text-muted">High priority or urgent care required</small>
                                                            </div>
                                                        </div>
                                                        <div class="check-icon text-danger">
                                                            <i class="fas fa-check-circle fa-lg"></i>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Controls -->
                                    <div class="col-12 mt-4 pt-2">
                                        <button type="submit" id="submitBtn" class="btn btn-primary-custom w-100 py-3 text-uppercase">
                                            <i class="fas fa-save me-2"></i> Register Patient
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <!-- Feedback Message Handler -->
                            <div id="form-message" class="mt-4"></div>
                        </div>

                    </div>
                </div>
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
    $('#addPatientForm').submit(function(e){
        e.preventDefault();
        let btn = $('#submitBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Saving Record...');

        // Use standard relative request with fallback resolution
        let actionUrl = 'actions/register_patient.php';

        $.ajax({
            url: actionUrl,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res){
                if(res.status === 'success'){
                    $('#form-message').html(`
                        <div class="alert alert-success border-0 shadow-sm d-flex align-items-center" role="alert">
                            <i class="fas fa-check-circle fa-lg me-3"></i>
                            <div>${res.message}</div>
                        </div>
                    `);
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    $('#form-message').html(`
                        <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center" role="alert">
                            <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                            <div>${res.message}</div>
                        </div>
                    `);
                    btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i> Register Patient');
                }
            },
            error: function(xhr, status, error){
                console.error("HTTP Status:", xhr.status);
                console.error("Response Text:", xhr.responseText);
                
                let errDetail = "Server communication error (" + xhr.status + ").";
                if(xhr.responseText) {
                    errDetail += " Response: " + xhr.responseText.substring(0, 100);
                }

                $('#form-message').html(`
                    <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                        <div>${errDetail}</div>
                    </div>
                `);
                btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i> Register Patient');
            }
        });
    });
});
</script>
</body>
</html>
