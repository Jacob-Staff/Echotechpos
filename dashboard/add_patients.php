<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

// Multi-tenant Security
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php");
    exit;
}

// Auto-generate Patient/Invoice number
$patient_no = 'PAT-' . mt_rand(100000, 999999);

// --- FETCH STATS ---
$totalPatients = 0;
$emergencyPatients = 0;

// Total Patients
$stmt1 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE pharmacy_id = ? AND branch_id = ?");
$stmt1->bind_param("ii", $pharmacy_id, $branch_id);
$stmt1->execute();
$totalPatients = $stmt1->get_result()->fetch_assoc()['total'] ?? 0;

// Emergency Patients
$stmt2 = $conn->prepare("SELECT COUNT(*) AS total FROM patients WHERE patient_condation='Yes' AND pharmacy_id = ? AND branch_id = ?");
$stmt2->bind_param("ii", $pharmacy_id, $branch_id);
$stmt2->execute();
$emergencyPatients = $stmt2->get_result()->fetch_assoc()['total'] ?? 0;
?>

<style>
    /* Page Layout */
    #patient-reg-content { background-color: #f8f9fa; min-height: 100vh; padding: 20px; color: #333; }
    .registration-card { background: #fff; border: 1px solid #e0e6ed; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 30px; margin-bottom: 20px; }
    .stat-box { border-radius: 8px; padding: 25px; color: white; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .bg-patients { background: linear-gradient(135deg, #007bff 0%, #6610f2 100%); }
    .bg-emergency { background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%); }
    .stat-box h2 { font-weight: 800; margin: 0; }
    .stat-box p { font-size: 0.85rem; text-transform: uppercase; margin-bottom: 5px; opacity: 0.9; }
    .form-label { font-weight: 600; font-size: 0.9rem; color: #495057; }
    .form-control { border: 1px solid #ced4da; border-radius: 6px; padding: 10px; }
    .form-control:focus { border-color: #007bff; box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25); }
    .btn-save-patient { background-color: #343a40; color: #fff; font-weight: 600; border: none; padding: 12px; transition: 0.3s; }
    .btn-save-patient:hover { background-color: #212529; color: #fff; }
    .emergency-check-label { color: #dc3545; font-weight: bold; }
</style>

<div id="patient-reg-content">
    <div class="container-fluid">

        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h4 class="fw-bold text-dark mb-0">Patient Registration</h4>
                <small class="text-muted">Branch: <?= $_SESSION['branch_name'] ?? 'Main'; ?></small>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="badge bg-white text-dark border p-2">
                    <i class="fas fa-user-plus me-1 text-primary"></i> New Entry
                </div>
            </div>
        </div>

        <!-- Stats -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <a href="patients.php" class="text-decoration-none">
            <div class="stat-box bg-patients shadow-sm">
                <div>
                    <p>Total Registered Patients</p>
                    <h2><?= number_format($totalPatients) ?></h2>
                </div>
                <i class="fas fa-users fa-3x opacity-25"></i>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="patients.php?filter=emergency" class="text-decoration-none">
            <div class="stat-box bg-emergency shadow-sm">
                <div>
                    <p>Emergency Cases</p>
                    <h2><?= number_format($emergencyPatients) ?></h2>
                </div>
                <i class="fas fa-ambulance fa-3x opacity-25"></i>
            </div>
        </a>
    </div>
</div>
        <!-- Registration Form -->
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="registration-card">
                    <h5 class="fw-bold mb-4 text-dark border-bottom pb-3">
                        Entry Form <span class="float-end text-muted small">Ref: <?= $patient_no ?></span>
                    </h5>

                    <form id="addPatientForm">
                        <input type="hidden" name="invoice_number" value="<?= $patient_no ?>">
                        <input type="hidden" name="pharmacy_id" value="<?= $pharmacy_id ?>">
                        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" placeholder="Enter first name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" placeholder="Enter last name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="contact_number" class="form-control" placeholder="097..." required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Patient Condition / Summary</label>
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
                                    <label class="form-check-label" for="noEm">NO - Routine</label>
                                </div>
                            </div>

                            <div class="col-12 mt-4">
                                <button type="submit" id="submitBtn" class="btn btn-save-patient w-100 py-3">
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

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    $('#addPatientForm').submit(function(e){
        e.preventDefault();
        let btn = $('#submitBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> SAVING RECORD...');

        $.ajax({
            url: 'actions/register_patient.php', // This script must handle POST & insert into `patients`
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res){
                if(res.status === 'success'){
                    $('#form-message').html('<div class="alert alert-success">Patient registered successfully!</div>');
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    $('#form-message').html('<div class="alert alert-danger">' + res.message + '</div>');
                    btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');
                }
            },
            error: function(){
                $('#form-message').html('<div class="alert alert-danger">Error saving data.</div>');
                btn.prop('disabled', false).html('<i class="fas fa-check-circle me-2"></i> COMPLETE REGISTRATION');
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>