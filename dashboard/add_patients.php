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
.patient-wrapper {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #333;
}

.stat-box {
    border-radius: 10px;
    padding: 1.25rem;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: transform 0.2s ease-in-out;
}

.stat-box:hover {
    transform: translateY(-2px);
}

.bg-patients { 
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); 
}

.bg-emergency { 
    background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%); 
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e0e6ed;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}

.form-label {
    font-weight: 600;
    font-size: 0.88rem;
    color: #495057;
}

.emergency-check-label {
    color: #dc3545;
    font-weight: 700;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper patient-wrapper">
        <div class="container-fluid p-0">

            <!-- Page Title Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">
                        <i class="fas fa-user-plus me-2 text-primary"></i>Patient Registration
                    </h3>
                    <small class="text-secondary fw-semibold">
                        <?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?> | <?= htmlspecialchars($display_branch_name) ?>
                    </small>
                </div>
                <div>
                    <a href="patients.php" class="btn btn-outline-primary fw-bold">
                        <i class="fas fa-list me-1"></i> Patient Directory
                    </a>
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <a href="patients.php" class="text-decoration-none">
                        <div class="stat-box bg-patients shadow-sm">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Total Registered Patients</span>
                                <h2 class="fw-bold mb-0 mt-1"><?= number_format($totalPatients) ?></h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-users fa-2x text-white"></i>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-12 col-md-6">
                    <a href="patients.php?filter=emergency" class="text-decoration-none">
                        <div class="stat-box bg-emergency shadow-sm">
                            <div>
                                <span class="text-white-50 small fw-bold text-uppercase">Emergency Cases</span>
                                <h2 class="fw-bold mb-0 mt-1"><?= number_format($emergencyPatients) ?></h2>
                            </div>
                            <div class="bg-white bg-opacity-25 rounded-circle p-3">
                                <i class="fas fa-ambulance fa-2x text-white"></i>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Main Registration Form -->
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10">
                    <div class="card card-custom p-4">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                            <h5 class="fw-bold text-dark mb-0">
                                Entry Form
                            </h5>
                            <span class="badge bg-light text-dark border fw-semibold">
                                Ref: <?= $patient_no ?>
                            </span>
                        </div>

                        <form id="addPatientForm">
                            <input type="hidden" name="invoice_number" value="<?= $patient_no ?>">
                            <input type="hidden" name="pharmacy_id" value="<?= $pharmacy_id ?>">
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">

                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control" placeholder="Enter first name" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" class="form-control" placeholder="Enter last name" required>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                    <input type="text" name="contact_number" class="form-control" placeholder="097..." required>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label">Patient Condition / Notes</label>
                                    <input type="text" name="patient_condation_notes" class="form-control" placeholder="Short description of health status">
                                </div>

                                <div class="col-12 mt-4">
                                    <label class="form-label d-block">Is this an Emergency?</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="patient_condation" value="Yes" id="yesEm">
                                        <label class="form-check-label emergency-check-label" for="yesEm">YES - Emergency</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="patient_condation" value="No" id="noEm" checked>
                                        <label class="form-check-label text-secondary" for="noEm">NO - Routine</label>
                                    </div>
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" id="submitBtn" class="btn btn-dark w-100 py-3 fw-bold">
                                        <i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div id="form-message" class="mt-3"></div>
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
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> SAVING RECORD...');

        $.ajax({
            url: 'actions/register_patient.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res){
                if(res.status === 'success'){
                    $('#form-message').html('<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i>' + res.message + '</div>');
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    $('#form-message').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i>' + res.message + '</div>');
                    btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');
                }
            },
            error: function(xhr){
                console.error(xhr.responseText);
                $('#form-message').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> Error saving data or network issue.</div>');
                btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');
            }
        });
    });
});
</script>
</body>
</html>
