<?php
// create_admin_account.php
require_once 'includes/conn.php';

if (!isset($conn) || !$conn) {
    die("Database connection not available. Check includes/conn.php");
}

$admin_username = "Jacob Staff";
$admin_password_plain = "Jayson22";
$role = "admin"; // Changed to 'admin' to match the SQL check below

try {
    // Check if a user with this username exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $admin_username);
    $stmt->execute();
    $stmt->store_result();

    $hashed_password = password_hash($admin_password_plain, PASSWORD_DEFAULT);

    if ($stmt->num_rows > 0) {
        // User exists — update password
        $stmt->close();
        $update = $conn->prepare("UPDATE users SET password = ?, role = ? WHERE username = ?");
        $update->bind_param("sss", $hashed_password, $role, $admin_username);
        if ($update->execute()) {
            echo "Account updated successfully.<br>";
            echo "Username: <strong>" . htmlspecialchars($admin_username) . "</strong><br>";
        }
    } else {
        // User does not exist — insert new
        $stmt->close();
        $insert = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $insert->bind_param("sss", $admin_username, $hashed_password, $role);
        if ($insert->execute()) {
            echo "Account created successfully.<br>";
            echo "Username: <strong>" . htmlspecialchars($admin_username) . "</strong><br>";
        }
    }
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}

$conn->close();
echo "<hr><strong>Security note:</strong> DELETE this file from your folder now.";
?>