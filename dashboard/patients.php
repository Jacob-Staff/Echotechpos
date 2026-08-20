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

// Fetch Dynamic Pharmacy & Branch Details
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

// Filter logic
$filter = $_GET['filter'] ?? 'all';
$whereClause = "pharmacy_id = ? AND branch_id = ?";
$types = "ii";
$params = [$pharmacy_id, $branch_id];

if ($filter === 'emergency') {
    $whereClause .= " AND patient_condation = 'Yes'";
} elseif ($filter === 'routine') {
    $whereClause .= " AND (patient_condation = 'No' OR patient_condation IS NULL)";
}

// Fetch Patients
$stmt = $conn->prepare("SELECT patient_id, invoice_number, first_name, last_name, contact_number, registration_date, status, patient_condation 
                        FROM patients 
                        WHERE $whereClause 
                        ORDER BY registration_date DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

require_once "../includes/head.php";
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

<style>
.patients-wrapper {
    background-color: #f8f9fa !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #333;
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e0e6ed;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}

.table-patients tbody tr {
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.table-patients tbody tr:hover {
    background-color: #f1f5f9 !important;
}

.badge-emergency {
    background-color: #dc3545;
    color: #ffffff;
    padding: 0.35em 0.65em;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 6px;
}

.badge-routine {
    background-color: #198754;
    color: #ffffff;
    padding: 0.35em 0.65em;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 6px;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper patients-wrapper">
        <div class="container-fluid p-0">

            <!-- Header Title -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">
                        <i class="fas fa-users text-primary me-2"></i>Patient Directory
                    </h3>
                    <small class="text-secondary fw-semibold">
                        <?= htmlspecialchars(strtoupper($display_pharmacy_name)) ?> | <?= htmlspecialchars($display_branch_name) ?>
                    </small>
                </div>
                <div>
                    <a href="add_patients.php" class="btn btn-primary fw-bold shadow-sm">
                        <i class="fas fa-user-plus me-1"></i> Register New Patient
                    </a>
                </div>
            </div>

            <!-- Filter Buttons -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <a href="patients.php?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?> fw-semibold px-3">
                    <i class="fas fa-list me-1"></i> All Patients
                </a>
                <a href="patients.php?filter=emergency" class="btn btn-sm <?= $filter === 'emergency' ? 'btn-danger' : 'btn-outline-danger' ?> fw-semibold px-3">
                    <i class="fas fa-ambulance me-1"></i> Emergency Cases
                </a>
                <a href="patients.php?filter=routine" class="btn btn-sm <?= $filter === 'routine' ? 'btn-success' : 'btn-outline-success' ?> fw-semibold px-3">
                    <i class="fas fa-notes-medical me-1"></i> Routine Cases
                </a>
            </div>

            <!-- Table Card -->
            <div class="card card-custom p-4">
                <div class="table-responsive">
                    <table id="patientsTable" class="table table-hover align-middle table-patients w-100">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice Ref</th>
                                <th>Full Name</th>
                                <th>Contact Number</th>
                                <th>Registration Date</th>
                                <th>Condition</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr onclick="window.location='patient_details.php?id=<?= $row['patient_id'] ?>'">
                                        <td class="fw-bold text-primary"><?= htmlspecialchars($row['invoice_number']) ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                        <td><?= htmlspecialchars($row['contact_number']) ?></td>
                                        <td><?= date('d M Y, H:i', strtotime($row['registration_date'])) ?></td>
                                        <td>
                                            <?php if ($row['patient_condation'] === 'Yes'): ?>
                                                <span class="badge badge-emergency"><i class="fas fa-bolt me-1"></i>Emergency</span>
                                            <?php else: ?>
                                                <span class="badge badge-routine"><i class="fas fa-check me-1"></i>Routine</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end" onclick="event.stopPropagation();">
                                            <a href="patient_details.php?id=<?= $row['patient_id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                                <i class="fas fa-folder-open me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#patientsTable').DataTable({
        "pageLength": 15,
        "ordering": true,
        "order": [[ 3, "desc" ]],
        "language": {
            "search": "Filter Patients:",
            "zeroRecords": "No matching patient records found",
            "emptyTable": "No patients registered under this filter"
        }
    });
});
</script>
</body>
</html>
