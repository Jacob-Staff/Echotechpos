<?php
// create_admin_account.php
// Creates or updates an admin account. Run once and then delete this file.

// Database connection
require_once 'includes/conn.php';

if (!isset($conn) || !$conn) {
    die("Database connection not available. Check includes/conn.php");
}

// Admin credentials (as requested)
$admin_username = "Jacob Daka";
$admin_password_plain = "Jayson1234";
$role = "admin";

try {
    // Check if an admin with this username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = 'admin' LIMIT 1");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $admin_username);
    $stmt->execute();
    $stmt->store_result();

    $hashed_password = password_hash($admin_password_plain, PASSWORD_DEFAULT);

    if ($stmt->num_rows > 0) {
        // Admin exists — update password
        $stmt->close();
        $update = $conn->prepare("UPDATE users SET password = ? WHERE username = ? AND role = 'admin'");
        if (!$update) {
            throw new Exception("Prepare failed (update): " . $conn->error);
        }
        $update->bind_param("ss", $hashed_password, $admin_username);
        if ($update->execute()) {
            echo "Admin account already existed — password updated successfully.<br>";
            echo "Username: <strong>" . htmlspecialchars($admin_username) . "</strong><br>";
            echo "Password: <strong>" . htmlspecialchars($admin_password_plain) . "</strong><br>";
        } else {
            throw new Exception("Failed to update admin password: " . $update->error);
        }
        $update->close();
    } else {
        // Admin does not exist — insert new admin
        $stmt->close();
        $insert = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        if (!$insert) {
            throw new Exception("Prepare failed (insert): " . $conn->error);
        }
        $insert->bind_param("sss", $admin_username, $hashed_password, $role);
        if ($insert->execute()) {
            echo "Admin account created successfully.<br>";
            echo "Username: <strong>" . htmlspecialchars($admin_username) . "</strong><br>";
            echo "Password: <strong>" . htmlspecialchars($admin_password_plain) . "</strong><br>";
        } else {
            throw new Exception("Failed to create admin: " . $insert->error);
        }
        $insert->close();
    }
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}

$conn->close();

echo "<hr><strong>Security note:</strong> Delete this file from the server now that you've created/updated the admin account.";
