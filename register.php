<?php
session_start();
require('includes/conn.php');

// Allow only admins to access this page
if (!isset($_SESSION['sessionUsername']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['status'] = 'danger';
    $_SESSION['message'] = "Only Admins can register new users.";
    header("Location: index.php");
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = 'user'; // All new accounts created by admin are users

    if ($username === '' || $password === '') {
        $error = "Please fill in all fields.";
    } else {
        $username = mysqli_real_escape_string($conn, $username);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Check if username exists
        $check_sql = "SELECT id FROM users WHERE username='$username' LIMIT 1";
        $check_res = mysqli_query($conn, $check_sql);
        if ($check_res && mysqli_num_rows($check_res) > 0) {
            $error = "Username already exists.";
        } else {
            $insert_sql = "INSERT INTO users (username, password, role) VALUES ('$username', '$passwordHash', '$role')";
            if (mysqli_query($conn, $insert_sql)) {
                $success = "User account created successfully!";
            } else {
                $error = "Database error: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register User - Admin Only</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">Create New User (Admin Only)</h3>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if(isset($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" required>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>

        <button type="submit" class="btn btn-success">Create User</button>
        <a href="index.php" class="btn btn-secondary">Back to Login</a>
    </form>
</div>
</body>
</html>
