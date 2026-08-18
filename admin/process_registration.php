<?php
require_once 'includes/conn.php';

// Collect data from the signup form
$corp_name = $_POST['pharmacy_name'];
$owner_name = $_POST['owner_username'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
$location = $_POST['location'];

try {
    $conn->begin_transaction();

    // STEP 1: Create the Pharmacy (The Tenant)
    $stmt1 = $conn->prepare("INSERT INTO pharmacies (name, created_at) VALUES (?, NOW())");
    $stmt1->bind_param("s", $corp_name);
    $stmt1->execute();
    $new_pharmacy_id = $conn->insert_id; // THIS IS THE KEY

    // STEP 2: Create the first Branch for THIS new pharmacy
    $stmt2 = $conn->prepare("INSERT INTO branches (pharmacy_id, branch_name, address, is_active) VALUES (?, ?, ?, 1)");
    $main_branch_name = $corp_name . " - Main";
    $stmt2->bind_param("iss", $new_pharmacy_id, $main_branch_name, $location);
    $stmt2->execute();
    $new_branch_id = $conn->insert_id;

    // STEP 3: Create the Admin User for THIS new pharmacy
    $stmt3 = $conn->prepare("INSERT INTO users (username, password, role, pharmacy_id, branch_id) VALUES (?, ?, 'Admin', ?, ?)");
    $stmt3->bind_param("ssii", $owner_name, $password, $new_pharmacy_id, $new_branch_id);
    $stmt3->execute();

    $conn->commit();
    echo "Success! New Pharmacy '{$corp_name}' is now registered.";

} catch (Exception $e) {
    $conn->rollback();
    echo "Registration failed: " . $e->getMessage();
}