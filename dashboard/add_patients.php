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
    if (isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
        exit();
    }
    header("Location: ../login.php?error=session_expired");
    exit();
}

// -----------------------------------------------------------------------------
// INLINE AJAX HANDLERS (ADD, EDIT, DELETE)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'];

    // 1. ADD PATIENT ACTION
    if ($action === 'register_patient') {
        $invoice_number    = trim($_POST['invoice_number'] ?? '');
        $first_name        = trim($_POST['first_name'] ?? '');
        $last_name         = trim($_POST['last_name'] ?? '');
        $contact_number    = trim($_POST['contact_number'] ?? '');
        $patient_condation = trim($_POST['patient_condation'] ?? 'No');

        if (empty($first_name) || empty($last_name) || empty($contact_number)) {
            echo json_encode(['status' => 'error', 'message' => 'Please fill in First Name, Last Name, and Phone Number.']);
            exit();
        }

        if (empty($invoice_number)) {
            $invoice_number = 'PAT-' . mt_rand(100000, 999999);
        }

        $status_value = 'Active'; 

        try {
            $stmt = $conn->prepare("INSERT INTO patients 
                (pharmacy_id, branch_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)");

            $stmt->bind_param("iissssss", 
                $pharmacy_id, 
                $branch_id, 
                $invoice_number, 
                $first_name, 
                $last_name, 
                $contact_number, 
                $status_value,
                $patient_condation
            );

            $stmt->execute();
            $stmt->close();

            echo json_encode([
                'status'  => 'success', 
                'message' => 'Patient successfully registered! Ref: ' . $invoice_number
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
            exit();
        }
    }

    // 2. EDIT PATIENT ACTION
    if ($action === 'update_patient') {
        $patient_id        = (int)($_POST['patient_id'] ?? 0);
        $first_name        = trim($_POST['first_name'] ?? '');
        $last_name         = trim($_POST['last_name'] ?? '');
        $contact_number    = trim($_POST['contact_number'] ?? '');
        $patient_condation = trim($_POST['patient_condation'] ?? 'No');

        if (!$patient_id || empty($first_name) || empty($last_name) || empty($contact_number)) {
            echo json_encode(['status' => 'error', 'message' => 'Please complete all required fields.']);
            exit();
        }

        try {
            $stmt = $conn->prepare("UPDATE patients SET first_name = ?, last_name = ?, contact_number = ?, patient_condation = ? WHERE patient_id = ? AND pharmacy_id = ? AND branch_id = ?");
            $stmt->bind_param("ssssiii", $first_name, $last_name, $contact_number, $patient_condation, $patient_id, $pharmacy_id, $branch_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Patient updated successfully!']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
            exit();
        }
    }

    // 3. DELETE PATIENT ACTION
    if ($action === 'delete_patient') {
        $patient_id = (int)($_POST['patient_id'] ?? 0);

        if (!$patient_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid patient selection.']);
            exit();
        }

        try {
            $stmt = $conn->prepare("DELETE FROM patients WHERE patient_id = ? AND pharmacy_id = ? AND branch_id = ?");
            $stmt->bind_param("iii", $patient_id, $pharmacy_id, $branch_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['status' => 'success', 'message' => 'Patient deleted successfully!']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
            exit();
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid Action']);
    exit();
}

// -----------------------------------------------------------------------------
// PAGE VIEW DATA RETRIEVAL & FILTER PROCESSING
// -----------------------------------------------------------------------------

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

// GET FILTERS FROM REQUEST
$search_query   = trim($_GET['search'] ?? '');
$filter_priority = trim($_GET['priority'] ?? ''); // 'Yes', 'No', or ''
$start_date     = trim($_GET['start_date'] ?? '');
$end_date       = trim($_GET['end_date'] ?? '');
$record_limit   = trim($_GET['limit'] ?? '50'); // Default 50

// Handle shortcut parameter from quick metric cards
if (($_GET['filter'] ?? '') === 'emergency') {
    $filter_priority = 'Yes';
}

// Build SQL Query Dynamically
$where_clauses = ["pharmacy_id = ?", "branch_id = ?"];
$param_types   = "ii";
$param_values  = [$pharmacy_id, $branch_id];

if (!empty($search_query)) {
    $where_clauses[] = "(first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ? OR invoice_number LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $param_types .= "ssss";
    array_push($param_values, $search_param, $search_param, $search_param, $search_param);
}

if ($filter_priority === 'Yes' || $filter_priority === 'No') {
    $where_clauses[] = "patient_condation = ?";
    $param_types .= "s";
    $param_values[] = $filter_priority;
}

if (!empty($start_date)) {
    $where_clauses[] = "DATE(registration_date) >= ?";
    $param_types .= "s";
    $param_values[] = $start_date;
}

if (!empty($end_date)) {
    $where_clauses[] = "DATE(registration_date) <= ?";
    $param_types .= "s";
    $param_values[] = $end_date;
}

$sql = "SELECT * FROM patients WHERE " . implode(" AND ", $where_clauses) . " ORDER BY patient_id DESC";

// Apply Limit if not 'all'
if ($record_limit !== 'all' && is_numeric($record_limit)) {
    $limit_num = (int)$record_limit;
    $sql .= " LIMIT " . $limit_num;
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$param_values);
$stmt->execute();
$result = $stmt->get_result();

$patients = [];
while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}
$stmt->close();

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

.form-card, .table-card, .filter-card {
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

.form-control, .form-select {
    border-color: var(--border-color);
    padding: 0.65rem 0.85rem;
    font-size: 0.95rem;
    border-radius: 8px;
    transition: all 0.2s;
}

.form-control:focus, .form-select:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.1);
}

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

/* Print Only Header Styling */
.print-only-header {
    display: none;
}

/* PRINT STYLESHEET */
@media print {
    body {
        background-color: #ffffff !important;
        font-size: 11pt;
    }
    #main-wrapper > header,
    #main-wrapper > aside,
    .left-sidebar,
    .topbar,
    .stat-card,
    .form-card,
    .filter-card,
    .btn,
    .actions-column,
    .no-print {
        display: none !important;
    }
    .page-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }
    .print-only-header {
        display: block !important;
        border-bottom: 2px solid #000;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }
    .table-card {
        border: none !important;
        box-shadow: none !important;
    }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        border: 1px solid #ccc !important;
        padding: 8px !important;
    }
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper patient-wrapper">
        <div class="container-fluid max-width-lg p-0">

            <!-- PRINT HEADER (ONLY VISIBLE ON PRINT OUTPUT) -->
            <div class="print-only-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold mb-0" style="text-transform: uppercase;"><?= htmlspecialchars($display_pharmacy_name) ?></h2>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($display_branch_name) ?></p>
                        <h4 class="mt-2 fw-bold">PATIENT DIRECTORY REPORT</h4>
                    </div>
                    <div class="text-end">
                        <small><strong>Printed Date:</strong> <?= date('d M Y, H:i') ?></small><br>
                        <small><strong>Filtered Records:</strong> <?= count($patients) ?></small><br>
                        <?php if (!empty($start_date) || !empty($end_date)): ?>
                            <small><strong>Date Range:</strong> <?= htmlspecialchars($start_date ?: 'Start') ?> to <?= htmlspecialchars($end_date ?: 'Today') ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Action Navigation -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 no-print">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Patient Management</h3>
                    <p class="text-muted mb-0 small">
                        <i class="fas fa-building text-primary me-1"></i>
                        <strong><?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?></strong> &bull; <?= htmlspecialchars($display_branch_name) ?>
                    </p>
                </div>
                <div>
                    <button onclick="window.print()" class="btn btn-outline-dark fw-semibold rounded-2 px-3 me-2">
                        <i class="fas fa-print me-2"></i> Print Filtered List (<?= count($patients) ?>)
                    </button>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="row g-3 mb-4 no-print">
                <div class="col-12 col-md-6">
                    <a href="add_patients.php" class="text-decoration-none">
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
                    <a href="add_patients.php?priority=Yes" class="text-decoration-none">
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
            <div class="row justify-content-center mb-4 no-print">
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
                                <input type="hidden" name="action" value="register_patient">
                                <input type="hidden" name="invoice_number" value="<?= $patient_no ?>">

                                <div class="row g-4">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" name="first_name" class="form-control" placeholder="Enter here" required>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" name="last_name" class="form-control" placeholder="Enter here" required>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Phone / Contact Number <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-phone-alt"></i></span>
                                            <input type="text" name="contact_number" class="form-control" placeholder="0971234567" required>
                                        </div>
                                    </div>

                                    <div class="col-12 mt-4">
                                        <label class="form-label d-block mb-3">Triage / Care Priority</label>
                                        <div class="row g-3">
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

                                    <div class="col-12 mt-4 pt-2">
                                        <button type="submit" id="submitBtn" class="btn btn-primary-custom w-100 py-3 text-uppercase">
                                            <i class="fas fa-save me-2"></i> Register Patient
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <div id="form-message" class="mt-4"></div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- FILTER CONTROLS CARD -->
            <div class="row justify-content-center mb-4 no-print">
                <div class="col-12 col-xl-10">
                    <div class="filter-card p-3">
                        <form method="GET" action="add_patients.php" class="row g-2 align-items-end">
                            <!-- Search Query -->
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold text-muted">Search Query</label>
                                <input type="text" name="search" class="form-control" placeholder="Name, Phone, or Ref #" value="<?= htmlspecialchars($search_query) ?>">
                            </div>

                            <!-- Care Priority -->
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold text-muted">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="No" <?= $filter_priority === 'No' ? 'selected' : '' ?>>Routine Only</option>
                                    <option value="Yes" <?= $filter_priority === 'Yes' ? 'selected' : '' ?>>Emergency Only</option>
                                </select>
                            </div>

                            <!-- Date Range: From -->
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold text-muted">From Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                            </div>

                            <!-- Date Range: To -->
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold text-muted">To Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                            </div>

                            <!-- Display Limit -->
                            <div class="col-6 col-md-1">
                                <label class="form-label small fw-semibold text-muted">Limit</label>
                                <select name="limit" class="form-select">
                                    <option value="25" <?= $record_limit == '25' ? 'selected' : '' ?>>25</option>
                                    <option value="50" <?= $record_limit == '50' ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= $record_limit == '100' ? 'selected' : '' ?>>100</option>
                                    <option value="250" <?= $record_limit == '250' ? 'selected' : '' ?>>250</option>
                                    <option value="all" <?= $record_limit == 'all' ? 'selected' : '' ?>>All</option>
                                </select>
                            </div>

                            <!-- Filter Submit & Reset -->
                            <div class="col-12 col-md-2 d-flex gap-1">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i> Filter
                                </button>
                                <a href="add_patients.php" class="btn btn-outline-secondary" title="Reset Filters">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Patient Directory Table Card -->
            <div class="row justify-content-center">
                <div class="col-12 col-xl-10">
                    <div class="table-card p-3">
                        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                            <h5 class="fw-bold text-dark mb-0">
                                <i class="fas fa-list text-primary me-2"></i> Patient Records
                                <span class="badge bg-primary bg-opacity-10 text-primary ms-2 fs-7"><?= count($patients) ?> records found</span>
                            </h5>
                        </div>

                        <div id="page-alert" class="no-print"></div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ref #</th>
                                        <th>Full Name</th>
                                        <th>Contact Number</th>
                                        <th>Care Priority</th>
                                        <th>Reg. Date</th>
                                        <th class="text-end actions-column">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($patients)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No patient records found matching your active filters.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($patients as $p): ?>
                                            <tr id="patient-row-<?= $p['patient_id'] ?>">
                                                <td><span class="badge bg-secondary bg-opacity-10 text-dark border"><?= htmlspecialchars($p['invoice_number']) ?></span></td>
                                                <td class="fw-semibold text-dark"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></td>
                                                <td><?= htmlspecialchars($p['contact_number']) ?></td>
                                                <td>
                                                    <?php if ($p['patient_condation'] === 'Yes'): ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-bolt me-1"></i> Emergency</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-notes-medical me-1"></i> Routine</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('d M Y, H:i', strtotime($p['registration_date'])) ?></td>
                                                <td class="text-end actions-column">
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit-patient" 
                                                        data-id="<?= $p['patient_id'] ?>"
                                                        data-invoice="<?= htmlspecialchars($p['invoice_number']) ?>"
                                                        data-first="<?= htmlspecialchars($p['first_name']) ?>"
                                                        data-last="<?= htmlspecialchars($p['last_name']) ?>"
                                                        data-phone="<?= htmlspecialchars($p['contact_number']) ?>"
                                                        data-condition="<?= htmlspecialchars($p['patient_condation']) ?>">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-patient" 
                                                        data-id="<?= $p['patient_id'] ?>" 
                                                        data-name="<?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>">
                                                        <i class="fas fa-trash me-1"></i> Delete
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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

<!-- EDIT PATIENT POPUP MODAL -->
<div class="modal fade" id="editPatientModal" tabindex="-1" aria-labelledby="editPatientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="editPatientModalLabel"><i class="fas fa-user-edit text-primary me-2"></i>Edit Patient Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPatientForm">
                <input type="hidden" name="action" value="update_patient">
                <input type="hidden" name="patient_id" id="edit_patient_id">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-semibold">INVOICE / REF NO.</label>
                        <input type="text" id="edit_invoice_number" class="form-control bg-light" readonly>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone / Contact Number <span class="text-danger">*</span></label>
                        <input type="text" name="contact_number" id="edit_contact_number" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Care Priority / Condition</label>
                        <select name="patient_condation" id="edit_patient_condation" class="form-select">
                            <option value="No">Routine Consultation</option>
                            <option value="Yes">Urgent / Emergency</option>
                        </select>
                    </div>

                    <div id="edit-form-message"></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="saveEditBtn" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CUSTOMIZED DELETE CONFIRMATION MODAL -->
<div class="modal fade" id="deletePatientModal" tabindex="-1" aria-labelledby="deletePatientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="text-danger mb-3">
                    <i class="fas fa-exclamation-triangle fa-3x"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">Delete Patient?</h5>
                <p class="text-muted small mb-3">Are you sure you want to remove <strong id="delete_patient_name" class="text-dark"></strong> from records? This action cannot be undone.</p>
                
                <input type="hidden" id="delete_patient_id">

                <div class="d-flex justify-content-center gap-2 mt-4">
                    <button type="button" class="btn btn-light px-3 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmDeleteBtn" class="btn btn-danger px-3 fw-semibold"><i class="fas fa-trash me-1"></i> Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function(){

    // Register Patient AJAX
    $('#addPatientForm').submit(function(e){
        e.preventDefault();
        let btn = $('#submitBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> SAVING PATIENT...');

        $.ajax({
            url: 'add_patients.php',
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
                    setTimeout(() => { location.reload(); }, 1200);
                } else {
                    $('#form-message').html(`
                        <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center" role="alert">
                            <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                            <div>${res.message}</div>
                        </div>
                    `);
                    btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i> REGISTER PATIENT');
                }
            },
            error: function(){
                $('#form-message').html(`
                    <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
                        <div>Server communication error.</div>
                    </div>
                `);
                btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i> REGISTER PATIENT');
            }
        });
    });

    // Open Edit Modal
    $(document).on('click', '.btn-edit-patient', function(){
        let id = $(this).data('id');
        let invoice = $(this).data('invoice');
        let first = $(this).data('first');
        let last = $(this).data('last');
        let phone = $(this).data('phone');
        let condition = $(this).data('condition');

        $('#edit_patient_id').val(id);
        $('#edit_invoice_number').val(invoice);
        $('#edit_first_name').val(first);
        $('#edit_last_name').val(last);
        $('#edit_contact_number').val(phone);
        $('#edit_patient_condation').val(condition);

        $('#edit-form-message').html('');
        $('#editPatientModal').modal('show');
    });

    // Save Edited Patient AJAX
    $('#editPatientForm').submit(function(e){
        e.preventDefault();
        let btn = $('#saveEditBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

        $.ajax({
            url: 'add_patients.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res){
                if(res.status === 'success'){
                    $('#edit-form-message').html(`
                        <div class="alert alert-success border-0 py-2 mt-2" role="alert">
                            <i class="fas fa-check-circle me-1"></i> ${res.message}
                        </div>
                    `);
                    setTimeout(() => { location.reload(); }, 1000);
                } else {
                    $('#edit-form-message').html(`
                        <div class="alert alert-danger border-0 py-2 mt-2" role="alert">
                            <i class="fas fa-exclamation-triangle me-1"></i> ${res.message}
                        </div>
                    `);
                    btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> Save Changes');
                }
            },
            error: function(){
                $('#edit-form-message').html(`
                    <div class="alert alert-danger border-0 py-2 mt-2" role="alert">
                        <i class="fas fa-exclamation-triangle me-1"></i> Server error.
                    </div>
                `);
                btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> Save Changes');
            }
        });
    });

    // Trigger Custom Delete Modal
    $(document).on('click', '.btn-delete-patient', function(){
        let id = $(this).data('id');
        let name = $(this).data('name');

        $('#delete_patient_id').val(id);
        $('#delete_patient_name').text(name);
        $('#deletePatientModal').modal('show');
    });

    // Confirm Delete Action AJAX
    $('#confirmDeleteBtn').click(function(){
        let patientId = $('#delete_patient_id').val();
        let btn = $(this);

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Deleting...');

        $.ajax({
            url: 'add_patients.php',
            type: 'POST',
            data: { 
                action: 'delete_patient', 
                patient_id: patientId 
            },
            dataType: 'json',
            success: function(res){
                $('#deletePatientModal').modal('hide');
                btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i> Delete');

                if(res.status === 'success'){
                    $(`#patient-row-${patientId}`).fadeOut(300, function(){ $(this).remove(); });
                    $('#page-alert').html(`
                        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                            <i class="fas fa-check-circle me-2"></i> ${res.message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `);
                } else {
                    alert(res.message);
                }
            },
            error: function(){
                $('#deletePatientModal').modal('hide');
                btn.prop('disabled', false).html('<i class="fas fa-trash me-1"></i> Delete');
                alert('Error processing patient deletion.');
            }
        });
    });

});
</script>
</body>
</html>
