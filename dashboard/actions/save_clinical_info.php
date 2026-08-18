<?php
require "../../includes/conn.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = $_POST['product_id'] ?? 0;
    $about = $_POST['about_text'] ?? '';
    $uses = $_POST['uses'] ?? '';
    $directions = $_POST['directions'] ?? '';
    $side = $_POST['side_effects'] ?? '';
    $works = $_POST['how_it_works'] ?? '';
    $storage = $_POST['storage_info'] ?? '';

    if ($pid > 0) {
        // Updated to use product_details_info
        $sql = "INSERT INTO product_details_info 
                (product_id, about_text, uses, directions, side_effects, how_it_works, storage_info) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                about_text = VALUES(about_text), 
                uses = VALUES(uses), 
                directions = VALUES(directions), 
                side_effects = VALUES(side_effects), 
                how_it_works = VALUES(how_it_works), 
                storage_info = VALUES(storage_info)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issssss", $pid, $about, $uses, $directions, $side, $works, $storage);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
    }
}