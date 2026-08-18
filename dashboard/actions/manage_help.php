<?php
session_start();
require "../../includes/conn.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');

    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        exit;
    }

    if ($action === 'reply_and_resolve') {
        if (empty($reply)) {
            echo json_encode(['status' => 'error', 'message' => 'Reply cannot be empty']);
            exit;
        }

        // Updates the reply and closes the ticket
        $stmt = $conn->prepare("UPDATE help_inquiries SET admin_reply = ?, status = 'Resolved' WHERE id = ?");
        $stmt->bind_param("si", $reply, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit;
    }

    if ($action === 'resolve') {
        $stmt = $conn->prepare("UPDATE help_inquiries SET status = 'Resolved' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }
}
echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
?>