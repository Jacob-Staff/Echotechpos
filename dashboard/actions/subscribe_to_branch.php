<?php
session_start();
require_once(__DIR__ . "/../../includes/conn.php");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {
    $client_id = intval($_POST['client_id']);
    $branch_id = intval($_POST['branch_id']);
    $pharmacy_id = intval($_POST['pharmacy_id']);
    $action = $_POST['action'] ?? 'subscribe';

    if ($action === 'unsubscribe') {
        // DELETE logic
        $del = $conn->prepare("DELETE FROM customers WHERE client_id = ? AND branch_id = ?");
        $del->bind_param("ii", $client_id, $branch_id);
        if ($del->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Unsubscribed successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to unsubscribe']);
        }
        exit;
    }

    // Existing SUBSCRIBE logic...
    $check = $conn->prepare("SELECT id FROM customers WHERE client_id = ? AND branch_id = ?");
    $check->bind_param("ii", $client_id, $branch_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Already subscribed']); // Return success so UI updates
        exit;
    }

    // Fetch and Insert logic
    $user_query = $conn->prepare("SELECT full_name, email, phone FROM clients WHERE id = ?");
    $user_query->bind_param("i", $client_id);
    $user_query->execute();
    $user_data = $user_query->get_result()->fetch_assoc();

    $ins = $conn->prepare("INSERT INTO customers (pharmacy_id, branch_id, client_id, name, phone, email, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $ins->bind_param("iiisss", $pharmacy_id, $branch_id, $client_id, $user_data['full_name'], $user_data['phone'], $user_data['email']);

    if ($ins->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Subscribed successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
}