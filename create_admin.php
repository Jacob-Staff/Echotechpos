<?php
// Start session
session_start();

// Include database connection
require_once 'conn.php';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
    $adminPassword = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($adminUsername === '' || $adminPassword === '') {
        die("Please provide both username and password.");
    }

    // Sanitize
    $adminUsername = mysqli_real_escape_string($conn, $adminUsername);

    // Hash password
    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

    // Role is admin
    $role = 'admin';

    // Insert admin into users table
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $adminUsername, $hashedPassword, $role);

    if ($stmt->execute()) {
        echo "Admin account created successfully!";
    } else {
        echo "Error creating admin: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
} else {
    ?>
    <form method="post">
        <label>Username:</label><br>
        <input type="text" name="username" required><br><br>
        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>
        <button type="submit">Create Admin</button>
    </form>
    <?php
}
?>
