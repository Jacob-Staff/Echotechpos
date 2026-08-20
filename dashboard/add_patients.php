<?php  
ini_set('display_errors', 0);
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

// Dynamic Pharmacy & Branch Details
$display_pharmacy_name = "Echo Prime Ltd";[cite: 9]
$display_branch_name   = "Main Branch";[cite: 9]

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
$patient_no = 'PAT-' . mt_rand(100000, 999999);[cite: 10]

// Fetch Dashboard Metrics
$totalPatients = 0;[cite: 10]
$emergencyPatients = 0;[cite: 10]

$stmt1 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE pharmacy_id = ? AND branch_id = ?");[cite: 10]
$stmt1->bind_param("ii", $pharmacy_id, $branch_id);[cite: 10]
$stmt1->execute();
$totalPatients = $stmt1->get_result()->fetch_assoc()['total'] ?? 0;[cite: 10]
$stmt1->close();

$stmt2 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE patient_condation = 'Yes' AND pharmacy_id = ? AND branch_id = ?");[cite: 10]
$stmt2->bind_param("ii", $pharmacy_id, $branch_id);[cite: 10]
$stmt2->execute();
$emergencyPatients = $stmt2->get_result()->fetch_assoc()['total'] ?? 0;[cite: 10]
$stmt2->close();

require_once "../includes/head.php";
?>

<style>
.patient-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1.25rem;
    color: #212529;
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
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.form-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: #475569;
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
                    <span class="text-secondary small">
                        <b><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></b> | <?= htmlspecialchars($display_branch_name) ?>[cite: 9]
                    </span>
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
                                <i class="fas fa-id-card text-primary me-2"></i>New Patient Entry Form
                            </h5>
                            <span class="badge bg-light text-dark border fw-semibold">
                                Ref: <?= $patient_no ?>[cite: 10]
                            </span>
                        </div>

                        <form id="addPatientForm">
                            <input type="hidden" name="invoice_number" value="<?= $patient_no ?>">[cite: 10]

                            <div class="row g-3">
                                <!-- Personal Info -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control" placeholder="Enter first name" required>[cite: 10]
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" class="form-control" placeholder="Enter last name" required>[cite: 10]
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                    <input type="text" name="contact_number" class="form-control" placeholder="e.g. 0971234567" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Age / Date of Birth</label>
                                    <input type="text" name="age" class="form-control" placeholder="e.g. 32 Yrs or DD/MM/YYYY">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Residential Address</label>
                                    <input type="text" name="address" class="form-control" placeholder="Enter residential area or physical address">
                                </div>

                                <!-- Condition & Notes -->
                                <div class="col-12">
                                    <label class="form-label">Patient Condition / Symptoms Summary</label>
                                    <textarea name="patient_condation_notes" class="form-control" rows="2" placeholder="Brief notes on medical presentation or request..."></textarea>
                                </div>

                                <div class="col-12">
                                    <label class="form-label d-block">Emergency Priority Tag?</label>[cite: 10]
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="patient_condation" value="Yes" id="yesEm">[cite: 10]
                                        <label class="form-check-label emergency-check-label" for="yesEm">YES - Emergency / Urgent</label>[cite: 10]
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="patient_condation" value="No" id="noEm" checked>[cite: 10]
                                        <label class="form-check-label text-secondary" for="noEm">NO - Routine / Walk-in</label>
                                    </div>
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" id="submitBtn" class="btn btn-dark w-100 py-3 fw-bold">
                                        <i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION[cite: 10]
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div id="form-message" class="mt-3"></div>[cite: 10]
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
                    $('#form-message').html('<div class="alert alert-success alert-dismissible fade show role="alert"><i class="fas fa-check-circle me-2"></i>' + res.message + '</div>');
                    setTimeout(() => { location.reload(); }, 1500);[cite: 10]
                } else {
                    $('#form-message').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i>' + res.message + '</div>');
                    btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');[cite: 10]
                }
            },
            error: function(xhr){
                console.error(xhr.responseText);
                $('#form-message').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> Server connection failed.</div>');
                btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');[cite: 10]
            }
        });
    });
});
</script>
</body>
</html>
