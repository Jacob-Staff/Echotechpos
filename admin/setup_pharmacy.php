<?php
session_start();
require_once '../includes/conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect Pharmacy Info
    $pharmacy_name = trim($_POST['pharmacy_name']);
    $hq_address = trim($_POST['address']);
    
    // Collect Admin User Info
    $admin_user = trim($_POST['username']);
    $admin_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $admin_email = trim($_POST['email']);

    // Start Transaction (Ensures if one part fails, nothing is saved)
    $conn->begin_transaction();

    try {
        // STEP 1: Insert the Main Pharmacy (The Tenant)
        $stmt1 = $conn->prepare("INSERT INTO pharmacies (name, address) VALUES (?, ?)");
        $stmt1->bind_param("ss", $pharmacy_name, $hq_address);
        $stmt1->execute();
        $pharmacy_id = $conn->insert_id;

        // STEP 2: Create the Default/First Branch for this Pharmacy
        $branch_name = $pharmacy_name . " - Main Branch";
        $stmt2 = $conn->prepare("INSERT INTO branches (pharmacy_id, branch_name, location) VALUES (?, ?, ?)");
        $stmt2->bind_param("iss", $pharmacy_id, $branch_name, $hq_address);
        $stmt2->execute();
        $branch_id = $conn->insert_id;

        // STEP 3: Create the Admin User and link them to this Branch
        $role = 'Admin';
        $status = 'Active';
        $stmt3 = $conn->prepare("INSERT INTO users (username, password, email, role, branch_id, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt3->bind_param("ssssis", $admin_user, $admin_pass, $admin_email, $role, $branch_id, $status);
        $stmt3->execute();

        // If everything is okay, save to database
        $conn->commit();
        header("Location: ../login_inc.php?status=setup_complete");
        exit();

    } catch (Exception $e) {
        // If anything fails, undo everything
        $conn->rollback();
        echo "Error setting up pharmacy: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Setup New Pharmacy Tenant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f111a; color: white; padding-top: 50px; }
        .setup-card { background: #161b22; border: 1px solid #30363d; padding: 30px; border-radius: 15px; }
        .form-control { background: #0d1117; color: white; border: 1px solid #30363d; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="setup-card shadow">
                <h2 class="text-center mb-4">Register New Pharmacy</h2>
                <form method="POST">
                    <h5 class="text-info border-bottom pb-2">Business Details</h5>
                    <div class="mb-3">
                        <label>Pharmacy Name</label>
                        <input type="text" name="pharmacy_name" class="form-control" placeholder="e.g. Jacobs Pharmacy" required>
                    </div>
                    <div class="mb-3">
                        <label>Headquarters Address</label>
                        <input type="text" name="address" class="form-control" placeholder="Lusaka, Zambia" required>
                    </div>

                    <h5 class="text-info border-bottom pb-2 mt-4">Admin Account</h5>
                    <div class="mb-3">
                        <label>Admin Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Admin Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Admin Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 mt-3">Finish Setup & Create Admin</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>