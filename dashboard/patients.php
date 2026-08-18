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

// Filter patients by query param
$filter = $_GET['filter'] ?? 'all';
$whereClause = "pharmacy_id = ? AND branch_id = ?";
$params = [$pharmacy_id, $branch_id];

if($filter === 'emergency') {
    $whereClause .= " AND patient_condation = 'Yes'";
}

// Fetch patients
$stmt = $conn->prepare("SELECT patient_id, invoice_number, first_name, last_name, contact_number, registration_date, patient_condation 
                        FROM patients WHERE $whereClause ORDER BY registration_date DESC");
$stmt->bind_param("ii", ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<style>
    #patients-page {
        background-color: #f4f6f9;
        min-height: 100vh;
        padding: 20px;
        font-family: 'Segoe UI', sans-serif;
    }

    .patients-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        padding: 25px;
        margin-bottom: 20px;
    }

    .patients-card h4 {
        font-weight: 700;
        margin-bottom: 15px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        padding: 12px 15px;
        border-bottom: 1px solid #dee2e6;
        text-align: left;
        font-size: 0.95rem;
    }

    th {
        background-color: #007bff;
        color: white;
        text-transform: uppercase;
        font-weight: 600;
    }

    tr:hover {
        background-color: #f1f3f5;
        cursor: pointer;
    }

    .badge-emergency {
        background-color: #dc3545;
        color: #fff;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
    }

    .badge-routine {
        background-color: #28a745;
        color: #fff;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
    }

    .filter-buttons {
        margin-bottom: 20px;
    }

    .filter-buttons .btn {
        margin-right: 10px;
    }

    @media (max-width: 768px) {
        th, td {
            font-size: 0.85rem;
        }
    }
</style>

<div id="patients-page">
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-md-6">
                <h4 class="fw-bold text-dark mb-0">Patients List</h4>
                <small class="text-muted">Branch: <?= $_SESSION['branch_name'] ?? 'Main'; ?></small>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="filter-buttons">
            <a href="patients.php?filter=all" class="btn btn-primary <?= $filter === 'all' ? 'active' : '' ?>">All Patients</a>
            <a href="patients.php?filter=emergency" class="btn btn-danger <?= $filter === 'emergency' ? 'active' : '' ?>">Emergency Cases</a>
        </div>

        <div class="patients-card">
            <h4><?= $filter === 'emergency' ? 'Emergency Patients' : 'All Patients' ?></h4>

            <table id="patientsTable">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Full Name</th>
                        <th>Contact</th>
                        <th>Registration Date</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr onclick="window.location='patient_details.php?id=<?= $row['patient_id'] ?>'">
                                <td><?= $row['invoice_number'] ?></td>
                                <td><?= $row['first_name'].' '.$row['last_name'] ?></td>
                                <td><?= $row['contact_number'] ?></td>
                                <td><?= date('d-M-Y H:i', strtotime($row['registration_date'])) ?></td>
                                <td>
                                    <?php if($row['patient_condation'] === 'Yes'): ?>
                                        <span class="badge-emergency">Emergency</span>
                                    <?php else: ?>
                                        <span class="badge-routine">Routine</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No patients found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JS for table search and sort -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    // Simple search/filter
    $('<input type="text" id="searchInput" placeholder="Search Patients..." class="form-control mb-3" />')
        .prependTo('#patientsTable').on('keyup', function(){
            var val = $(this).val().toLowerCase();
            $('#patientsTable tbody tr').filter(function(){
                $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1)
            });
        });
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>