<?php
session_start();
require "../includes/conn.php";

/** * ECHO PRIME / JAYSON POS - DASHBOARD ANALYTICS */
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

// AJAX can also pass a specific branch_id if an admin is switching views
$active_branch_id = $_GET['branch_id'] ?? $branch_id;

header('Content-Type: application/json');

// 1. ✅ Security & Session Check
if (!$pharmacy_id || !$active_branch_id) {
    echo json_encode(['error' => 'Unauthorized or Session Expired']);
    exit;
}

// 2. ✅ Total Patients Count (Scoped to Pharmacy & Branch)
$total_query = "SELECT COUNT(*) AS total FROM patients WHERE pharmacy_id = ? AND branch_id = ?";
$stmt1 = $conn->prepare($total_query);
$stmt1->bind_param("ii", $pharmacy_id, $active_branch_id);
$stmt1->execute();
$res1 = $stmt1->get_result();
$totalPatients = $res1->fetch_assoc()['total'] ?? 0;
$stmt1->close();

// 3. ✅ Emergency Patients Count
// Note: Fixed the column name 'patient_condation' to match your snippet, 
// but ensure it isn't a typo for 'patient_condition' in your DB.
$emergency_query = "SELECT COUNT(*) AS total FROM patients 
                    WHERE pharmacy_id = ? 
                    AND branch_id = ? 
                    AND patient_condation = 'Yes'";

$stmt2 = $conn->prepare($emergency_query);
$stmt2->bind_param("ii", $pharmacy_id, $active_branch_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
$emergencyPatients = $res2->fetch_assoc()['total'] ?? 0;
$stmt2->close();

// 4. ✅ Return Clean JSON Output
echo json_encode([
    'success' => true,
    'total' => (int)$totalPatients,
    'emergency' => (int)$emergencyPatients,
    'timestamp' => date('Y-m-d H:i:s')
]);

$conn->close();
?>