<?php
session_start();
require_once "../../includes/conn.php"; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
    $branch_id   = $_SESSION['branch_id'] ?? 0;
    $user_id     = $_SESSION['user_id'] ?? 0; 
    
    $name     = mysqli_real_escape_string($conn, $_POST['name']);
    $amount   = floatval($_POST['amount']);
    // This MUST match name="date" in the HTML form
    $ex_date  = mysqli_real_escape_string($conn, $_POST['date']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);

    if (!empty($name) && $amount > 0) {
        $query = "INSERT INTO expenses (pharmacy_id, branch_id, name, amount, expense_date, category, recorded_by) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iisdssi", $pharmacy_id, $branch_id, $name, $amount, $ex_date, $category, $user_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $stmt->error]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data provided']);
    }
    exit;
}